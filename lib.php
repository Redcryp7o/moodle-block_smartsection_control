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
 * Core library functions for the SmartSection Control block.
 *
 * This file contains all procedural functions that must be globally available:
 * the enforcement engine, the scheduled visibility checker, and the manual unlock
 * helper. It is loaded via require_once in the block class and by the hook listener.
 *
 * Note: namespaced helper and calendar classes are autoloaded by Moodle's PSR-0
 * autoloader and do not require explicit require_once calls here.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Enforce the correct lock state for a single course section.
 *
 * Hard lock: toggles Moodle section visibility.
 * Soft lock: keeps the section visible, merges a tagged date restriction into
 * existing Moodle availability JSON (never wiping teacher-authored rules), and
 * optionally injects teaser CSS on web requests.
 *
 * @param int           $courseid    The course ID.
 * @param \section_info $sectioninfo Section from get_fast_modinfo().
 * @param stdClass      $record      The block_smartsection_control configuration record.
 * @param bool          $visible     Whether the section should currently be visible/unlocked.
 * @param int           $userid      The user ID performing or triggering the action.
 */
function block_smartsection_control_enforce_lock_state(
    int $courseid,
    \section_info $sectioninfo,
    stdClass $record,
    bool $visible,
    int $userid,
    bool $rebuildcache = true
): bool {
    global $DB;

    $courseid = \block_smartsection_control\helper::require_course_id($courseid);
    $sectionid = \block_smartsection_control\helper::require_section_id($sectioninfo->id, $courseid);

    $changed = false;

    // Marketplace v1: Soft Lock is schedule-only. Completion triggers use Hard Lock.
    if (($record->unlocktype ?? '') === 'event' && ($record->locktype ?? 'hard') === 'soft') {
        $record->locktype = 'hard';
        if (!empty($record->id)) {
            $DB->set_field('block_smartsection_control', 'locktype', 'hard', ['id' => (int) $record->id]);
        }
    }

    $locktype = $record->locktype ?? 'hard';

    if ($locktype === 'soft') {
        $sectionrec = $DB->get_record('course_sections', ['id' => $sectionid]);
        if (!$sectionrec) {
            return false;
        }

        // Soft lock sections must remain visible in the course outline.
        if ((int) $sectionrec->visible !== 1) {
            $changed = \block_smartsection_control\helper::set_section_visibility(
                $courseid,
                $sectioninfo,
                true,
                false
            ) || $changed;
            $sectionrec = $DB->get_record('course_sections', ['id' => $sectionid]);
        }

        if (!$visible) {
            $unlocktype = $record->unlocktype ?? 'absolute';
            if ($unlocktype !== 'event') {
                $unlocktime = \block_smartsection_control\helper::calculate_unlock_time(
                    $record,
                    get_course($courseid)
                );
                if ($unlocktime) {
                    $changed = \block_smartsection_control\helper::apply_soft_lock_availability(
                        $sectionid,
                        (int) $unlocktime
                    ) || $changed;
                } else {
                    $changed = \block_smartsection_control\helper::strip_soft_lock_availability($sectionid)
                        || $changed;
                }
            } else {
                $changed = \block_smartsection_control\helper::strip_soft_lock_availability($sectionid)
                    || $changed;
            }
        } else {
            $matchtime = null;
            if (($record->unlocktype ?? '') !== 'event') {
                $calculated = \block_smartsection_control\helper::calculate_unlock_time(
                    $record,
                    get_course($courseid)
                );
                $matchtime = $calculated ? (int) $calculated : null;
            }
            $changed = \block_smartsection_control\helper::strip_soft_lock_availability(
                $sectionid,
                $matchtime
            ) || $changed;
        }

        if ($changed && $rebuildcache) {
            rebuild_course_cache($courseid, true);
        }
        return $changed;
    }

    // Hard lock: ensure any leftover Soft Lock availability marker is removed first.
    $changed = \block_smartsection_control\helper::strip_soft_lock_availability($sectionid) || $changed;

    $currentvisible = $DB->get_field('course_sections', 'visible', ['id' => $sectionid]);
    $newvisible     = $visible ? 1 : 0;

    if ((int) $currentvisible !== $newvisible) {
        $changed = \block_smartsection_control\helper::set_section_visibility(
            $courseid,
            $sectioninfo,
            $visible,
            false
        ) || $changed;

        if ($changed) {
            \block_smartsection_control\helper::log_history(
                $sectionid,
                $courseid,
                $visible ? 'unlocked' : 'locked',
                'runtime_check',
                $userid,
                $currentvisible ? 'unlocked' : 'locked',
                $visible ? 'unlocked' : 'locked'
            );
        }
    }

    if ($changed && $rebuildcache) {
        rebuild_course_cache($courseid, true);
    }

    return $changed;
}

