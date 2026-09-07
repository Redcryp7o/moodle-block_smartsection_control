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
 * SmartSection Control Management Page
 *
 * Full-page interface for managing section unlock dates
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$courseid = \block_smartsection_control\helper::course_id_from($course);
require_login($course);
$context = context_course::instance($courseid);
$courseformat = course_get_format($course);

require_capability('block/smartsection_control:manage', $context);

$PAGE->set_url(new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'block_smartsection_control'));
$PAGE->set_heading(format_string($course->fullname) . ' - ' . get_string('manageunlockdates', 'block_smartsection_control'));

// Note: $DB, $OUTPUT, $USER, $PAGE are available at file scope after Moodle bootstrap;
// no global declaration is needed outside of function/method bodies.

// Handle email landing parameters to delay section unlock.
$delaysection = optional_param('delaysection', 0, PARAM_INT);
$delaydays = optional_param('days', 0, PARAM_INT);
$delayuserid = optional_param('userid', 0, PARAM_INT);
$delaytoken = optional_param('token', '', PARAM_ALPHANUM);

if ($delaysection && $delaydays > 0 && $delaydays <= 7) {
    $authorized = false;
    $record = $DB->get_record('block_smartsection_control', ['sectionid' => $delaysection]);
    if (
        !$record || (int) $record->courseid !== (int) $course->id
            || (int) $record->unlocktime <= 0 || $record->unlocktype !== 'absolute'
    ) {
        redirect(
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id]),
            get_string('invalid_delay_request', 'block_smartsection_control'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // The confirm_sesskey(false) call performs a soft sesskey check: returns false instead of
    // throwing an exception when the key is invalid. This allows the delay link to
    // fall through to the HMAC token check below when accessed from an email client
    // that does not carry the Moodle session (which always lacks a valid sesskey).
    if (confirm_sesskey(false)) {
        $authorized = true;
    } else if ($delayuserid && $delaytoken && (int) $delayuserid === (int) $USER->id) {
        if (
            \block_smartsection_control\helper::verify_delay_token(
                $delayuserid,
                $delaysection,
                $delaydays,
                (int) $record->unlocktime,
                $delaytoken
            )
        ) {
            $authorized = true;
        }
    }

    if ($authorized) {
        // Require an explicit confirm step so GET prefetch cannot mutate state.
        $delayconfirm = optional_param('confirm', 0, PARAM_BOOL);
        if (!$delayconfirm || !data_submitted()) {
            $confirmurl = new moodle_url('/blocks/smartsection_control/manage.php', [
                'id' => $course->id,
                'delaysection' => $delaysection,
                'days' => $delaydays,
                'userid' => $delayuserid ?: $USER->id,
                'token' => $delaytoken,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string('delay_x_days', 'block_smartsection_control', $delaydays),
                new single_button($confirmurl, get_string('confirm'), 'post'),
                new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id])
            );
            echo $OUTPUT->footer();
            exit;
        }

        // Re-check capability at mutation time (capability may have been revoked since email).
        require_capability('block/smartsection_control:manage', $context);

        $oldtime = $record->unlocktime;
        $record->unlocktime += ($delaydays * DAYSECS);
        $record->notifysentfor = 0;
        $record->timemodified = time();
        $DB->update_record('block_smartsection_control', $record);

        $now = time();
        $visible = ($record->unlocktime <= $now);
        $sectioninfo = \block_smartsection_control\helper::get_section_info_by_section_id($courseid, $delaysection);

        block_smartsection_control_enforce_lock_state(
            $courseid,
            $sectioninfo,
            $record,
            $visible,
            (int) $USER->id
        );

        // Create/update calendar event.
        $sectionname = \block_smartsection_control\helper::get_section_display_name($course, $delaysection);
        \block_smartsection_control\calendar::create_unlock_event(
            $delaysection,
            $courseid,
            (int) $record->unlocktime,
            $sectionname
        );

        \block_smartsection_control\helper::log_rule_history(
            $delaysection,
            $courseid,
            'updated',
            'pacing_delay',
            (int) $USER->id,
            $record,
            ['delay_days' => $delaydays, 'old_time' => $oldtime, 'new_time' => (int) $record->unlocktime]
        );

        $message = get_string('delay_success', 'block_smartsection_control', (object)[
            'days' => $delaydays,
            'date' => userdate($record->unlocktime, get_string('strftimedatefullshort', 'langconfig')),
        ]);
        redirect(
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id]),
            $message,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect(
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id]),
            get_string('invalid_delay_request', 'block_smartsection_control'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}


// Register AMD module for the unlock-type selector show/hide logic and
// confirmation prompts. Moodle loads the plugin styles.css automatically.
$PAGE->requires->js_call_amd('block_smartsection_control/manage', 'init');

// Get all course sections (except general).
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();

