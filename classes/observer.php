<?php
declare(strict_types=1);
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Event observer for the SmartSection Control block.
 *
 * Listens for activity completion events and writes per-student pacing unlock
 * timestamps to block_smartsection_control_u, enabling personal velocity-based
 * content release.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_smartsection_control;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer callbacks for block_smartsection_control.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * React to an activity completion state change.
     *
     * When a student completes an activity that is configured as a section unlock
     * trigger, a personal pacing timestamp is calculated and stored. The timestamp
     * is the current time plus any configured pacing_delay_days, giving instructors
     * the ability to space out individual student progress.
     *
     * If the student has already triggered an unlock for the same section, the
     * earlier of the two timestamps is kept to avoid accidentally extending access.
     *
     * @param \core\event\course_module_completion_updated $event The completion event.
     */
    public static function course_module_completion_updated(
        \core\event\course_module_completion_updated $event
    ): void {
        global $DB;

        $eventdata = $event->get_record_snapshot('course_modules_completion', $event->objectid);
        if (!$eventdata) {
            return;
        }

        // Only process fully-completed states (COMPLETION_COMPLETE = 1, COMPLETION_COMPLETE_PASS = 2).
        if ((int) $eventdata->completionstate < 1) {
            return;
        }

        $userid = (int) $eventdata->userid;
        $cmid   = (int) $eventdata->coursemoduleid;

        $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        if (!$cm) {
            return;
        }

        // Find all event-based unlock rules in this course that reference this activity.
        $sql = "SELECT bsu.*
                  FROM {block_smartsection_control} bsu
                 WHERE bsu.courseid   = :courseid
                   AND bsu.unlocktype = 'event'
                   AND bsu.eventconditions IS NOT NULL";

        $records = $DB->get_records_sql($sql, ['courseid' => $cm->course]);

        foreach ($records as $record) {
            $conditions = json_decode($record->eventconditions, true);
            if (!$conditions) {
                continue;
            }

            if (empty($conditions['activity_completion'])
                || (int) $conditions['activity_completion'] !== $cmid) {
                continue;
            }

            // Honour all event conditions (completion + optional grade threshold)
            // before creating a personal pacing unlock.
            if (!helper::check_event_conditions($record, $userid)) {
                continue;
            }

            $pacingdays = isset($conditions['pacing_delay_days']) ? (int) $conditions['pacing_delay_days'] : 0;
            $unlocktime = time() + ($pacingdays * DAYSECS);

            $userunlock = $DB->get_record('block_smartsection_control_u', [
                'userid'    => $userid,
                'sectionid' => (int) $record->sectionid,
            ]);

            if ($userunlock) {
                // Keep the earlier unlock time if triggered more than once.
                $userunlock->unlocktime = min((int) $userunlock->unlocktime, $unlocktime);
                $DB->update_record('block_smartsection_control_u', $userunlock);
            } else {
                $DB->insert_record('block_smartsection_control_u', (object) [
                    'userid'      => $userid,
                    'sectionid'   => (int) $record->sectionid,
                    'courseid'    => (int) $cm->course,
                    'unlocktime'  => $unlocktime,
                    'timecreated' => time(),
                ]);
            }
        }
    }
}
