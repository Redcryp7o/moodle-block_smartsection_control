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
 * Helper utilities for the SmartSection Control block.
 *
 * Static utility class containing all business logic for unlock time calculation,
 * event condition checking, personal pacing, holiday-aware date shifting,
 * HMAC token management, and audit history logging.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_smartsection_control;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helper class for SmartSection Control unlock operations.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Return true when a section_info object is safe to pass to course format APIs.
     *
     * @param mixed $section A section_info object from modinfo.
     * @return bool
     */
    public static function is_valid_section_info(mixed $section): bool {
        return is_object($section)
            && !empty($section->id)
            && isset($section->section)
            && (int) $section->section > 0;
    }

    /**
     * Resolve a section_info object for a course_sections.id.
     *
     * @param object $course    The course object.
     * @param int    $sectionid The course_sections.id.
     * @return \section_info|false
     */
    public static function get_section_info_by_id(object $course, int $sectionid): \section_info|false {
        global $DB;

        $sectionrec = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $course->id]);
        if (!$sectionrec) {
            return false;
        }

        try {
            $modinfo = get_fast_modinfo($course);
            return $modinfo->get_section_info((int) $sectionrec->section, IGNORE_MISSING) ?: false;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Return a display-ready section name using the active course format.
     *
     * Always passes a valid section_info to course_format::get_section_name() so
     * third-party formats (e.g. RemUI) never receive invalid section objects.
     *
     * @param object $course    The course object.
     * @param int    $sectionid The course_sections.id.
     * @return string
     */
    public static function get_section_display_name(object $course, int $sectionid): string {
        global $DB;

        $sectionrec = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $course->id]);
        if (!$sectionrec) {
            return get_string('sectionname', 'block_smartsection_control');
        }

        $sectioninfo = self::get_section_info_by_id($course, $sectionid);
        if ($sectioninfo) {
            return course_get_format($course)->get_section_name($sectioninfo);
        }

        if (!empty($sectionrec->name) && is_string($sectionrec->name)) {
            return (string) $sectionrec->name;
        }

        return get_string('sectionname', 'block_smartsection_control') . ' ' . (int) $sectionrec->section;
    }

    /**
     * Return a display-ready section name from a section_info object.
     *
     * @param object     $course      The course object.
     * @param \section_info $sectioninfo The section_info from modinfo.
     * @return string
     */
    public static function get_section_display_name_from_info(object $course, \section_info $sectioninfo): string {
        if (!self::is_valid_section_info($sectioninfo)) {
            return get_string('sectionname', 'block_smartsection_control');
        }

        return course_get_format($course)->get_section_name($sectioninfo);
    }

    /**
     * Write a state-transition record to the audit history table.
     *
     * @param int         $sectionid    The course_sections.id.
     * @param int         $courseid     The course ID.
     * @param string      $action       Action performed: 'locked', 'unlocked', or 'updated'.
     * @param string      $triggertype  What triggered this: 'cron', 'manual', 'event', 'relative', 'runtime_check', 'pacing_guard'.
     * @param int         $triggeredby  User ID of actor; 0 for automated triggers.
     * @param string      $oldstate     Previous lock state.
     * @param string      $newstate     New lock state.
     * @param array       $metadata     Optional additional context as a key-value array (JSON-encoded on write).
     * @return bool True on success.
     */
    public static function log_history(
        int $sectionid,
        int $courseid,
        string $action,
        string $triggertype = '',
        int $triggeredby = 0,
        string $oldstate = '',
        string $newstate = '',
        array $metadata = []
    ): bool {
        global $DB;

        $record = (object) [
            'sectionid'    => $sectionid,
            'courseid'     => $courseid,
            'action'       => $action,
            'trigger_type' => $triggertype,
            'triggered_by' => $triggeredby ?: null,
            'old_state'    => $oldstate ?: null,
            'new_state'    => $newstate ?: null,
            'metadata'     => !empty($metadata) ? json_encode($metadata) : null,
            'timecreated'  => time(),
        ];

        return $DB->insert_record('block_smartsection_control_history', $record) !== false;
    }

    /**
     * Calculate the effective unlock timestamp for a configuration record.
     *
     * Handles all unlock types:
     * - absolute : returns the stored Unix timestamp directly.
     * - relative : computes from course start date or previous-section unlock time.
     * - event    : event-based unlocks have no fixed time; returns false.
     * - manual   : returns the current time (immediate unlock).
     *
     * @param object      $record  The block_smartsection_control row.
     * @param object|null $course  The course object (loaded from DB if omitted).
     * @return int|false Unix timestamp, or false when the time cannot be determined.
     */
    public static function calculate_unlock_time(object $record, ?object $course = null): int|false {
        global $DB;

        if (empty($record->unlocktype)) {
            $record->unlocktype = 'absolute';
        }

        switch ($record->unlocktype) {
            case 'absolute':
                return (int) $record->unlocktime;

            case 'relative':
                if (empty($record->relativesettings)) {
                    return false;
                }
                $settings = json_decode($record->relativesettings, true);
                if (!$settings) {
                    return false;
                }

                if (!$course) {
                    $course = $DB->get_record('course', ['id' => $record->courseid], '*', MUST_EXIST);
                }

                // Days after course start date (0 is valid — unlock at course start).
                if (array_key_exists('days_after_start', $settings) && $settings['days_after_start'] !== null
                        && $settings['days_after_start'] !== '') {
                    $basetime = $course->startdate ?: time();
                    return (int) ($basetime + ((int) $settings['days_after_start'] * DAYSECS));
                }

                // Days after the previous section's unlock time (0 is valid).
                if (array_key_exists('days_after_prev_section', $settings) && $settings['days_after_prev_section'] !== null
                        && $settings['days_after_prev_section'] !== '') {
                    $prevunlock = self::get_previous_section_unlock_time($record, $course);
                    if (!$prevunlock) {
                        return false;
                    }
                    return (int) ($prevunlock + ((int) $settings['days_after_prev_section'] * DAYSECS));
                }

                return false;

            case 'event':
                // Event-based unlocks have no fixed timestamp; handled by check_event_conditions().
                return false;

            case 'manual':
                return time();

            default:
                return (int) $record->unlocktime;
        }
    }

    /**
     * Return the previous section record for a given section.
     *
     * @param int $sectionid The current section's database ID.
     * @param int $courseid  The course ID.
     * @return object|false The previous course_sections row, or false if none exists.
     */
    public static function get_previous_section(int $sectionid, int $courseid): object|false {
        global $DB;

        $current = $DB->get_record('course_sections', ['id' => $sectionid], 'section', MUST_EXIST);
        if ((int) $current->section <= 0) {
            return false;
        }

        return $DB->get_record_sql(
            "SELECT * FROM {course_sections} WHERE course = :courseid AND section = :sectionnum",
            ['courseid' => $courseid, 'sectionnum' => (int) $current->section - 1]
        );
    }

    /**
     * Return the server timezone as a DateTimeZone object.
     *
     * The 'course' timezone mode is reserved for a future enhancement; Moodle core
     * does not expose a per-course timezone setting, so both 'server' and 'course'
     * resolve to the server timezone ($CFG->timezone).
     *
     * @param object      $record  The configuration record (timezone field read for future use).
     * @param object|null $course  Unused; retained for API compatibility.
     * @return \DateTimeZone
     */
    public static function get_timezone(object $record, ?object $course = null): \DateTimeZone {
        global $CFG;
        return new \DateTimeZone($CFG->timezone);
    }

    /**
     * Check whether a section's event-based unlock conditions are satisfied for a user.
     *
     * Evaluates two optional conditions in the eventconditions JSON:
     * - activity_completion : the specified course module must be marked complete.
     * - grade_threshold     : the user's grade for a given item must meet the threshold.
     *
     * @param object $record The configuration record.
     * @param int    $userid The user ID to evaluate conditions for.
     * @return bool True if all configured conditions are met.
     */
    public static function check_event_conditions(object $record, int $userid): bool {
        global $DB;

        if ($record->unlocktype !== 'event' || empty($record->eventconditions)) {
            return false;
        }

        $conditions = json_decode($record->eventconditions, true);
        if (!$conditions) {
            return false;
        }

        $hasconditions = false;

        // Check activity completion condition.
        if (!empty($conditions['activity_completion'])) {
            $hasconditions = true;
            $cm = $DB->get_record('course_modules', [
                'id' => (int) $conditions['activity_completion'],
                'course' => (int) $record->courseid,
            ], '*', IGNORE_MISSING);
            if (!$cm) {
                // Missing or cross-course CM — keep section locked.
                return false;
            }
            $completion    = new \completion_info(get_course($record->courseid));
            $cmcompletion  = $completion->get_data($cm, false, $userid);
            if (empty($cmcompletion->completionstate) || $cmcompletion->completionstate < COMPLETION_COMPLETE) {
                return false;
            }
        }

        // Check grade threshold condition.
        if (!empty($conditions['grade_threshold']) && is_array($conditions['grade_threshold'])) {
            $hasconditions = true;
            $cmid = (int) ($conditions['grade_threshold']['cmid'] ?? 0);
            $minimum = (float) ($conditions['grade_threshold']['minimum'] ?? 0);
            if ($cmid <= 0 || $minimum <= 0) {
                return false;
            }
            if (!self::meets_grade_threshold((int) $record->courseid, $cmid, $userid, $minimum)) {
                return false;
            }
        }

        return $hasconditions;
    }

    /**
     * Resolve the effective unlock timestamp of the section immediately before this one.
     *
     * @param object      $record The current section configuration record.
     * @param object|null $course The course object.
     * @return int|false Unix timestamp, or false when it cannot be determined.
     */
    public static function get_previous_section_unlock_time(object $record, ?object $course = null): int|false {
        global $DB;

        $prevsection = self::get_previous_section((int) $record->sectionid, (int) $record->courseid);
        if (!$prevsection) {
            return false;
        }

        $prevrecord = $DB->get_record('block_smartsection_control', ['sectionid' => $prevsection->id]);
        if (!$prevrecord) {
            return false;
        }

        if (!empty($prevrecord->manualoverride)) {
            return (int) ($prevrecord->timemodified ?: time());
        }

        if (!$course) {
            $course = $DB->get_record('course', ['id' => $record->courseid], '*', MUST_EXIST);
        }

        return self::calculate_unlock_time($prevrecord, $course);
    }

    /**
     * Check whether a user has met a minimum percentage grade for a graded activity.
     *
     * @param int   $courseid The course ID.
     * @param int   $cmid     The course module ID of the graded activity.
     * @param int   $userid   The user ID to evaluate.
     * @param float $minimum  Required minimum percentage (0–100).
     * @return bool True when the grade meets or exceeds the threshold.
     */
    public static function meets_grade_threshold(int $courseid, int $cmid, int $userid, float $minimum): bool {
        global $DB, $CFG;

        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], '*', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }

        // course_modules stores module as FK id; resolve the frankenstyle mod name.
        $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
        if (!$modname) {
            return false;
        }

        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $cm->instance,
        ]);
        if (!$gradeitem) {
            return false;
        }

        $grade = $gradeitem->get_grade($userid, false);
        if ($grade->finalgrade === null) {
            return false;
        }

        $grademax = (float) $gradeitem->grademax;
        if ($grademax <= 0) {
            return false;
        }

        $percent = ((float) $grade->finalgrade / $grademax) * 100;
        return $percent >= $minimum;
    }

    /**
     * Format a Unix timestamp for display, applying the configured timezone.
     *
     * @param int         $timestamp The Unix timestamp to format.
     * @param object      $record    The configuration record (for timezone resolution).
     * @param object|null $course    The course object.
     * @return string Localised date string.
     */
    public static function format_unlock_time(int $timestamp, object $record, ?object $course = null): string {
        $tz = self::get_timezone($record, $course);
        return userdate($timestamp, get_string('strftimedatefullshort', 'langconfig'), $tz->getName());
    }

    /**
     * Return a structured status array for a configuration record.
     *
     * @param object      $record  The configuration record.
     * @param object|null $course  The course object.
     * @param int|null    $userid  User ID for event-based condition checks.
     * @return array{status: string, unlocktime: int|null, message: string}
     *   - status    : 'unlocked', 'scheduled', or 'locked'
     *   - unlocktime: Unix timestamp when applicable, otherwise null
     *   - message   : Human-readable localised status description
     */
    public static function get_unlock_status(object $record, ?object $course = null, ?int $userid = null): array {
        $now = time();

        if (!empty($record->manualoverride)) {
            return [
                'status'     => 'unlocked',
                'unlocktime' => $now,
                'message'    => get_string('status_manual_unlock', 'block_smartsection_control'),
            ];
        }

        if ($record->unlocktype === 'event') {
            if ($userid && self::check_event_conditions($record, $userid)) {
                return [
                    'status'     => 'unlocked',
                    'unlocktime' => $now,
                    'message'    => get_string('status_event_unlocked', 'block_smartsection_control'),
                ];
            }
            return [
                'status'     => 'locked',
                'unlocktime' => null,
                'message'    => get_string('status_event_locked', 'block_smartsection_control'),
            ];
        }

        $unlocktime = self::calculate_unlock_time($record, $course);

        if (!$unlocktime) {
            return [
                'status'     => 'locked',
                'unlocktime' => null,
                'message'    => get_string('status_invalid', 'block_smartsection_control'),
            ];
        }

        if ($unlocktime <= $now) {
            return [
                'status'     => 'unlocked',
                'unlocktime' => $unlocktime,
                'message'    => get_string('status_unlocked', 'block_smartsection_control'),
            ];
        }

        $daysremaining = (int) ceil(($unlocktime - $now) / DAYSECS);
        return [
            'status'     => 'scheduled',
            'unlocktime' => $unlocktime,
            'message'    => get_string('status_unlocks_in_days', 'block_smartsection_control', $daysremaining),
        ];
    }

    /**
     * Determine whether a timestamp falls on a weekend or a site-calendar holiday.
     *
     * Holidays are detected by searching for site-level calendar events containing
     * common holiday keywords (holiday, vacation, break, closed) on the given day.
     *
     * @param int $timestamp Unix timestamp to check.
     * @return bool True if the date is a weekend (Sat/Sun) or a recognised holiday.
     */
    public static function is_weekend_or_holiday(int $timestamp): bool {
        global $DB;

        // ISO-8601: 6 = Saturday, 7 = Sunday.
        if ((int) date('N', $timestamp) >= 6) {
            return true;
        }

        $daystart = (int) strtotime(date('Y-m-d 00:00:00', $timestamp));
        $dayend   = (int) strtotime(date('Y-m-d 23:59:59', $timestamp));

        $likes    = [];
        $params   = ['daystart' => $daystart, 'dayend' => $dayend];
        $keywords = ['holiday', 'vacation', 'break', 'closed'];
        $i        = 0;

        foreach ($keywords as $keyword) {
            $paramname          = 'keyword' . $i;
            $likes[]            = $DB->sql_like('name', ':' . $paramname, false);
            $params[$paramname] = '%' . $keyword . '%';
            $i++;
        }

        $sql = "SELECT COUNT(*) FROM {event}
                 WHERE eventtype = 'site'
                   AND timestart <= :dayend
                   AND (timestart + timeduration) >= :daystart
                   AND (" . implode(' OR ', $likes) . ")";

        return $DB->count_records_sql($sql, $params) > 0;
    }

    /**
     * Shift a Unix timestamp by a given number of days, optionally skipping weekends and holidays.
     *
     * When skip_weekends_holidays is true, each step of the shift advances to the next
     * working day, ensuring the final date is never a weekend or calendar holiday.
     *
     * @param int  $starttime              Starting Unix timestamp.
     * @param int  $daystoshift            Number of days to shift; may be negative.
     * @param bool $skipweekendsholidays   When true, weekends and holidays are skipped.
     * @return int Shifted Unix timestamp.
     */
    public static function shift_date_excluding_weekends(
        int $starttime,
        int $daystoshift,
        bool $skipweekendsholidays = false
    ): int {
        if ($daystoshift === 0) {
            return $starttime;
        }

        if (!$skipweekendsholidays) {
            return $starttime + ($daystoshift * DAYSECS);
        }

        $currenttime = $starttime;
        $direction   = $daystoshift > 0 ? 1 : -1;
        $steps       = abs($daystoshift);

        for ($i = 0; $i < $steps; $i++) {
            $currenttime += $direction * DAYSECS;
            while (self::is_weekend_or_holiday($currenttime)) {
                $currenttime += $direction * DAYSECS;
            }
        }

        return $currenttime;
    }

    /**
     * Look up a student's personal pacing unlock timestamp for a section.
     *
     * @param int $userid    The student's user ID.
     * @param int $sectionid The course_sections.id.
     * @return int|false The personal unlock timestamp, or false if none is recorded.
     */
    public static function check_user_pacing_unlock(int $userid, int $sectionid): int|false {
        global $DB;

        $record = $DB->get_record('block_smartsection_user_unlocks', [
            'userid'    => $userid,
            'sectionid' => $sectionid,
        ]);

        return $record ? (int) $record->unlocktime : false;
    }

    /**
     * Calculate the aggregate completion rate for all tracked activities in the preceding section.
     *
     * Used by the pacing-guard notification to warn instructors when students have not
     * finished the current section before the next one is about to unlock.
     *
     * @param int $sectionid The section about to unlock.
     * @param int $courseid  The course ID.
     * @return float|null Rate between 0.0 and 1.0, or null if the data is unavailable.
     */
    public static function get_prev_section_completion_rate(int $sectionid, int $courseid): ?float {
        global $DB;

        $prevsection = self::get_previous_section($sectionid, $courseid);
        if (!$prevsection) {
            return null;
        }

        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                 WHERE cm.section = :sectionid
                   AND cm.completion > 0
                   AND cm.deletioninprogress = 0";
        $cms = $DB->get_records_sql($sql, ['sectionid' => $prevsection->id]);

        if (empty($cms)) {
            return null;
        }

        $context    = \context_course::instance($courseid);
        $students   = get_enrolled_users($context, 'moodle/course:participate');
        if (empty($students)) {
            return null;
        }

        $completion = new \completion_info(get_course($courseid));
        if (!$completion->is_enabled()) {
            return null;
        }

        $modinfo       = get_fast_modinfo($courseid);
        $totaltracked  = 0;
        $totalcomplete = 0;

        foreach ($students as $student) {
            if (has_capability('block/smartsection_control:manage', $context, $student->id)) {
                continue;
            }
            foreach ($cms as $cmrec) {
                try {
                    $cm = $modinfo->get_cm($cmrec->id);
                } catch (\moodle_exception $e) {
                    // CM is in a transitional state (e.g. deletion in progress); skip it.
                    continue;
                }
                $cmcompletion = $completion->get_data($cm, false, $student->id);
                $totaltracked++;
                if (!empty($cmcompletion->completionstate)
                    && ($cmcompletion->completionstate == COMPLETION_COMPLETE
                        || $cmcompletion->completionstate == COMPLETION_COMPLETE_PASS)) {
                    $totalcomplete++;
                }
            }
        }

        return $totaltracked > 0 ? $totalcomplete / $totaltracked : null;
    }

    /**
     * Marker embedded in Soft Lock availability conditions so they can be
     * added/removed without destroying teacher-authored Moodle restrictions.
     */
    public const AVAILABILITY_MARKER = 'ssc';

    /**
     * Whether a section is currently locked for a specific user.
     *
     * Used for per-user enforcement (URL intercept / Soft Lock CSS). Does not
     * mutate shared course state.
     *
     * @param object      $record Configuration record.
     * @param object|null $course Course object.
     * @param int         $userid User ID to evaluate.
     * @return bool True when the user should be denied access.
     */
    public static function is_section_locked_for_user(object $record, ?object $course, int $userid): bool {
        if (!empty($record->manualoverride)) {
            return false;
        }

        $now = time();

        if ($record->unlocktype === 'event') {
            if (!self::check_event_conditions($record, $userid)) {
                return true;
            }
            // Conditions met — honour personal pacing delay when present.
            $personal = self::check_user_pacing_unlock($userid, (int) $record->sectionid);
            if ($personal) {
                return $personal > $now;
            }
            return false;
        }

        $personal = self::check_user_pacing_unlock($userid, (int) $record->sectionid);
        if ($personal && $personal <= $now) {
            return false;
        }

        $unlocktime = self::calculate_unlock_time($record, $course);
        if (!$unlocktime) {
            return true;
        }

        return $unlocktime > $now;
    }

    /**
     * Schedule-based visibility for course-wide enforcement (cron / absolute / relative).
     *
     * Event-based unlocks are per-user and must not drive shared visibility.
     *
     * @param object      $record Configuration record.
     * @param object|null $course Course object.
     * @return bool True when the section should be unlocked for everyone.
     */
    public static function is_schedule_unlocked(object $record, ?object $course = null): bool {
        if (!empty($record->manualoverride)) {
            return true;
        }
        if (($record->unlocktype ?? '') === 'event') {
            return false;
        }
        $unlocktime = self::calculate_unlock_time($record, $course);
        return (bool) ($unlocktime && $unlocktime <= time());
    }

    /**
     * Write merged Soft Lock availability onto a course section.
     *
     * @param int $sectionid  course_sections.id.
     * @param int $unlocktime Unix unlock timestamp.
     * @return bool True when the stored availability value changed.
     */
    public static function apply_soft_lock_availability(int $sectionid, int $unlocktime): bool {
        global $DB;
        $current = $DB->get_field('course_sections', 'availability', ['id' => $sectionid]);
        $existing = ($current !== false && $current !== null && $current !== '') ? (string) $current : null;
        // Remove prior Soft Lock rules (tagged or legacy same-timestamp date) then merge.
        $cleaned = self::remove_soft_lock_from_availability($existing, $unlocktime);
        $merged = self::merge_soft_lock_into_availability($cleaned, $unlocktime);
        if ((string) ($existing ?? '') === (string) $merged) {
            return false;
        }
        $DB->set_field('course_sections', 'availability', $merged, ['id' => $sectionid]);
        return true;
    }

    /**
     * Strip Soft Lock conditions from a section, leaving other restrictions intact.
     *
     * @param int      $sectionid           course_sections.id.
     * @param int|null $matchingunlocktime  Also remove legacy untagged date rules with this timestamp.
     * @return bool True when the stored availability value changed.
     */
    public static function strip_soft_lock_availability(int $sectionid, ?int $matchingunlocktime = null): bool {
        global $DB;
        $current = $DB->get_field('course_sections', 'availability', ['id' => $sectionid]);
        if ($current === false || $current === null || $current === '') {
            return false;
        }
        $cleaned = self::remove_soft_lock_from_availability((string) $current, $matchingunlocktime);
        $newvalue = $cleaned;
        if ((string) $current === (string) ($newvalue ?? '')) {
            return false;
        }
        $DB->set_field('course_sections', 'availability', $newvalue, ['id' => $sectionid]);
        return true;
    }

    /**
     * Merge Soft Lock date restriction into existing section availability JSON.
     *
     * Preserves all non-plugin Moodle restrictions (group, grade, completion, etc.).
     *
     * @param string|null $json       Existing course_sections.availability JSON.
     * @param int         $unlocktime Unix timestamp when access becomes allowed.
     * @return string Encoded availability tree.
     */
    public static function merge_soft_lock_into_availability(?string $json, int $unlocktime): string {
        $ssccondition = [
            'type' => 'date',
            'd' => '>=',
            't' => $unlocktime,
            self::AVAILABILITY_MARKER => 1,
        ];

        $tree = self::decode_availability_tree($json);
        $tree = self::strip_ssc_from_tree($tree, $unlocktime);

        if ($tree === null) {
            $tree = [
                'op' => '&',
                'c' => [$ssccondition],
                'showc' => [false],
            ];
        } else if (($tree['op'] ?? '&') === '&') {
            $tree['c'][] = $ssccondition;
            if (!isset($tree['showc']) || !is_array($tree['showc'])) {
                $tree['showc'] = array_fill(0, max(0, count($tree['c']) - 1), true);
            }
            while (count($tree['showc']) < count($tree['c']) - 1) {
                $tree['showc'][] = true;
            }
            $tree['showc'][] = false;
        } else {
            $tree = [
                'op' => '&',
                'c' => [$tree, $ssccondition],
                'showc' => [true, false],
            ];
        }

        return json_encode($tree);
    }

    /**
     * Remove only Soft Lock (SSC-tagged) conditions from availability JSON.
     *
     * @param string|null $json                Existing availability JSON.
     * @param int|null    $matchingunlocktime  Also drop legacy date>= rules with this t value.
     * @return string|null Remaining JSON, or null when no restrictions remain.
     */
    public static function remove_soft_lock_from_availability(?string $json, ?int $matchingunlocktime = null): ?string {
        $tree = self::decode_availability_tree($json);
        if ($tree === null) {
            return null;
        }
        $tree = self::strip_ssc_from_tree($tree, $matchingunlocktime);
        if ($tree === null || empty($tree['c'])) {
            return null;
        }
        if (count($tree['c']) === 1 && isset($tree['c'][0]['op'])) {
            $tree = $tree['c'][0];
        }
        return json_encode($tree);
    }

    /**
     * @param string|null $json Availability JSON.
     * @return array|null Decoded tree or null.
     */
    private static function decode_availability_tree(?string $json): ?array {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || empty($decoded['c']) || !is_array($decoded['c'])) {
            return null;
        }
        return $decoded;
    }

    /**
     * Recursively remove SSC-tagged leaf conditions from an availability tree.
     *
     * @param array|null $tree                 Decoded tree.
     * @param int|null   $matchingunlocktime   Also remove legacy date>= with this t.
     * @return array|null Cleaned tree, or null if empty.
     */
    private static function strip_ssc_from_tree(?array $tree, ?int $matchingunlocktime = null): ?array {
        if ($tree === null || empty($tree['c']) || !is_array($tree['c'])) {
            return null;
        }

        $newc = [];
        $newshowc = [];
        $showc = isset($tree['showc']) && is_array($tree['showc']) ? $tree['showc'] : [];

        foreach ($tree['c'] as $i => $child) {
            if (!is_array($child)) {
                continue;
            }
            if (isset($child['op'])) {
                $stripped = self::strip_ssc_from_tree($child, $matchingunlocktime);
                if ($stripped !== null && !empty($stripped['c'])) {
                    $newc[] = $stripped;
                    $newshowc[] = $showc[$i] ?? true;
                }
                continue;
            }
            if (!empty($child[self::AVAILABILITY_MARKER])) {
                continue;
            }
            // Legacy Soft Lock wrote an untagged date condition; drop matching timestamps.
            if ($matchingunlocktime !== null
                    && ($child['type'] ?? '') === 'date'
                    && ($child['d'] ?? '') === '>='
                    && (int) ($child['t'] ?? 0) === $matchingunlocktime) {
                continue;
            }
            $newc[] = $child;
            $newshowc[] = $showc[$i] ?? true;
        }

        if (empty($newc)) {
            return null;
        }

        $tree['c'] = $newc;
        $tree['showc'] = $newshowc;
        return $tree;
    }

    /**
     * Parse a datetime string in the current user's Moodle timezone.
     *
     * Use this instead of strtotime() for form inputs (e.g. datetime-local) so the
     * stored Unix timestamp reflects the instructor's configured timezone, not the
     * server PHP default timezone.
     *
     * @param string $datestr Datetime string (e.g. "2026-09-01T08:00" or "2026-09-01 08:00").
     * @return int|false Unix timestamp on success, false on failure.
     */
    public static function parse_user_datetime(string $datestr) {
        $datestr = trim($datestr);
        if ($datestr === '') {
            return false;
        }
        try {
            $tz = \core_date::get_user_timezone_object();
            $dt = new \DateTime($datestr, $tz);
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build History list SQL and named params using Moodle user fields API.
     *
     * Uses core_user\fields::for_name()->get_sql() with leadingcomma=true so the
     * returned selects fragment already begins with a comma and joins safely after
     * the base SELECT list.
     *
     * @param int $courseid Canonical course id.
     * @return array{sql: string, params: array, fieldprefix: string} SQL, params, and name-field prefix.
     */
    public static function get_history_list_sql(int $courseid): array {
        $courseid = self::require_course_id($courseid);
        $fieldprefix = 'triggeruser';

        // Named params + leadingcomma=true: selects begins with ", u.field AS triggeruserfield...".
        $userfieldsql = \core_user\fields::for_name()->get_sql(
            'u',
            true,
            $fieldprefix,
            '',
            true
        );

        $sql = "SELECT h.*, cs.section, cs.name AS sectionname
                       {$userfieldsql->selects}
                  FROM {block_smartsection_control_history} h
                  JOIN {course_sections} cs ON cs.id = h.sectionid
             LEFT JOIN {user} u ON u.id = h.triggered_by AND u.deleted = 0
                       {$userfieldsql->joins}
                 WHERE h.courseid = :courseid
              ORDER BY h.timecreated DESC";

        $params = $userfieldsql->params;
        $params['courseid'] = $courseid;

        return [
            'sql' => $sql,
            'params' => $params,
            'fieldprefix' => $fieldprefix,
        ];
    }

    /**
     * Format the actor name for a history row using Moodle fullname fields.
     *
     * Prefixed columns come from get_history_list_sql() / get_sql(..., $prefix, ..., true).
     * Moodle aliases are prefix + field with no separator (e.g. triggeruserfirstname).
     *
     * @param object $record     History row including prefixed user name columns.
     * @param string $fieldprefix Prefix used in get_sql(), e.g. triggeruser.
     * @return string Display name for triggered_by column.
     */
    public static function format_history_actor(object $record, string $fieldprefix = 'triggeruser'): string {
        if (empty($record->triggered_by)) {
            return get_string('history_actor_system', 'block_smartsection_control');
        }

        // Prefer get_name_fields() when available; fall back to for_name()->get_required_fields().
        if (method_exists(\core_user\fields::class, 'get_name_fields')) {
            $namefields = \core_user\fields::get_name_fields();
        } else {
            $namefields = \core_user\fields::for_name()->get_required_fields();
        }

        $user = (object) ['id' => (int) $record->triggered_by];
        $hascorename = false;

        foreach ($namefields as $field) {
            $column = $fieldprefix . $field;
            $user->$field = property_exists($record, $column) ? (string) ($record->$column ?? '') : '';
            if (($field === 'firstname' || $field === 'lastname') && $user->$field !== '') {
                $hascorename = true;
            }
        }

        // Joined user missing/deleted, or name fields absent.
        if (!$hascorename) {
            return get_string('history_actor_unknown', 'block_smartsection_control');
        }

        return fullname($user);
    }

    /**
     * Normalize and validate a course ID from request or course object.
     *
     * @param mixed $courseid Raw course id.
     * @return int Valid course id (> 1).
     */
    public static function require_course_id($courseid): int {
        $id = clean_param($courseid, PARAM_INT);
        if ($id <= 1) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        return $id;
    }

    /**
     * Return canonical int course id from a Moodle course object.
     *
     * @param object $course Course record from get_course().
     * @return int
     */
    public static function course_id_from(object $course): int {
        return self::require_course_id($course->id);
    }

    /**
     * Set section visibility using the Moodle 5.2 course format API.
     *
     * @param int          $courseid     Course id.
     * @param \section_info $sectioninfo Section from modinfo.
     * @param bool         $visible      Target visibility.
     * @param bool         $rebuildcache Whether to rebuild course cache after change.
     * @return bool True when visibility was changed.
     */
    public static function set_section_visibility(
        int $courseid,
        \section_info $sectioninfo,
        bool $visible,
        bool $rebuildcache = true
    ): bool {
        global $DB;

        $courseid = self::require_course_id($courseid);
        $sectionid = self::require_section_id($sectioninfo->id, $courseid);

        $currentvisible = (int) $DB->get_field('course_sections', 'visible', ['id' => $sectionid]);
        $targetvisible = $visible ? 1 : 0;
        if ($currentvisible === $targetvisible) {
            return false;
        }

        \core_courseformat\formatactions::section($courseid)->set_visibility($sectioninfo, $visible);

        if ($rebuildcache) {
            rebuild_course_cache($courseid, true);
        }

        return true;
    }

    /**
     * Load section_info for a course_sections.id within a course.
     *
     * @param int $courseid  Course id.
     * @param int $sectionid course_sections.id.
     * @return \section_info
     */
    public static function get_section_info_by_section_id(int $courseid, int $sectionid): \section_info {
        global $DB;

        $courseid = self::require_course_id($courseid);
        $sectionid = self::require_section_id($sectionid, $courseid);
        $sectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $sectionid], MUST_EXIST);
        $modinfo = get_fast_modinfo($courseid);
        $sectioninfo = $modinfo->get_section_info($sectionnum, MUST_EXIST);
        return $sectioninfo;
    }

    /**
     * Record a SmartSection rule change in the audit history.
     *
     * @param int         $sectionid    course_sections.id.
     * @param int         $courseid     Course id.
     * @param string      $action       created|updated|cleared|locked|unlocked.
     * @param string      $triggertype  manage_save|bulk_lockall|manual|cron|etc.
     * @param int         $userid       Actor user id; 0 for automated.
     * @param object|null $record       Optional SSC rule record for metadata.
     * @param array       $extrametadata Additional metadata keys.
     * @return bool
     */
    public static function log_rule_history(
        int $sectionid,
        int $courseid,
        string $action,
        string $triggertype,
        int $userid,
        ?object $record = null,
        array $extrametadata = []
    ): bool {
        $metadata = $extrametadata;
        if ($record !== null) {
            $metadata['unlocktype'] = $record->unlocktype ?? '';
            $metadata['locktype'] = $record->locktype ?? 'hard';
            if (!empty($record->unlocktime)) {
                $metadata['unlocktime'] = (int) $record->unlocktime;
            }
        }

        return self::log_history(
            $sectionid,
            $courseid,
            $action,
            $triggertype,
            $userid,
            '',
            '',
            $metadata
        );
    }

    /**
     * Normalize and validate a course_sections.id from request or section_info.
     *
     * @param mixed $sectionid Raw section id (may be string from forms/DB).
     * @param int   $courseid  Expected owning course id.
     * @return int Valid course_sections.id.
     */
    public static function require_section_id($sectionid, int $courseid): int {
        global $DB;

        $id = clean_param($sectionid, PARAM_INT);
        if ($id <= 0) {
            throw new \moodle_exception('invalidsection', 'error');
        }

        $section = $DB->get_record('course_sections', ['id' => $id, 'course' => $courseid], 'id,course', IGNORE_MISSING);
        if (!$section) {
            throw new \moodle_exception('invalidsection', 'error');
        }

        return $id;
    }

    /**
     * Resolve course_sections.id from a section_info object for the active course.
     *
     * @param \section_info $section  Section from modinfo.
     * @param int           $courseid Course id.
     * @return int course_sections.id
     */
    public static function resolve_section_id_from_info(\section_info $section, int $courseid): int {
        return self::require_section_id($section->id, $courseid);
    }

    /**
     * Remap course-module IDs stored inside eventconditions JSON for restore.
     *
     * @param string   $json     Existing eventconditions JSON.
     * @param callable $mapcmid  function(int $oldcmid): int — return 0 if unmapped.
     * @return array{json: string, dropped: bool} Remapped JSON (empty when none remain) and whether any CM was dropped.
     */
    public static function remap_eventconditions_cmids(string $json, callable $mapcmid): array {
        if ($json === '') {
            return ['json' => '', 'dropped' => false];
        }
        $conditions = json_decode($json, true);
        if (!is_array($conditions)) {
            return ['json' => $json, 'dropped' => false];
        }

        $dropped = false;

        if (!empty($conditions['activity_completion'])) {
            $newcmid = (int) $mapcmid((int) $conditions['activity_completion']);
            if ($newcmid > 0) {
                $conditions['activity_completion'] = $newcmid;
            } else {
                unset($conditions['activity_completion']);
                $dropped = true;
            }
        }

        if (!empty($conditions['grade_threshold']['cmid'])) {
            $newcmid = (int) $mapcmid((int) $conditions['grade_threshold']['cmid']);
            if ($newcmid > 0) {
                $conditions['grade_threshold']['cmid'] = $newcmid;
            } else {
                unset($conditions['grade_threshold']);
                $dropped = true;
            }
        }

        if ($dropped) {
            $conditions['restore_needs_review'] = 1;
        }

        if (empty($conditions) || (
            empty($conditions['activity_completion'])
            && empty($conditions['grade_threshold'])
            && empty($conditions['restore_needs_review'])
        )) {
            // Keep explicit review marker when conditions were dropped entirely.
            if ($dropped) {
                return ['json' => (string) json_encode(['restore_needs_review' => 1]), 'dropped' => true];
            }
            return ['json' => '', 'dropped' => false];
        }

        return ['json' => (string) json_encode($conditions), 'dropped' => $dropped];
    }

    /**
     * Whether Soft Lock is allowed for a given unlock type (Marketplace v1).
     *
     * Soft Lock supports fixed-date and relative schedules only. Completion
     * triggers require Hard Lock because Moodle section availability cannot
     * express per-user SmartSection pacing safely.
     *
     * @param string $unlocktype absolute|relative|event|...
     * @return bool
     */
    public static function soft_lock_allowed_for_unlocktype(string $unlocktype): bool {
        return $unlocktype === 'absolute' || $unlocktype === 'relative';
    }

    /**
     * Generate an HMAC-SHA256 token used to authenticate delay-unlock email links.
     *
     * The token binds userid, sectionid, days, the current unlocktime (anti-replay),
     * and a Unix day-bucket. Uses $CFG->siteidentifier only — fails closed if unset.
     *
     * @param int      $userid     The instructor's user ID.
     * @param int      $sectionid  The section ID.
     * @param int      $days       The number of days to delay (1–7).
     * @param int      $unlocktime Current scheduled unlock timestamp (bound into HMAC).
     * @param int|null $daybucket  Optional day-bucket override; defaults to today.
     * @return string 64-character hex SHA-256 HMAC.
     */
    public static function generate_delay_token(
        int $userid,
        int $sectionid,
        int $days,
        int $unlocktime,
        ?int $daybucket = null
    ): string {
        global $CFG;
        if (empty($CFG->siteidentifier)) {
            throw new \coding_exception('Cannot generate delay token: $CFG->siteidentifier is not set.');
        }
        $daybucket = $daybucket ?? (int) floor(time() / 86400);
        $payload = $userid . '-' . $sectionid . '-' . $days . '-' . $unlocktime . '-' . $daybucket;
        return hash_hmac('sha256', $payload, $CFG->siteidentifier);
    }

    /**
     * Verify a delay-unlock token received via email link.
     *
     * Accepts tokens whose day-bucket is within ±7 days of today. Binding the
     * current unlocktime means a token becomes invalid after one successful delay
     * (anti-replay). Uses hash_equals() for constant-time comparison.
     *
     * @param int    $userid     The instructor's user ID.
     * @param int    $sectionid  The section ID.
     * @param int    $days       The claimed delay in days.
     * @param int    $unlocktime Current unlocktime from the configuration record.
     * @param string $token      The token to verify.
     * @return bool True if the token is valid and not expired.
     */
    public static function verify_delay_token(
        int $userid,
        int $sectionid,
        int $days,
        int $unlocktime,
        string $token
    ): bool {
        global $CFG;
        if ($token === '' || $days < 1 || $days > 7 || empty($CFG->siteidentifier)) {
            return false;
        }
        $today = (int) floor(time() / 86400);
        for ($offset = -7; $offset <= 7; $offset++) {
            $expected = self::generate_delay_token($userid, $sectionid, $days, $unlocktime, $today + $offset);
            if (hash_equals($expected, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Release plugin control of a section: strip Soft Lock markers.
     *
     * Hard Lock: restore section to visible (SSC owned the hide state).
     * Soft Lock: do not force visibility — Soft Lock never owns teacher hide state;
     * only SSC availability markers are removed.
     *
     * @param int         $sectionid  course_sections.id
     * @param int         $courseid   Course ID
     * @param int|null    $unlocktime Optional timestamp for legacy Soft Lock date cleanup
     * @param string      $locktype   hard|soft — defaults to hard for conservative unlock on legacy callers
     */
    public static function release_section_control(
        int $sectionid,
        int $courseid,
        ?int $unlocktime = null,
        string $locktype = 'hard'
    ): void {
        $courseid = self::require_course_id($courseid);
        $sectionid = self::require_section_id($sectionid, $courseid);

        self::strip_soft_lock_availability($sectionid, $unlocktime);
        if ($locktype !== 'hard') {
            return;
        }

        $sectioninfo = self::get_section_info_by_section_id($courseid, $sectionid);
        self::set_section_visibility($courseid, $sectioninfo, true, false);
    }
}