// Handle bulk actions â€” POST + sesskey only (never mutate via GET).
$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
if ($bulkaction !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !confirm_sesskey()) {
        throw new \moodle_exception('invalidsesskey', 'error');
    }

    // Moodle-native confirmation for high-impact destructive bulk actions.
    if (
        in_array($bulkaction, ['unlockall', 'clearall'], true)
            && !optional_param('confirm', 0, PARAM_BOOL)
    ) {
        $managedcount = $DB->count_records('block_smartsection_control', ['courseid' => $courseid]);
        $confirmurl = new moodle_url('/blocks/smartsection_control/manage.php', [
            'id' => $course->id,
            'bulkaction' => $bulkaction,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]);
        $message = ($bulkaction === 'unlockall')
            ? get_string('unlockallconfirm', 'block_smartsection_control', $managedcount)
            : get_string('clearallconfirm', 'block_smartsection_control');
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            $message,
            new single_button($confirmurl, get_string('confirm'), 'post'),
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id])
        );
        echo $OUTPUT->footer();
        exit;
    }

    $processed = 0;

    foreach ($sections as $section) {
        if (!\block_smartsection_control\helper::is_valid_section_info($section)) {
            continue;
        }

        $sid = \block_smartsection_control\helper::resolve_section_id_from_info($section, $courseid);

        if ($bulkaction === 'lockall') {
            $lockdate = optional_param('lockalldate', '', PARAM_RAW_TRIMMED);
            $locktype = optional_param('lockalllocktype', 'hard', PARAM_ALPHA);
            if ($locktype !== 'soft') {
                $locktype = 'hard';
            }
            if ($lockdate) {
                $unlocktime = \block_smartsection_control\helper::parse_user_datetime($lockdate);
                if ($unlocktime !== false) {
                    $wasexisting = false;
                    if ($record = $DB->get_record('block_smartsection_control', ['sectionid' => $sid])) {
                        $wasexisting = true;
                        $record->unlocktime = $unlocktime;
                        $record->unlocktype = 'absolute';
                        $record->locktype = $locktype;
                        $record->relativesettings = null;
                        $record->eventconditions = null;
                        $record->manualoverride = 0;
                        $record->timemodified = time();
                        $DB->update_record('block_smartsection_control', $record);
                    } else {
                        $record = (object) [
                            'courseid' => $courseid,
                            'sectionid' => $sid,
                            'unlocktime' => $unlocktime,
                            'unlocktype' => 'absolute',
                            'locktype' => $locktype,
                            'timecreated' => time(),
                            'timemodified' => time(),
                            'timezone' => 'server',
                        ];
                        $record->id = $DB->insert_record('block_smartsection_control', $record);
                    }
                    $visible = ($unlocktime <= time());
                    block_smartsection_control_enforce_lock_state(
                        $courseid,
                        $section,
                        $record,
                        $visible,
                        (int) $USER->id,
                        false
                    );
                    $sectionname = \block_smartsection_control\helper::get_section_display_name_from_info($course, $section);
                    \block_smartsection_control\calendar::create_unlock_event($sid, $courseid, (int) $unlocktime, $sectionname);
                    \block_smartsection_control\helper::log_rule_history(
                        $sid,
                        $courseid,
                        $wasexisting ? 'updated' : 'created',
                        'bulk_lockall',
                        (int) $USER->id,
                        $record
                    );
                    $processed++;
                }
            }
        } else if ($bulkaction === 'unlockall') {
            // Remove SmartSection rules. Force visible only when Hard Lock owned hiding.
            $existing = $DB->get_record('block_smartsection_control', ['sectionid' => $sid]);
            $forcevisible = $existing && (($existing->locktype ?? 'hard') === 'hard');
            \block_smartsection_control\helper::strip_soft_lock_availability($sid);
            $DB->delete_records('block_smartsection_control', ['sectionid' => $sid]);
            \block_smartsection_control\calendar::delete_unlock_event($sid, $courseid);
            if ($forcevisible) {
                \block_smartsection_control\helper::set_section_visibility($courseid, $section, true, false);
            }
            if ($existing) {
                \block_smartsection_control\helper::log_rule_history(
                    $sid,
                    $courseid,
                    'cleared',
                    'bulk_unlockall',
                    (int) $USER->id,
                    $existing
                );
            }
            $processed++;
        } else if ($bulkaction === 'clearall') {
            // Remove SmartSection rules only; do not force visibility changes.
            $existing = $DB->get_record('block_smartsection_control', ['sectionid' => $sid]);
            \block_smartsection_control\helper::strip_soft_lock_availability($sid);
            $DB->delete_records('block_smartsection_control', ['sectionid' => $sid]);
            \block_smartsection_control\calendar::delete_unlock_event($sid, $courseid);
            if ($existing) {
                \block_smartsection_control\helper::log_rule_history(
                    $sid,
                    $courseid,
                    'cleared',
                    'bulk_clearall',
                    (int) $USER->id,
                    $existing
                );
            }
            $processed++;
        } else if ($bulkaction === 'shiftdates') {
            $shiftdays = optional_param('shiftdays', 0, PARAM_INT);
            $skipweekendsholidays = optional_param('skip_weekends_holidays', 0, PARAM_INT) ? true : false;
            if ($shiftdays != 0) {
                $record = $DB->get_record('block_smartsection_control', ['sectionid' => $sid]);
                if ($record && $record->unlocktime > 0 && $record->unlocktype === 'absolute') {
                    $record->unlocktime = \block_smartsection_control\helper::shift_date_excluding_weekends(
                        (int) $record->unlocktime,
                        $shiftdays,
                        $skipweekendsholidays
                    );
                    $record->timemodified = time();
                    $DB->update_record('block_smartsection_control', $record);
                    $visible = ($record->unlocktime <= time());
                    block_smartsection_control_enforce_lock_state(
                        $courseid,
                        $section,
                        $record,
                        $visible,
                        (int) $USER->id,
                        false
                    );
                    $sectionname = \block_smartsection_control\helper::get_section_display_name_from_info($course, $section);
                    \block_smartsection_control\calendar::create_unlock_event(
                        $sid,
                        $courseid,
                        (int) $record->unlocktime,
                        $sectionname
                    );
                    \block_smartsection_control\helper::log_rule_history(
                        $sid,
                        $courseid,
                        'updated',
                        'bulk_shiftdates',
                        (int) $USER->id,
                        $record
                    );
                    $processed++;
                }
            }
        } else if ($bulkaction === 'applyinterval') {
            $intervaldays = optional_param('intervaldays', 0, PARAM_INT);
            $startdate = optional_param('intervalstart', '', PARAM_RAW_TRIMMED);
            if ($intervaldays > 0 && $startdate) {
                $starttime = \block_smartsection_control\helper::parse_user_datetime($startdate);
                if ($starttime !== false) {
                    $sectionnum = (int) $section->section;
                    if ($sectionnum > 0) {
                        $unlocktime = $starttime + (($sectionnum - 1) * $intervaldays * DAYSECS);
                        $wasexisting = false;
                        if ($record = $DB->get_record('block_smartsection_control', ['sectionid' => $sid])) {
                            $wasexisting = true;
                            $record->unlocktime = $unlocktime;
                            $record->unlocktype = 'absolute';
                            $record->relativesettings = null;
                            $record->eventconditions = null;
                            $record->manualoverride = 0;
                            $record->timemodified = time();
                            if (empty($record->locktype)) {
                                $record->locktype = 'hard';
                            }
                            $DB->update_record('block_smartsection_control', $record);
                        } else {
                            $record = (object) [
                                'courseid' => $course->id,
                                'sectionid' => $sid,
                                'unlocktime' => $unlocktime,
                                'unlocktype' => 'absolute',
                                'locktype' => 'hard',
                                'timecreated' => time(),
                                'timemodified' => time(),
                                'timezone' => 'server',
                            ];
                            $record->id = $DB->insert_record('block_smartsection_control', $record);
                        }
                        $visible = ($unlocktime <= time());
                        block_smartsection_control_enforce_lock_state(
                            $courseid,
                            $section,
                            $record,
                            $visible,
                            (int) $USER->id,
                            false
                        );
                        $sectionname = \block_smartsection_control\helper::get_section_display_name_from_info($course, $section);
                        \block_smartsection_control\calendar::create_unlock_event(
                            $sid,
                            $courseid,
                            (int) $unlocktime,
                            $sectionname
                        );
                        \block_smartsection_control\helper::log_rule_history(
                            $sid,
                            $courseid,
                            $wasexisting ? 'updated' : 'created',
                            'bulk_applyinterval',
                            (int) $USER->id,
                            $record
                        );
                        $processed++;
                    }
                }
            }
        }
    }

    $message = '';
    if ($bulkaction === 'lockall') {
        $message = get_string('alllocked', 'block_smartsection_control');
    } else if ($bulkaction === 'unlockall') {
        $message = get_string('allunlocked', 'block_smartsection_control');
    } else if ($bulkaction === 'clearall') {
        $message = get_string('allcleared', 'block_smartsection_control');
    } else if ($bulkaction === 'shiftdates') {
        $message = get_string('dates_shifted', 'block_smartsection_control', $processed);
    } else if ($bulkaction === 'applyinterval') {
        $message = get_string('interval_applied', 'block_smartsection_control', $processed);
    }

    if ($message) {
        rebuild_course_cache($courseid, true);
        redirect(
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]),
            $message,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Handle manual unlock â€” must run before Save (Unlock Now POST must not trigger Save).