/**
 * Enforce all section visibility rules for the current page request.
 *
 * Called on every page load via the Moodle 5.x hook (db/hooks.php) and
 * directly from the block's get_content() method. It performs three roles:
 *
 * 1. Intercepts direct URL access to Moodle activity view pages and redirects
 *    users when they attempt to access a locked activity.
 * 2. Injects soft-lock CSS on course view pages for sections in teaser mode.
 * Shared course visibility/availability mutations are performed by the scheduled
 * task and teacher actions — not on ordinary student page views.
 */
function block_smartsection_control_before_http_headers(): void {
    global $DB, $PAGE, $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (!get_config('block_smartsection_control', 'enabled')) {
        return;
    }

    $script = $PAGE->pagetype ?? '';

    // -----------------------------------------------------------------------
    // Role 1 — Intercept direct URL access to locked activity pages (read-only).
    // -----------------------------------------------------------------------
    // Match any /mod/ script (view, attempt, review, etc.), not only view.php.
    if (strpos($PAGE->url->out_omit_querystring(), '/mod/') !== false) {

        $courseid = optional_param('course', 0, PARAM_INT);
        if (!$courseid) {
            $courseid = $PAGE->course->id ?? 0;
        }

        if ($courseid && $courseid > 1) {
            $context = context_course::instance($courseid);
            // Teachers/managers who can ignore availability or manage this plugin
            // must not be redirected away from activities.
            if (has_capability('moodle/course:ignoreavailabilityrestrictions', $context)
                    || has_capability('block/smartsection_control:manage', $context)
                    || has_capability('moodle/course:viewhiddensections', $context)) {
                return;
            }

            $modinfo  = get_fast_modinfo($courseid);
            $course   = get_course($courseid);
            $cmid     = optional_param('id', 0, PARAM_INT);
            if (!$cmid) {
                $cmid = optional_param('cmid', 0, PARAM_INT);
            }

            if (!$cmid) {
                return;
            }

            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\Throwable $e) {
                return;
            }

            $record = $DB->get_record('block_smartsection_control', [
                'sectionid' => (int) $cm->section,
                'courseid' => $courseid,
            ]);
            if (!$record || !empty($record->manualoverride)) {
                return;
            }

            if (\block_smartsection_control\helper::is_section_locked_for_user(
                $record,
                $course,
                (int) $USER->id
            )) {
                redirect(
                    new moodle_url('/course/view.php', ['id' => $courseid]),
                    get_string('section_locked_message', 'block_smartsection_control'),
                    null,
                    \core\output\notification::NOTIFY_WARNING
                );
            }
        }
        return;
    }

    // -----------------------------------------------------------------------
    // Role 2 — Soft-lock CSS injection on course view pages (presentation only).
    // Access control for Soft Lock schedule modes is Moodle availability JSON.
    // -----------------------------------------------------------------------
    if (!empty($PAGE->course->id) && $PAGE->course->id > 1 && strpos($script, 'course-view') !== false) {
        $courseid = (int) $PAGE->course->id;
        $context  = context_course::instance($courseid);

        if (!has_capability('block/smartsection_control:manage', $context)
                && !has_capability('moodle/course:ignoreavailabilityrestrictions', $context)) {
            $course  = get_course($courseid);
            $records = $DB->get_records('block_smartsection_control', [
                'courseid' => $courseid,
                'locktype' => 'soft',
            ]);

            $selectors = [];
            foreach ($records as $record) {
                // Soft Lock + completion is not supported (coerced to hard elsewhere).
                if (($record->unlocktype ?? '') === 'event') {
                    continue;
                }
                if (!\block_smartsection_control\helper::is_section_locked_for_user(
                    $record,
                    $course,
                    (int) $USER->id
                )) {
                    continue;
                }
                $sectionrec = $DB->get_record('course_sections', ['id' => $record->sectionid], 'id, section');
                if ($sectionrec) {
                    $sectionnum = (int) $sectionrec->section;
                    $selectors[] = "#section-{$sectionnum}";
                    $selectors[] = "li#section-{$sectionnum}";
                    $selectors[] = "[data-sectionid='{$record->sectionid}']";
                    $selectors[] = "[data-sectionreturnid='{$sectionnum}']";
                }
            }

            if (!empty($selectors)) {
                $cssrule = implode(', ', $selectors) . ' { opacity: 0.5 !important; pointer-events: none !important; user-select: none !important; }';
                $jscode = "(function(){var s=document.createElement('style');s.setAttribute('data-plugin','block_smartsection_control');s.textContent=" . json_encode($cssrule) . ";document.head.appendChild(s);})();";
                $PAGE->requires->js_init_code($jscode);
            }
        }
    }
}

