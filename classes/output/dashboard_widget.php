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
 * Dashboard widget output class for the SmartSection Control block.
 *
 * Implements renderable and templatable for use with Moodle's output subsystem.
 * Provides both the teacher dashboard view (upcoming/overdue unlocks, last cron action)
 * and the student view (next 3 upcoming unlocks with countdown metadata).
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_smartsection_control\output;

/**
 * Dashboard widget: collects and exports block display data for Mustache templates.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_widget implements \renderable, \templatable {
    /** @var array Upcoming section unlock records (top 3 visible). */
    public array $upcoming = [];

    /** @var array Queued upcoming section unlock records for progressive rotation. */
    public array $queued = [];

    /** @var array Overdue section unlock records (teacher view). */
    public array $overdue = [];

    /** @var object|null Most recent automatic (cron-triggered) history entry. */
    public ?object $lastaction = null;

    /** @var bool Whether this widget is being built for a student (true) or teacher (false). */
    public bool $isstudent = false;

    /** @var int Course ID; 0 means all courses (student view). */
    public int $courseid = 0;

    /**
     * Construct the widget and pre-load the required data.
     *
     * @param bool $isstudent True for the student timeline view; false for the teacher dashboard.
     * @param int  $courseid  Course ID; 0 to query across all enrolled courses (student view).
     */
    public function __construct(bool $isstudent = false, int $courseid = 0) {
        $this->isstudent = $isstudent;
        $this->courseid  = $courseid;

        if ($isstudent) {
            $this->load_student_upcoming();
        } else {
            if ($courseid > 0) {
                $this->upcoming = \block_smartsection_control\calendar::get_upcoming_unlocks($courseid, 7);
                $this->overdue  = \block_smartsection_control\calendar::get_overdue_unlocks($courseid);
            }
            $this->load_last_action();
        }
    }

    /**
     * Load the next upcoming unlocks for the current student.
     *
     * Queries future scheduled sections in the current course (or across all enrolled courses
     * if on system/dashboard context), sorted strictly ascending by nearest unlock timestamp.
     * Selects at most 3 items for initial display, and reserves additional future items in $queued
     * for seamless client-side rotation as sections unlock.
     */
    private function load_student_upcoming(): void {
        global $DB, $USER;

        $now = time();
        $courses = [];

        if ($this->courseid > 0) {
            if ($course = $DB->get_record('course', ['id' => $this->courseid])) {
                $courses[$course->id] = $course;
            }
        } else {
            $enrolled = enrol_get_all_users_courses((int) $USER->id, false, 'id, fullname, startdate');
            foreach ($enrolled as $c) {
                $courses[$c->id] = $c;
            }
        }

        if (empty($courses)) {
            return;
        }

        $allupcoming = [];
        $courseids = array_keys($courses);
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

        $sql = "SELECT bsu.*, cs.section, cs.name AS sectionname, c.fullname AS coursename
                  FROM {block_smartsection_control} bsu
                  JOIN {course_sections} cs ON cs.id = bsu.sectionid
                  JOIN {course} c ON c.id = bsu.courseid
                 WHERE bsu.courseid {$insql}
                   AND bsu.manualoverride = 0";

        $records = $DB->get_records_sql($sql, $params);

        foreach ($records as $record) {
            $course = $courses[$record->courseid] ?? null;
            if (!$course) {
                continue;
            }

            // Exclude section 0 (general section is never scheduled).
            if ((int) $record->section === 0) {
                continue;
            }

            $unlocktime = \block_smartsection_control\helper::calculate_unlock_time($record, $course);
            if ($unlocktime === false || (int) $unlocktime <= $now) {
                continue;
            }

            $allupcoming[] = [
                'id' => (int) $record->id,
                'sectionid' => (int) $record->sectionid,
                'sectionnum' => (int) $record->section,
                'courseid' => (int) $record->courseid,
                'coursename' => (string) ($record->coursename ?? $course->fullname),
                'sectionname' => $this->resolve_section_name($record),
                'unlocktime' => (int) $unlocktime,
                'locktype' => (string) ($record->locktype ?? 'hard'),
            ];
        }

        // Sort strictly ascending by nearest unlock timestamp.
        usort($allupcoming, function (array $a, array $b): int {
            return $a['unlocktime'] <=> $b['unlocktime'];
        });

        // Limit visible display to the next 3 scheduled sections.
        $this->upcoming = array_slice($allupcoming, 0, 3);

        // Retain next 5 upcoming items for client-side progressive rotation upon expiry.
        $this->queued = array_slice($allupcoming, 3, 5);
    }

    /**
     * Load the most recent cron-triggered history entry for the course.
     */
    private function load_last_action(): void {
        global $DB;

        if ($this->courseid <= 0) {
            return;
        }

        $sql = "SELECT * FROM {block_smartsection_control_h}
                 WHERE courseid    = :courseid
                   AND trigger_type = 'cron'
              ORDER BY timecreated DESC";

        $records          = $DB->get_records_sql($sql, ['courseid' => $this->courseid], 0, 1);
        $this->lastaction = !empty($records) ? reset($records) : null;
    }

    /**
     * Format remaining seconds into student-friendly countdown string for initial server render.
     *
     * @param int $diff Remaining seconds until unlock.
     * @return string Formatted countdown string.
     */
    public static function format_countdown_display(int $diff): string {
        if ($diff <= 0) {
            return '0s';
        }

        $days  = (int) floor($diff / DAYSECS);
        $hours = (int) floor(($diff % DAYSECS) / HOURSECS);
        $mins  = (int) floor(($diff % HOURSECS) / MINSECS);
        $secs  = (int) ($diff % MINSECS);

        if ($days >= 1) {
            return sprintf('%dd %02dh %02dm', $days, $hours, $mins);
        } else if ($hours >= 1) {
            return sprintf('%02dh %02dm %02ds', $hours, $mins, $secs);
        } else {
            return sprintf('%02dm %02ds', $mins, $secs);
        }
    }

    /**
     * Export widget data for consumption by Mustache templates.
     *
     * @param \renderer_base $output The page renderer.
     * @return array<string, mixed> Template context array.
     */
    public function export_for_template(\renderer_base $output): array {
        $now = time();
        $upcomingdata = [];
        $queueddata = [];

        // Resolve repeated string labels once — avoids redundant get_string() calls per row.
        $unlocksinlabel = get_string('unlocks_in', 'block_smartsection_control');
        $nextlabel      = get_string('next_unlock', 'block_smartsection_control');

        foreach ($this->upcoming as $index => $unlock) {
            $unlocktime  = (int) (is_object($unlock) ? $unlock->unlocktime : ($unlock['unlocktime'] ?? 0));
            $sectionname = $this->resolve_section_name($unlock);
            $coursename  = is_object($unlock)
                ? (string) ($unlock->coursename ?? '')
                : (string) ($unlock['coursename'] ?? '');
            $sectionid   = is_object($unlock)
                ? (int) ($unlock->sectionid ?? 0)
                : (int) ($unlock['sectionid'] ?? 0);
            $diff        = max(0, $unlocktime - $now);

            $upcomingdata[] = [
                'sectionid'           => $sectionid,
                'sectionname'         => format_string($sectionname),
                'coursename'          => ($coursename && $this->courseid === 0) ? format_string($coursename) : '',
                'rawunlocktime'       => $unlocktime,
                'unlocktimeformatted' => userdate($unlocktime, get_string('strftimedatetimeshort', 'langconfig')),
                'countdowndisplay'    => self::format_countdown_display($diff),
                'daysuntil'           => (int) ceil($diff / DAYSECS),
                'isimminent'          => ($index === 0),
                // Flag issoon is true when < 24 h remain — used for warm-accent CSS state and JS tick rate.
                'issoon'              => ($diff > 0 && $diff < DAYSECS),
                'unlocksinlabel'      => $unlocksinlabel,
                'nextlabel'           => $nextlabel,
            ];
        }

        if (!empty($this->queued)) {
            foreach ($this->queued as $unlock) {
                $unlocktime  = (int) (is_object($unlock) ? $unlock->unlocktime : ($unlock['unlocktime'] ?? 0));
                $sectionname = $this->resolve_section_name($unlock);
                $coursename  = is_object($unlock)
                    ? (string) ($unlock->coursename ?? '')
                    : (string) ($unlock['coursename'] ?? '');
                $sectionid   = is_object($unlock)
                    ? (int) ($unlock->sectionid ?? 0)
                    : (int) ($unlock['sectionid'] ?? 0);
                $diff        = max(0, $unlocktime - $now);

                $queueddata[] = [
                    'sectionid'           => $sectionid,
                    'sectionname'         => format_string($sectionname),
                    'coursename'          => ($coursename && $this->courseid === 0) ? format_string($coursename) : '',
                    'unlocktime'          => $unlocktime,
                    'unlocktimeformatted' => userdate($unlocktime, get_string('strftimedatetimeshort', 'langconfig')),
                    'countdowndisplay'    => self::format_countdown_display($diff),
                    'unlocksinlabel'      => $unlocksinlabel,
                ];
            }
        }

        $data = [
            'uniqid'         => \html_writer::random_id('ssc_widget_'),
            'nextlabel'      => $nextlabel,
            'unlocksinlabel' => $unlocksinlabel,
            'isstudent'      => $this->isstudent,
            'courseid'       => $this->courseid,
            'hasupcoming'    => !empty($upcomingdata),
            'upcomingcount'  => count($upcomingdata),
            'upcoming'       => $upcomingdata,
            'queued_json'    => !empty($queueddata) ? json_encode($queueddata) : '',
            'overdue'        => [],
            'lastaction'     => null,
        ];

        if (!$this->isstudent) {
            foreach ($this->overdue as $unlock) {
                $sectionname       = $this->resolve_section_name($unlock);
                $unlocktime        = (int) (is_object($unlock) ? $unlock->unlocktime : ($unlock['unlocktime'] ?? 0));
                $data['overdue'][] = [
                    'sectionname' => format_string($sectionname),
                    'daysoverdue' => (int) floor(($now - $unlocktime) / DAYSECS),
                ];
            }

            if ($this->lastaction) {
                $data['lastaction'] = [
                    'time' => userdate(
                        (int) $this->lastaction->timecreated,
                        get_string('strftimedatetimeshort', 'langconfig')
                    ),
                ];
            }
        }

        return $data;
    }

    /**
     * Safely extract a display-ready section name from an unlock record.
     *
     * Handles both object and array record formats and falls back to a numbered
     * placeholder when the name field is absent or empty.
     *
     * @param object|array $unlock The unlock record.
     * @return string The section name.
     */
    private function resolve_section_name(object|array $unlock): string {
        if (is_object($unlock)) {
            $name   = $unlock->sectionname ?? '';
            $number = $unlock->section ?? '';
        } else {
            $name   = $unlock['sectionname'] ?? '';
            $number = $unlock['sectionnum'] ?? ($unlock['section'] ?? '');
        }

        if (!is_string($name) || trim($name) === '') {
            return get_string('sectionname', 'block_smartsection_control') . ' ' . $number;
        }

        return (string) $name;
    }
}