$manualunlock = optional_param('manualunlock', 0, PARAM_INT);
if ($manualunlock) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !confirm_sesskey()) {
        throw new \moodle_exception('invalidsesskey', 'error');
    }
    if (!block_smartsection_control_manual_unlock($manualunlock, $courseid, (int) $USER->id)) {
        throw new \moodle_exception('invalidsection', 'error');
    }
    redirect(
        new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]),
        get_string('sectionunlocked', 'block_smartsection_control'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Handle individual section saves â€” explicit action=save only (not Unlock Now / bulk).
// NOTE: Do not detect Save via name=save + PARAM_BOOL. A <button name="save"> without
// value= submits save="" which PARAM_BOOL treats as false â€” Save silently did nothing.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'save') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new \moodle_exception('invalidrequest', 'error');
    }
    require_sesskey();

    $now = time();
    $pending = [];
    $calendarops = [];

    foreach ($sections as $section) {
        if (!\block_smartsection_control\helper::is_valid_section_info($section)) {
            continue;
        }

        $sectionid = \block_smartsection_control\helper::resolve_section_id_from_info($section, $courseid);
        $sectionnum = (int) $section->section;
        $sectionname = \block_smartsection_control\helper::get_section_display_name_from_info($course, $section);

        $unlocktype = optional_param("unlocktype_{$sectionid}", 'absolute', PARAM_ALPHA);
        $rec = $DB->get_record('block_smartsection_control', ['sectionid' => $sectionid, 'courseid' => $courseid]);

        $datestr = optional_param("unlock_{$sectionid}", '', PARAM_RAW_TRIMMED);

        // Fixed date with no date entered = not scheduled (same as No release rule).
        // Allows Fixed date as the default dropdown without forcing every row to validate.
        if ($unlocktype === 'absolute' && $datestr === '') {
            $unlocktype = 'none';
        }

        if ($unlocktype === 'none' || $unlocktype === '') {
            if ($rec) {
                $pending[] = [
                    'type' => 'clear',
                    'section' => $section,
                    'sectionid' => $sectionid,
                    'sectionnum' => $sectionnum,
                    'record' => $rec,
                    'washard' => (($rec->locktype ?? 'hard') === 'hard'),
                ];
                $calendarops[] = ['delete', $sectionid, $courseid];
            }
            continue;
        }

        // Read relative days as raw string so "0" is distinct from missing/invalid.
        $relativedaysraw = optional_param("relativedays_{$sectionid}", '', PARAM_RAW_TRIMMED);
        $relativebase = optional_param("relativebase_{$sectionid}", 'start', PARAM_ALPHA);
        $locktype = optional_param("locktype_{$sectionid}", 'hard', PARAM_ALPHA);
        if ($locktype !== 'soft') {
            $locktype = 'hard';
        }
        if (
            $unlocktype === 'event'
                || !\block_smartsection_control\helper::soft_lock_allowed_for_unlocktype($unlocktype)
        ) {
            $locktype = 'hard';
        }

        $eventactivity = optional_param("event_activity_{$sectionid}", 0, PARAM_INT);
        $eventdelay = optional_param("event_delay_{$sectionid}", 0, PARAM_INT);
        $eventgradeactivity = optional_param("event_grade_activity_{$sectionid}", 0, PARAM_INT);
        $eventgrademinimum = optional_param("event_grade_minimum_{$sectionid}", '', PARAM_FLOAT);

        if ($unlocktype === 'absolute') {
            // HTML <input type="date"> submits YYYY-MM-DD (not localized display).
            $unlocktime = \block_smartsection_control\helper::parse_user_datetime($datestr);
            if ($unlocktime === false) {
                throw new \moodle_exception('invaliddate', 'block_smartsection_control', '', $sectionname);
            }
            $pending[] = [
                'type' => 'absolute',
                'section' => $section,
                'sectionid' => $sectionid,
                'sectionname' => $sectionname,
                'record' => $rec,
                'wasexisting' => (bool) $rec,
                'unlocktime' => (int) $unlocktime,
                'locktype' => $locktype,
                'visible' => ((int) $unlocktime <= $now),
            ];
            $calendarops[] = ['create', $sectionid, $courseid, (int) $unlocktime, $sectionname];
        } else if ($unlocktype === 'relative') {
            if ($relativedaysraw === '' || !ctype_digit($relativedaysraw)) {
                throw new \moodle_exception('invalidrelative', 'block_smartsection_control', '', $sectionname);
            }
            $relativedays = (int) $relativedaysraw;
            if ($relativedays < 0) {
                throw new \moodle_exception('invalidrelative', 'block_smartsection_control', '', $sectionname);
            }
            if ($relativebase === 'prev_section') {
                $settings = json_encode(['days_after_prev_section' => $relativedays]);
            } else {
                $settings = json_encode(['days_after_start' => $relativedays]);
            }
            $pending[] = [
                'type' => 'relative',
                'section' => $section,
                'sectionid' => $sectionid,
                'sectionname' => $sectionname,
                'record' => $rec,
                'wasexisting' => (bool) $rec,
                'settings' => $settings,
                'locktype' => $locktype,
            ];
            $calendarops[] = ['delete', $sectionid, $courseid];
        } else if ($unlocktype === 'event') {
            if (!($eventactivity > 0 || ($eventgradeactivity > 0 && $eventgrademinimum !== '' && $eventgrademinimum > 0))) {
                throw new \moodle_exception('invalidevent', 'block_smartsection_control', '', $sectionname);
            }
            if ($eventactivity > 0 && !$DB->record_exists('course_modules', ['id' => $eventactivity, 'course' => $courseid])) {
                throw new \moodle_exception('invalidcmid', 'error');
            }
            if (
                $eventgradeactivity > 0
                && !$DB->record_exists('course_modules', ['id' => $eventgradeactivity, 'course' => $courseid])
            ) {
                throw new \moodle_exception('invalidcmid', 'error');
            }
            $conditions = [];
            if ($eventactivity > 0) {
                $conditions['activity_completion'] = (int) $eventactivity;
                $conditions['pacing_delay_days'] = (int) $eventdelay;
            }
            if ($eventgradeactivity > 0 && $eventgrademinimum !== '' && $eventgrademinimum > 0) {
                $conditions['grade_threshold'] = [
                    'cmid' => (int) $eventgradeactivity,
                    'minimum' => (float) $eventgrademinimum,
                ];
            }
            $pending[] = [
                'type' => 'event',
                'section' => $section,
                'sectionid' => $sectionid,
                'record' => $rec,
                'wasexisting' => (bool) $rec,
                'conditionsjson' => json_encode($conditions),
                'locktype' => $locktype,
            ];
            $calendarops[] = ['delete', $sectionid, $courseid];
        }
    }

    // Genuine no-op (e.g. all rows still "No release rule" with nothing to clear).
    if (empty($pending)) {
        redirect(
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]),
            get_string('schedules_nochange', 'block_smartsection_control'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Validate-all already done above; mutate inside one delegated transaction.
    // Do not catch Throwable here â€” Moodle must surface the real error; the
    // delegated transaction rolls back automatically when an exception escapes.
    $transaction = $DB->start_delegated_transaction();
    foreach ($pending as $item) {
        if ($item['type'] === 'absolute') {
            $rec = $item['record'];
            if ($rec) {
                $rec->unlocktime = $item['unlocktime'];
                $rec->unlocktype = 'absolute';
                $rec->locktype = $item['locktype'];
                $rec->relativesettings = null;
                $rec->eventconditions = null;
                $rec->manualoverride = 0;
                $rec->notifysentfor = 0;
                $rec->timemodified = time();
                $DB->update_record('block_smartsection_control', $rec);
            } else {
                $rec = (object) [
                    'courseid' => $courseid,
                    'sectionid' => $item['sectionid'],
                    'unlocktime' => $item['unlocktime'],
                    'unlocktype' => 'absolute',
                    'locktype' => $item['locktype'],
                    'timecreated' => time(),
                    'timemodified' => time(),
                    'timezone' => 'server',
                ];
                $rec->id = $DB->insert_record('block_smartsection_control', $rec);
            }
            block_smartsection_control_enforce_lock_state(
                $courseid,
                $item['section'],
                $rec,
                $item['visible'],
                (int) $USER->id,
                false
            );
            \block_smartsection_control\helper::log_rule_history(
                $item['sectionid'],
                $courseid,
                $item['wasexisting'] ? 'updated' : 'created',
                'manage_save',
                (int) $USER->id,
                $rec
            );
        } else if ($item['type'] === 'relative') {
            $rec = $item['record'];
            if ($rec) {
                $rec->unlocktype = 'relative';
                $rec->locktype = $item['locktype'];
                $rec->relativesettings = $item['settings'];
                $rec->eventconditions = null;
                $rec->unlocktime = 0;
                $rec->manualoverride = 0;
                $rec->timemodified = time();
                $DB->update_record('block_smartsection_control', $rec);
            } else {
                $rec = (object) [
                    'courseid' => $courseid,
                    'sectionid' => $item['sectionid'],
                    'unlocktype' => 'relative',
                    'locktype' => $item['locktype'],
                    'relativesettings' => $item['settings'],
                    'unlocktime' => 0,
                    'timecreated' => time(),
                    'timemodified' => time(),
                    'timezone' => 'server',
                ];
                $rec->id = $DB->insert_record('block_smartsection_control', $rec);
            }
            $unlocktime = \block_smartsection_control\helper::calculate_unlock_time($rec, $course);
            $visible = ($unlocktime && $unlocktime <= $now);
            block_smartsection_control_enforce_lock_state(
                $courseid,
                $item['section'],
                $rec,
                (bool) $visible,
                (int) $USER->id,
                false
            );
            \block_smartsection_control\helper::log_rule_history(
                $item['sectionid'],
                $courseid,
                $item['wasexisting'] ? 'updated' : 'created',
                'manage_save',
                (int) $USER->id,
                $rec
            );
        } else if ($item['type'] === 'event') {
            $rec = $item['record'];
            if ($rec) {
                $rec->unlocktype = 'event';
                $rec->locktype = $item['locktype'];
                $rec->relativesettings = null;
                $rec->eventconditions = $item['conditionsjson'];
                $rec->unlocktime = 0;
                $rec->manualoverride = 0;
                $rec->timemodified = time();
                $DB->update_record('block_smartsection_control', $rec);
            } else {
                $rec = (object) [
                    'courseid' => $courseid,
                    'sectionid' => $item['sectionid'],
                    'unlocktype' => 'event',
                    'locktype' => $item['locktype'],
                    'eventconditions' => $item['conditionsjson'],
                    'unlocktime' => 0,
                    'timecreated' => time(),
                    'timemodified' => time(),
                    'timezone' => 'server',
                ];
                $rec->id = $DB->insert_record('block_smartsection_control', $rec);
            }
            block_smartsection_control_enforce_lock_state(
                $courseid,
                $item['section'],
                $rec,
                false,
                (int) $USER->id,
                false
            );
            \block_smartsection_control\helper::log_rule_history(
                $item['sectionid'],
                $courseid,
                $item['wasexisting'] ? 'updated' : 'created',
                'manage_save',
                (int) $USER->id,
                $rec
            );
        } else if ($item['type'] === 'clear') {
            \block_smartsection_control\helper::strip_soft_lock_availability($item['sectionid']);
            $DB->delete_records('block_smartsection_control', [
                'sectionid' => $item['sectionid'],
                'courseid' => $courseid,
            ]);
            if ($item['washard']) {
                \block_smartsection_control\helper::set_section_visibility(
                    $courseid,
                    $item['section'],
                    true,
                    false
                );
            }
            \block_smartsection_control\helper::log_rule_history(
                $item['sectionid'],
                $courseid,
                'cleared',
                'manage_save',
                (int) $USER->id,
                $item['record']
            );
        }
    }
    $transaction->allow_commit();

    // Calendar sync is secondary â€” never roll back a successful schedule persist.
    $calendarfailures = 0;
    foreach ($calendarops as $op) {
        try {
            if ($op[0] === 'create') {
                \block_smartsection_control\calendar::create_unlock_event($op[1], $op[2], $op[3], $op[4]);
            } else {
                \block_smartsection_control\calendar::delete_unlock_event($op[1], $op[2]);
            }
        } catch (\Throwable $e) {
            $calendarfailures++;
            debugging('SmartSection calendar sync failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    rebuild_course_cache($courseid, true);

    $message = get_string('schedules_saved', 'block_smartsection_control');
    $level = \core\output\notification::NOTIFY_SUCCESS;
    if ($calendarfailures > 0) {
        $message .= ' ' . get_string('calendar_sync_warning', 'block_smartsection_control', $calendarfailures);
        $level = \core\output\notification::NOTIFY_WARNING;
    }

    redirect(
        new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]),
        $message,
        null,
        $level
    );
}

// -------------------------------------------------------------------------
// Template data preparation. All markup lives in the Mustache templates
// block_smartsection_control/manage_page and .../manage_bulk.

// Convert a value => label option map into Mustache-ready select options.
// Takes the option map keyed by submitted value plus the currently selected
// value, and returns a list of Mustache-ready value/label/selected rows.
$buildoptions = static function (array $options, $selected): array {
    $built = [];
    foreach ($options as $value => $label) {
        $built[] = [
            'value' => (string) $value,
            'label' => (string) $label,
            'selected' => ((string) $value === (string) $selected),
        ];
    }
    return $built;
};

// Warn teachers when a restored completion trigger lost its referenced activity.
$needsreviewcount = 0;
$reviewcandidates = $DB->get_records('block_smartsection_control', [
    'courseid' => $courseid,
    'unlocktype' => 'event',
]);
foreach ($reviewcandidates as $reviewrec) {
    $conditions = json_decode((string) ($reviewrec->eventconditions ?? ''), true);
    if (
        is_array($conditions) && !empty($conditions['restore_needs_review'])
            && empty($conditions['activity_completion'])
            && empty($conditions['grade_threshold'])
    ) {
        $needsreviewcount++;
    }
}

$completionactivities = [];
$gradedactivities = [];
foreach ($modinfo->get_cms() as $cm) {
    $sectionnum = isset($cm->sectionnum) ? (int) $cm->sectionnum : null;
    if ($sectionnum === null) {
        continue;
    }
    if ($cm->completion > 0) {
        $sectioninfo = $modinfo->get_section_info($sectionnum);
        $sectionname = $courseformat->get_section_name($sectioninfo);
        $completionactivities[$cm->id] = format_string($cm->name) . ' (' . $sectionname . ')';
    }
    if (plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE)) {
        $sectioninfo = $modinfo->get_section_info($sectionnum);
        $sectionname = $courseformat->get_section_name($sectioninfo);
        $gradedactivities[$cm->id] = format_string($cm->name) . ' (' . $sectionname . ')';
    }
}