/**
 * Run the full visibility enforcement pass across all courses.
 *
 * Called by the check_section_visibility scheduled task (daily at 02:00).
 * Iterates every configuration record and enforces the current lock state.
 * Also performs a garbage-collection pass to remove orphaned records.
 *
 * @return array{
 *   orphaned_rules: int,
 *   orphaned_history: int,
 *   orphaned_pacing: int,
 *   enforced: int,
 *   skipped_manual: int,
 *   skipped_missing_section: int,
 *   skipped_deleted_course: int
 * } Counts of actions taken during the sweep.
 */
function block_smartsection_control_check_sections_visibility(): array {
    global $DB;

    $stats = [
        'orphaned_rules'           => 0,
        'orphaned_history'         => 0,
        'orphaned_pacing'          => 0,
        'enforced'                 => 0,
        'skipped_manual'           => 0,
        'skipped_missing_section'  => 0,
        'skipped_deleted_course'   => 0,
    ];

    // -------------------------------------------------------------------
    // Garbage collection: delete records for deleted courses or sections.
    // -------------------------------------------------------------------

    // Count before so we can report how many were removed.
    $stats['orphaned_rules'] = (int) $DB->count_records_sql(
        "SELECT COUNT(*) FROM {block_smartsection_control}
          WHERE courseid NOT IN (SELECT id FROM {course})
             OR sectionid NOT IN (SELECT id FROM {course_sections})"
    );
    if ($stats['orphaned_rules'] > 0) {
        $DB->execute("
            DELETE FROM {block_smartsection_control}
             WHERE courseid NOT IN (SELECT id FROM {course})
                OR sectionid NOT IN (SELECT id FROM {course_sections})
        ");
    }

    $stats['orphaned_history'] = (int) $DB->count_records_sql(
        "SELECT COUNT(*) FROM {block_smartsection_control_h}
          WHERE courseid NOT IN (SELECT id FROM {course})
             OR sectionid NOT IN (SELECT id FROM {course_sections})"
    );
    if ($stats['orphaned_history'] > 0) {
        $DB->execute("
            DELETE FROM {block_smartsection_control_h}
             WHERE courseid NOT IN (SELECT id FROM {course})
                OR sectionid NOT IN (SELECT id FROM {course_sections})
        ");
    }

    $stats['orphaned_pacing'] = (int) $DB->count_records_sql(
        "SELECT COUNT(*) FROM {block_smartsection_control_u}
          WHERE courseid NOT IN (SELECT id FROM {course})
             OR sectionid NOT IN (SELECT id FROM {course_sections})"
    );
    if ($stats['orphaned_pacing'] > 0) {
        $DB->execute("
            DELETE FROM {block_smartsection_control_u}
             WHERE courseid NOT IN (SELECT id FROM {course})
                OR sectionid NOT IN (SELECT id FROM {course_sections})
        ");
    }

    // -------------------------------------------------------------------
    // Visibility enforcement sweep (shared state mutations live here, not on page views).
    // -------------------------------------------------------------------
    $lockfactory = \core\lock\lock_config::get_lock_factory('block_smartsection_control');
    $records = $DB->get_records_select(
        'block_smartsection_control',
        'manualoverride = 0 OR manualoverride IS NULL'
    );
    $now = time();
    $dirtycourses = [];

    foreach ($records as $record) {
        if (!empty($record->manualoverride)) {
            $stats['skipped_manual']++;
            continue;
        }

        try {
            $course = get_course((int) $record->courseid);
        } catch (\moodle_exception $e) {
            $stats['skipped_deleted_course']++;
            continue;
        }

        $sectionrec = $DB->get_record('course_sections', ['id' => $record->sectionid]);
        if (!$sectionrec) {
            $stats['skipped_missing_section']++;
            continue;
        }

        try {
            $modinfo = get_fast_modinfo((int) $record->courseid);
            $sectioninfo = $modinfo->get_section_info((int) $sectionrec->section, IGNORE_MISSING);
        } catch (\Throwable $e) {
            $stats['skipped_missing_section']++;
            continue;
        }
        if (!$sectioninfo) {
            $stats['skipped_missing_section']++;
            continue;
        }

        $lockkey = 'course_' . (int) $record->courseid . '_section_' . (int) $record->sectionid;
        $lock = $lockfactory->get_lock($lockkey, 10);
        if (!$lock) {
            continue;
        }

        try {
            // Soft + completion is unsupported: coerce to Hard Lock.
            if (($record->unlocktype ?? '') === 'event' && ($record->locktype ?? 'hard') === 'soft') {
                $DB->set_field('block_smartsection_control', 'locktype', 'hard', ['id' => (int) $record->id]);
                $record->locktype = 'hard';
            }

            if (($record->unlocktype ?? '') === 'event') {
                // Hard + event: keep hidden until manual override / per-user access paths.
                $visible = false;
            } else {
                $unlocktime = \block_smartsection_control\helper::calculate_unlock_time($record, $course);
                $visible = ($unlocktime && $unlocktime <= $now);
            }

            $changed = block_smartsection_control_enforce_lock_state(
                (int) $record->courseid,
                $sectioninfo,
                $record,
                $visible,
                0,
                false
            );
            if ($changed) {
                $dirtycourses[(int) $record->courseid] = true;
            }
            $stats['enforced']++;
        } finally {
            $lock->release();
        }
    }

    foreach (array_keys($dirtycourses) as $courseid) {
        rebuild_course_cache($courseid, true);
    }

    return $stats;
}

/**
 * Immediately unlock a section regardless of its scheduled time.
 *
 * Sets the manualoverride flag on the configuration record, marks the section
 * visible, and writes an audit history entry.
 *
 * @param int $sectionid The course_sections.id to unlock.
 * @param int $courseid  The course ID.
 * @param int $userid    The ID of the user performing the action.
 * @return bool True on success, false if no configuration record exists.
 */
function block_smartsection_control_manual_unlock(int $sectionid, int $courseid, int $userid): bool {
    global $DB;

    $courseid = \block_smartsection_control\helper::require_course_id($courseid);
    $sectionid = \block_smartsection_control\helper::require_section_id($sectionid, $courseid);

    $record = $DB->get_record('block_smartsection_control', ['sectionid' => $sectionid, 'courseid' => $courseid]);
    if (!$record) {
        return false;
    }

    $sectioninfo = \block_smartsection_control\helper::get_section_info_by_section_id($courseid, $sectionid);

    $lockfactory = \core\lock\lock_config::get_lock_factory('block_smartsection_control');
    $lock = $lockfactory->get_lock('course_' . $courseid . '_section_' . $sectionid, 10);
    if (!$lock) {
        return false;
    }

    try {
        $record->manualoverride = 1;
        $record->timemodified   = time();
        $DB->update_record('block_smartsection_control', $record);

        // Remove Soft Lock marker only; preserve other Moodle restrictions.
        $changed = \block_smartsection_control\helper::strip_soft_lock_availability($sectionid);

        // Only force visibility when Hard Lock owned the hidden state.
        if (($record->locktype ?? 'hard') === 'hard') {
            $changed = \block_smartsection_control\helper::set_section_visibility(
                $courseid,
                $sectioninfo,
                true,
                false
            ) || $changed;
        }

        if ($changed) {
            rebuild_course_cache($courseid, true);
        }

        \block_smartsection_control\helper::log_history(
            $sectionid,
            $courseid,
            'unlocked',
            'manual',
            $userid,
            'locked',
            'unlocked'
        );
        \block_smartsection_control\helper::log_rule_history(
            $sectionid,
            $courseid,
            'updated',
            'manual_unlock',
            $userid,
            $record
        );
    } finally {
        $lock->release();
    }

    return true;
}

/**
 * Add SmartSection Control options to the course reset form.
 *
 * Moodle calls this function during the course reset form build to allow
 * plugins to add their own reset options. Teachers can choose which
 * SmartSection Control data to clear when resetting a course for a new cohort.
 *
 * @param MoodleQuickForm $mform The reset form instance.
 */
function block_smartsection_control_reset_course_form_definition(MoodleQuickForm &$mform): void {
    $mform->addElement('header', 'smartsectioncontrolheader', get_string('pluginname', 'block_smartsection_control'));

    $mform->addElement('checkbox', 'reset_smartsection_history',
        get_string('reset_history', 'block_smartsection_control'));
    $mform->addHelpButton('reset_smartsection_history', 'reset_history', 'block_smartsection_control');

    $mform->addElement('checkbox', 'reset_smartsection_pacing',
        get_string('reset_pacing', 'block_smartsection_control'));
    $mform->addHelpButton('reset_smartsection_pacing', 'reset_pacing', 'block_smartsection_control');
}

/**
 * Return the default values for the course reset form fields added by this plugin.
 *
 * @param stdClass $course The course object.
 * @return array Associative array of field_name => default_value.
 */
function block_smartsection_control_reset_course_form_defaults(stdClass $course): array {
    return [
        'reset_smartsection_history' => 1,
        'reset_smartsection_pacing'  => 1,
    ];
}

/**
 * Perform the actual data reset when a course is reset.
 *
 * Moodle calls this function after the teacher submits the course reset form.
 * Depending on which checkboxes were checked, this purges audit history and/or
 * per-student pacing unlock timestamps for the course, leaving section schedule
 * configuration intact so teachers do not need to reconfigure unlock dates.
 *
 * @param stdClass $data The reset data submitted from the form (contains course id and checkbox values).
 * @return array An array of status objects (required by Moodle's reset API).
 */
function block_smartsection_control_reset_course_userdata(stdClass $data): array {
    global $DB;

    $status = [];

    if (!empty($data->reset_smartsection_history)) {
        $DB->delete_records('block_smartsection_control_h', ['courseid' => $data->courseid]);
        $status[] = [
            'component' => get_string('pluginname', 'block_smartsection_control'),
            'item'      => get_string('reset_history', 'block_smartsection_control'),
            'error'     => false,
        ];
    }

    if (!empty($data->reset_smartsection_pacing)) {
        $DB->delete_records('block_smartsection_control_u', ['courseid' => $data->courseid]);
        $status[] = [
            'component' => get_string('pluginname', 'block_smartsection_control'),
            'item'      => get_string('reset_pacing', 'block_smartsection_control'),
            'error'     => false,
        ];
    }

    return $status;
}

/**
 * Return the calendar event icon for SmartSection Control release events.
 *
 * Moodle Calendar calls this callback when exporting calendar events to determine
 * the component icon. Returns a pix_icon for 'sectionrelease' from this plugin.
 *
 * @param \calendar_event $event The calendar event object.
 * @return \pix_icon
 */
function block_smartsection_control_core_calendar_get_event_icon(\calendar_event $event): \pix_icon {
    return new \pix_icon(
        'sectionrelease',
        get_string('sectionrelease', 'block_smartsection_control'),
        'block_smartsection_control'
    );
}