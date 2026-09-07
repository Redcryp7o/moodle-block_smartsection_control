<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Moodle calendar integration for the SmartSection Control block.
 *
 * Manages course calendar events that represent scheduled section unlock dates,
 * keeping them synchronised with the block's configuration records.
 *
 * Events are created with component='block_smartsection_control' and modulename=''
 * which is the correct pattern for non-module Moodle plugins creating calendar entries.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_smartsection_control;

/**
 * Calendar event manager for section unlock dates.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar {
    /**
     * Create or update the calendar event for a section unlock date.
     *
     * Event identity: component + empty modulename + course_sections.id in `instance`
     * (one SmartSection rule per section; section id is stable across rule updates).
     *
     * @param int    $sectionid   The course_sections.id.
     * @param int    $courseid    The course ID.
     * @param int    $unlocktime  Unix timestamp of the scheduled unlock.
     * @param string $sectionname Display name of the section.
     * @return int The calendar event ID.
     */
    public static function create_unlock_event(int $sectionid, int $courseid, int $unlocktime, string $sectionname): int {
        global $DB, $CFG;

        if ($unlocktime <= 0) {
            throw new \invalid_parameter_exception('Unlock time must be a positive Unix timestamp.');
        }

        $sectionid = helper::require_section_id($sectionid, $courseid);
        $courseid = helper::require_course_id($courseid);
        $unlocktime = (int) $unlocktime;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $course         = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        if (empty($sectionname) || trim($sectionname) === '') {
            $sectionname = helper::get_section_display_name($course, $sectionid);
        }
        $sectionnamestr = format_string($sectionname);

        $eventname   = get_string('calendar_event_name', 'block_smartsection_control', $sectionnamestr);
        $description = get_string('calendar_event_description', 'block_smartsection_control', (object) [
            'section' => $sectionnamestr,
            'course'  => format_string($course->fullname),
        ]);

        // Look up an existing event for this section using the block component.
        $events = $DB->get_records('event', [
            'component' => 'block_smartsection_control',
            'modulename' => '',
            'instance'  => $sectionid,
            'courseid'  => $courseid,
        ]);

        if (!empty($events)) {
            // Update the existing event via the calendar API.
            $eventrec = reset($events);
            $event    = new \calendar_event($eventrec);
            $event->update((object) [
                'name'         => $eventname,
                'description'  => $description,
                'eventtype'    => 'sectionrelease',
                'timestart'    => $unlocktime,
                'timemodified' => time(),
            ], false);
            return (int) $event->id;
        }

        // Create a new course calendar event with dedicated sectionrelease eventtype.
        $eventdata = (object) [
            'name'         => $eventname,
            'description'  => $description,
            'format'       => FORMAT_HTML,
            'courseid'     => $courseid,
            'groupid'      => 0,
            'userid'       => 0,
            'component'    => 'block_smartsection_control',
            'modulename'   => '',
            'instance'     => $sectionid,
            'eventtype'    => 'sectionrelease',
            'timestart'    => $unlocktime,
            'timeduration' => 0,
            'visible'      => 1,
            'timemodified' => time(),
        ];

        $event = \calendar_event::create($eventdata, false);
        return (int) $event->id;
    }

    /**
     * Delete all calendar events associated with a section unlock.
     *
     * @param int $sectionid The course_sections.id.
     * @param int $courseid  The course ID.
     * @return bool True (always succeeds even if no events exist).
     */
    public static function delete_unlock_event(int $sectionid, int $courseid): bool {
        global $DB, $CFG;

        $sectionid = helper::require_section_id($sectionid, $courseid);
        $courseid = helper::require_course_id($courseid);

        require_once($CFG->dirroot . '/calendar/lib.php');

        $events = $DB->get_records('event', [
            'component' => 'block_smartsection_control',
            'modulename' => '',
            'instance'  => $sectionid,
            'courseid'  => $courseid,
        ]);

        foreach ($events as $eventrec) {
            $event = new \calendar_event($eventrec);
            $event->delete(false);
        }

        return true;
    }

    /**
     * Return all upcoming absolute-type unlocks for a course within a given number of days.
     *
     * @param int $courseid The course ID.
     * @param int $days     Number of days ahead to look (default 7).
     * @return array Array of block_smartsection_control rows joined with course_sections.
     */
    public static function get_upcoming_unlocks(int $courseid, int $days = 7): array {
        global $DB;

        $now      = time();
        $inndays  = $now + ($days * DAYSECS);

        $sql = "SELECT bsu.*, cs.section, cs.name AS sectionname
                  FROM {block_smartsection_control} bsu
                  JOIN {course_sections} cs ON cs.id = bsu.sectionid
                 WHERE bsu.courseid  = :courseid
                   AND bsu.unlocktime > :now
                   AND bsu.unlocktime <= :inndays
                   AND bsu.unlocktype = 'absolute'
              ORDER BY bsu.unlocktime ASC";

        return $DB->get_records_sql($sql, ['courseid' => $courseid, 'now' => $now, 'inndays' => $inndays]);
    }

    /**
     * Return all past-due absolute-type unlocks for a course.
     *
     * A record is overdue when its unlock time has passed but the section may not yet
     * have been made visible (e.g., cron has not run yet).
     *
     * @param int $courseid The course ID.
     * @return array Array of block_smartsection_control rows joined with course_sections.
     */
    public static function get_overdue_unlocks(int $courseid): array {
        global $DB;

        $now = time();

        $sql = "SELECT bsu.*, cs.section, cs.name AS sectionname
                  FROM {block_smartsection_control} bsu
                  JOIN {course_sections} cs ON cs.id = bsu.sectionid
                 WHERE bsu.courseid  = :courseid
                   AND bsu.unlocktime > 0
                   AND bsu.unlocktime < :now
                   AND bsu.unlocktype = 'absolute'
              ORDER BY bsu.unlocktime ASC";

        return $DB->get_records_sql($sql, ['courseid' => $courseid, 'now' => $now]);
    }
}