$unlocktypelabels = [
    'absolute' => get_string('unlocktype_absolute', 'block_smartsection_control'),
    'relative' => get_string('unlocktype_relative', 'block_smartsection_control'),
    'event' => get_string('unlocktype_event', 'block_smartsection_control'),
    'none' => get_string('unlocktype_none', 'block_smartsection_control'),
];
$locktypelabels = [
    'hard' => get_string('locktype_hard', 'block_smartsection_control'),
    'soft' => get_string('locktype_soft', 'block_smartsection_control'),
];
$relativebaselabels = [
    'start' => get_string('relative_base_start', 'block_smartsection_control'),
    'prev_section' => get_string('relative_base_prev_section', 'block_smartsection_control'),
];

$sectioncount = 0;
$scheduledcount = 0;
$lockedcount = 0;
$sectionrows = [];
$unlockforms = [];

foreach ($sections as $section) {
    if ((int) $section->section === 0 || !\block_smartsection_control\helper::is_valid_section_info($section)) {
        continue;
    }

    $sectioncount++;

    $sectionid = \block_smartsection_control\helper::resolve_section_id_from_info($section, $courseid);
    $record = $DB->get_record('block_smartsection_control', ['sectionid' => $sectionid]);
    $name = $courseformat->get_section_name($section);
    $formattedname = format_string($name);

    $unlocktype = 'absolute';
    $locktype = 'hard';
    $current = '';
    $relativebase = 'start';
    $relativedays = '';
    $eventconditions = null;
    $selectedactivity = '';
    $pacingdelay = '0';
    $selectedgradeactivity = '';
    $grademinimum = '';

    if ($record) {
        $scheduledcount++;
        $unlocktype = $record->unlocktype ?: 'absolute';
        $locktype = $record->locktype ?? 'hard';
        if (!empty($record->unlocktime)) {
            $current = date('Y-m-d', (int) $record->unlocktime);
        }
        if ($record->relativesettings) {
            $relsettings = json_decode($record->relativesettings, true);
            if (is_array($relsettings)) {
                if (isset($relsettings['days_after_prev_section'])) {
                    $relativebase = 'prev_section';
                    $relativedays = $relsettings['days_after_prev_section'];
                } else if (isset($relsettings['days_after_start'])) {
                    $relativebase = 'start';
                    $relativedays = $relsettings['days_after_start'];
                }
            }
        }
        if ($record->eventconditions) {
            $eventconditions = json_decode($record->eventconditions, true);
            if (is_array($eventconditions)) {
                $selectedactivity = $eventconditions['activity_completion'] ?? '';
                $pacingdelay = $eventconditions['pacing_delay_days'] ?? '0';
                $selectedgradeactivity = $eventconditions['grade_threshold']['cmid'] ?? '';
                $grademinimum = $eventconditions['grade_threshold']['minimum'] ?? '';
            }
        }
    }

    $hasruleui = ($unlocktype !== 'none' && $unlocktype !== '');

    // Status badge.
    $hasstatus = false;
    $statuslabel = '';
    $statusmodifier = '';
    $statustitle = '';
    $needsreview = false;

    if ($record) {
        $statusinfo = \block_smartsection_control\helper::get_unlock_status($record, $course);
        if (($statusinfo['status'] ?? '') !== 'unlocked') {
            $lockedcount++;
        }
        $hasstatus = true;

        if ($statusinfo['status'] === 'unlocked') {
            $statuslabel = get_string('unlocked', 'block_smartsection_control');
            $statusmodifier = 'ssc-status--unlocked';
        } else if ($statusinfo['status'] === 'scheduled' && !empty($statusinfo['unlocktime'])) {
            $statuslabel = get_string(
                'locked_until',
                'block_smartsection_control',
                userdate((int) $statusinfo['unlocktime'], get_string('strftimedate', 'langconfig'))
            );
            $statusmodifier = 'ssc-status--scheduled';
        } else if (($record->unlocktype ?? '') === 'event') {
            $conditions = is_array($eventconditions) ? $eventconditions : [];
            if (
                !empty($conditions['restore_needs_review'])
                    && empty($conditions['activity_completion'])
                    && empty($conditions['grade_threshold'])
            ) {
                $needsreview = true;
                $statuslabel = get_string('status_needs_review', 'block_smartsection_control');
                $statusmodifier = 'ssc-status--warning';
                $statustitle = get_string('needs_review_help', 'block_smartsection_control');
            } else {
                $statuslabel = get_string('status_waiting_completion', 'block_smartsection_control');
                $statusmodifier = 'ssc-status--waiting';
            }
        } else if (($record->unlocktype ?? '') === 'relative') {
            $statuslabel = get_string('status_relative_release', 'block_smartsection_control');
            $statusmodifier = 'ssc-status--info';
        } else {
            $statuslabel = get_string('locked', 'block_smartsection_control');
            $statusmodifier = 'ssc-status--locked';
        }
    }

    // Collapsed rule summary.
    if (!$record) {
        $rulesummary = $unlocktypelabels['none'];
    } else if (($record->unlocktype ?? '') === 'absolute') {
        $rulesummary = $unlocktypelabels['absolute'];
        if (!empty($record->unlocktime)) {
            $rulesummary .= ' · ' . userdate((int) $record->unlocktime, get_string('strftimedate', 'langconfig'));
        }
        $rulesummary .= ' · ' . (($record->locktype ?? 'hard') === 'soft'
            ? get_string('locktype_soft_short', 'block_smartsection_control')
            : get_string('locktype_hard_short', 'block_smartsection_control'));
    } else if (($record->unlocktype ?? '') === 'relative') {
        $rulesummary = $unlocktypelabels['relative'];
        if ($relativedays !== '' && $relativedays !== null) {
            $rulesummary .= ' · ' . ($relativebase === 'prev_section'
                ? get_string('relative_days_after_prev_section', 'block_smartsection_control')
                : get_string('relative_days_after_start', 'block_smartsection_control'))
                . ': ' . $relativedays;
        }
    } else if (($record->unlocktype ?? '') === 'event') {
        $rulesummary = $unlocktypelabels['event'];
        if ($needsreview) {
            $rulesummary .= ' · ' . get_string('status_needs_review', 'block_smartsection_control');
        } else if (!empty($selectedactivity) && isset($completionactivities[(int) $selectedactivity])) {
            $rulesummary .= ' · ' . $completionactivities[(int) $selectedactivity];
        }
    } else {
        $rulesummary = $unlocktypelabels['none'];
    }

    $hasunlockbutton = ($record && empty($record->manualoverride));
    $unlockformid = 'ssc-manual-unlock-' . $sectionid;
    if ($hasunlockbutton) {
        $unlockforms[] = ['formid' => $unlockformid];
    }

    $sectionrows[] = [
        'sectionid' => $sectionid,
        'configured' => (bool) $record,
        'expanded' => $needsreview,
        'name' => $formattedname,
        'rulesummary' => $rulesummary,
        'hasstatus' => $hasstatus,
        'statuslabel' => $statuslabel,
        'statusmodifier' => $statusmodifier,
        'hasstatustitle' => ($statustitle !== ''),
        'statustitle' => $statustitle,
        'needsreview' => $needsreview,
        'unlocktypeoptions' => $buildoptions($unlocktypelabels, $unlocktype),
        'locktypeoptions' => $buildoptions($locktypelabels, $record ? $locktype : 'hard'),
        'locktypedisabled' => !$hasruleui,
        'showlocktype' => $hasruleui,
        'showeventnote' => ($unlocktype === 'event'),
        'showdate' => ($unlocktype === 'absolute'),
        'showrelative' => ($unlocktype === 'relative'),
        'showevent' => ($unlocktype === 'event'),
        'unlockdate' => $current,
        'relativebaseoptions' => $buildoptions($relativebaselabels, $relativebase),
        'relativedays' => (string) $relativedays,
        'hascompletionactivities' => !empty($completionactivities),
        'activityoptions' => $buildoptions(
            ['' => get_string('event_activity_completion', 'block_smartsection_control')] + $completionactivities,
            $selectedactivity
        ),
        'pacingdelay' => (string) $pacingdelay,
        'hasgradedactivities' => !empty($gradedactivities),
        'gradeactivityoptions' => $buildoptions(
            ['' => get_string('event_grade_activity', 'block_smartsection_control')] + $gradedactivities,
            $selectedgradeactivity
        ),
        'grademinimum' => (string) $grademinimum,
        'hasunlockbutton' => $hasunlockbutton,
        'unlockformid' => $unlockformid,
        'arialabels' => [
            'unlocktype' => get_string('unlocktype', 'block_smartsection_control') . ' — ' . $formattedname,
            'locktype' => get_string('locktype', 'block_smartsection_control') . ' — ' . $formattedname,
            'unlockdate' => get_string('unlockdate', 'block_smartsection_control') . ' — ' . $formattedname,
            'relativebase' => get_string('relative_base', 'block_smartsection_control') . ' — ' . $formattedname,
            'relativedays' => get_string('relative_days', 'block_smartsection_control') . ' — ' . $formattedname,
            'eventactivity' => get_string('event_activity_completion', 'block_smartsection_control')
                . ' — ' . $formattedname,
            'pacingdelay' => get_string('pacing_delay_days', 'block_smartsection_control') . ' — ' . $formattedname,
            'gradeactivity' => get_string('event_grade_activity', 'block_smartsection_control')
                . ' — ' . $formattedname,
            'grademinimum' => get_string('event_grade_minimum', 'block_smartsection_control') . ' — ' . $formattedname,
        ],
    ];
}

$formaction = $PAGE->url->out(false);

$templatedata = [
    'tabs' => \block_smartsection_control\output\navigation::tabs(
        $courseid,
        \block_smartsection_control\output\navigation::TAB_MANAGE
    ),
    'needsreviewnotification' => $needsreviewcount > 0
        ? $OUTPUT->notification(
            get_string('needs_review_banner', 'block_smartsection_control', $needsreviewcount),
            \core\output\notification::NOTIFY_WARNING
        )
        : '',
    'formaction' => $formaction,
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'stats' => [
        'total' => $sectioncount . ' ' . get_string('total_sections', 'block_smartsection_control'),
        'scheduled' => $scheduledcount . ' ' . get_string('scheduled_sections', 'block_smartsection_control'),
        'locked' => $lockedcount . ' ' . get_string('locked_sections', 'block_smartsection_control'),
    ],
    'sections' => $sectionrows,
    'unlockforms' => $unlockforms,
    'str' => [
        'sectionschedule' => get_string('section_schedule', 'block_smartsection_control'),
        'sectionscheduledesc' => get_string('section_schedule_desc', 'block_smartsection_control'),
        'quickstats' => get_string('quick_stats', 'block_smartsection_control'),
        'releaserule' => get_string('releaserule', 'block_smartsection_control'),
        'locktype' => get_string('locktype', 'block_smartsection_control'),
        'softlockeventforcedhard' => get_string('soft_lock_event_forced_hard', 'block_smartsection_control'),
        'unlockdate' => get_string('unlockdate', 'block_smartsection_control'),
        'relativebase' => get_string('relative_base', 'block_smartsection_control'),
        'relativedays' => get_string('relative_days', 'block_smartsection_control'),
        'eventactivity' => get_string('event_activity_completion', 'block_smartsection_control'),
        'nocompletionactivities' => get_string('no_completion_activities', 'block_smartsection_control'),
        'pacingdelay' => get_string('pacing_delay_days', 'block_smartsection_control'),
        'pacingdelayplaceholder' => get_string('pacing_delay_days_placeholder', 'block_smartsection_control'),
        'gradeactivity' => get_string('event_grade_activity', 'block_smartsection_control'),
        'grademinimum' => get_string('event_grade_minimum', 'block_smartsection_control'),
        'grademinimumplaceholder' => get_string('event_grade_minimum_placeholder', 'block_smartsection_control'),
        'needsreviewhelp' => get_string('needs_review_help', 'block_smartsection_control'),
        'manualunlock' => get_string('manual_unlock', 'block_smartsection_control'),
        'manualunlockconfirm' => get_string('manual_unlock_confirm', 'block_smartsection_control'),
        'savechanges' => get_string('savechanges'),
    ],
    'bulk' => [
        'formaction' => $formaction,
        'courseid' => $courseid,
        'sesskey' => sesskey(),
        'locktypeoptions' => $buildoptions($locktypelabels, 'hard'),
        'str' => [
            'bulkactions' => get_string('bulkactions', 'block_smartsection_control'),
            'bulkactionshelp' => get_string('bulkactions_help', 'block_smartsection_control'),
            'bulkimmediate' => get_string('bulk_immediate', 'block_smartsection_control'),
            'bulkscheduletools' => get_string('bulk_schedule_tools', 'block_smartsection_control'),
            'lockall' => get_string('lockall', 'block_smartsection_control'),
            'lockallsummary' => get_string('lockall_summary', 'block_smartsection_control'),
            'lockalldate' => get_string('lockalldate', 'block_smartsection_control'),
            'locktype' => get_string('locktype', 'block_smartsection_control'),
            'lockallsubmit' => get_string('lockall_submit', 'block_smartsection_control'),
            'lockallconfirm' => get_string('lockallconfirm', 'block_smartsection_control'),
            'unlockallaction' => get_string('unlockall_action', 'block_smartsection_control'),
            'clearallaction' => get_string('clearall_action', 'block_smartsection_control'),
            'shiftalldates' => get_string('shift_all_dates', 'block_smartsection_control'),
            'shiftsummary' => get_string('shift_summary', 'block_smartsection_control'),
            'shiftdays' => get_string('shift_days', 'block_smartsection_control'),
            'shiftsubmit' => get_string('shift_submit', 'block_smartsection_control'),
            'skipweekendsholidays' => get_string('skip_weekends_holidays', 'block_smartsection_control'),
            'setsectioninterval' => get_string('set_section_interval', 'block_smartsection_control'),
            'intervalsummary' => get_string('interval_summary', 'block_smartsection_control'),
            'startdate' => get_string('start_date', 'block_smartsection_control'),
            'intervaldays' => get_string('interval_days', 'block_smartsection_control'),
            'intervalsubmit' => get_string('interval_submit', 'block_smartsection_control'),
        ],
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_smartsection_control/manage_page', $templatedata);
echo $OUTPUT->footer();
