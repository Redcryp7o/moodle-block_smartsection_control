<?php
declare(strict_types=1);
/**
 * SmartSection Control Management Page
 *
 * Full-page interface for managing section unlock dates
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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

// Handle email landing parameters to delay section unlock
$delaysection = optional_param('delaysection', 0, PARAM_INT);
$delaydays = optional_param('days', 0, PARAM_INT);
$delayuserid = optional_param('userid', 0, PARAM_INT);
$delaytoken = optional_param('token', '', PARAM_ALPHANUM);

if ($delaysection && $delaydays > 0 && $delaydays <= 7) {
    $authorized = false;
    $record = $DB->get_record('block_smartsection_control', ['sectionid' => $delaysection]);
    if (!$record || (int) $record->courseid !== (int) $course->id
            || (int) $record->unlocktime <= 0 || $record->unlocktype !== 'absolute') {
        redirect(
            new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id]),
            get_string('invalid_delay_request', 'block_smartsection_control'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // confirm_sesskey(false) performs a soft sesskey check: returns false instead of
    // throwing an exception when the key is invalid. This allows the delay link to
    // fall through to the HMAC token check below when accessed from an email client
    // that does not carry the Moodle session (which always lacks a valid sesskey).
    if (confirm_sesskey(false)) {
        $authorized = true;
    } else if ($delayuserid && $delaytoken && (int) $delayuserid === (int) $USER->id) {
        if (\block_smartsection_control\helper::verify_delay_token(
            $delayuserid,
            $delaysection,
            $delaydays,
            (int) $record->unlocktime,
            $delaytoken
        )) {
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

        // Create/update calendar event
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
            'date' => userdate($record->unlocktime, get_string('strftimedatefullshort', 'langconfig'))
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


// Register AMD module for the unlock-type selector show/hide logic.
$PAGE->requires->js_call_amd('block_smartsection_control/manage', 'init');

// Add CSS before header.
$PAGE->requires->css('/blocks/smartsection_control/styles.css');

// Get all course sections (except general)
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();

// Handle bulk actions — POST + sesskey only (never mutate via GET).
$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
if ($bulkaction !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !confirm_sesskey()) {
        throw new \moodle_exception('invalidsesskey', 'error');
    }

    // Moodle-native confirmation for high-impact destructive bulk actions.
    if (in_array($bulkaction, ['unlockall', 'clearall'], true)
            && !optional_param('confirm', 0, PARAM_BOOL)) {
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
            $skip_weekends_holidays = optional_param('skip_weekends_holidays', 0, PARAM_INT) ? true : false;
            if ($shiftdays != 0) {
                $record = $DB->get_record('block_smartsection_control', ['sectionid' => $sid]);
                if ($record && $record->unlocktime > 0 && $record->unlocktype === 'absolute') {
                    $record->unlocktime = \block_smartsection_control\helper::shift_date_excluding_weekends(
                        (int) $record->unlocktime,
                        $shiftdays,
                        $skip_weekends_holidays
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

// Handle manual unlock — must run before Save (Unlock Now POST must not trigger Save).
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

// Handle individual section saves — explicit action=save only (not Unlock Now / bulk).
// NOTE: Do not detect Save via name=save + PARAM_BOOL. A <button name="save"> without
// value= submits save="" which PARAM_BOOL treats as false — Save silently did nothing.
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
        if ($unlocktype === 'event'
                || !\block_smartsection_control\helper::soft_lock_allowed_for_unlocktype($unlocktype)) {
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
            if ($eventgradeactivity > 0 && !$DB->record_exists('course_modules', ['id' => $eventgradeactivity, 'course' => $courseid])) {
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
    // Do not catch Throwable here — Moodle must surface the real error; the
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

    // Calendar sync is secondary — never roll back a successful schedule persist.
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

// Render output
echo $OUTPUT->header();

echo html_writer::start_div('ssc-page ssc-manage ssc-ui');

echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs ssc-nav-tabs', 'role' => 'tablist']);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $course->id]),
        get_string('manage', 'block_smartsection_control'),
        ['class' => 'nav-link active', 'aria-current' => 'page']
    ),
    ['class' => 'nav-item']
);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/timeline.php', ['id' => $course->id]),
        get_string('timeline_view', 'block_smartsection_control'),
        ['class' => 'nav-link']
    ),
    ['class' => 'nav-item']
);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/history.php', ['id' => $course->id]),
        get_string('history_view', 'block_smartsection_control'),
        ['class' => 'nav-link']
    ),
    ['class' => 'nav-item']
);
echo html_writer::end_tag('ul');

$needsreviewcount = 0;
$reviewcandidates = $DB->get_records('block_smartsection_control', [
    'courseid' => $course->id,
    'unlocktype' => 'event',
]);
foreach ($reviewcandidates as $reviewrec) {
    $conditions = json_decode((string) ($reviewrec->eventconditions ?? ''), true);
    if (is_array($conditions) && !empty($conditions['restore_needs_review'])
            && empty($conditions['activity_completion'])
            && empty($conditions['grade_threshold'])) {
        $needsreviewcount++;
    }
}
if ($needsreviewcount > 0) {
    echo $OUTPUT->notification(
        get_string('needs_review_banner', 'block_smartsection_control', $needsreviewcount),
        \core\output\notification::NOTIFY_WARNING
    );
}

$manualunlockforms = [];

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

$sectioncount = 0;
$scheduledcount = 0;
$lockedcount = 0;
foreach ($sections as $section) {
    if ((int) $section->section === 0 || !\block_smartsection_control\helper::is_valid_section_info($section)) {
        continue;
    }
    $sectioncount++;
    $sid = \block_smartsection_control\helper::resolve_section_id_from_info($section, $courseid);
    $rec = $DB->get_record('block_smartsection_control', ['sectionid' => $sid]);
    if ($rec) {
        $scheduledcount++;
        $st = \block_smartsection_control\helper::get_unlock_status($rec, $course);
        if (($st['status'] ?? '') !== 'unlocked') {
            $lockedcount++;
        }
    }
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'id' => 'ssc-section-save',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('ssc-surface ssc-surface--primary ssc-manager');

echo html_writer::start_div('ssc-surface-header ssc-manager-header');
echo html_writer::tag('h2', get_string('section_schedule', 'block_smartsection_control'), ['class' => 'ssc-surface-title ssc-manager-title']);
echo html_writer::tag('p', get_string('section_schedule_desc', 'block_smartsection_control'), ['class' => 'ssc-surface-desc ssc-manager-desc']);
echo html_writer::start_div('ssc-manager-stats', ['aria-label' => get_string('quick_stats', 'block_smartsection_control')]);
echo html_writer::span($sectioncount . ' ' . get_string('total_sections', 'block_smartsection_control'), 'ssc-stat-pill');
echo html_writer::span($scheduledcount . ' ' . get_string('scheduled_sections', 'block_smartsection_control'), 'ssc-stat-pill ssc-stat-pill--scheduled');
echo html_writer::span($lockedcount . ' ' . get_string('locked_sections', 'block_smartsection_control'), 'ssc-stat-pill ssc-stat-pill--locked');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ssc-manager-list', [
    'role' => 'list',
    'aria-label' => get_string('section_schedule', 'block_smartsection_control'),
]);

foreach ($sections as $section) {
    if ((int) $section->section === 0) {
        continue;
    }
    if (!\block_smartsection_control\helper::is_valid_section_info($section)) {
        continue;
    }

    $sectionid = \block_smartsection_control\helper::resolve_section_id_from_info($section, $courseid);
    $record = $DB->get_record('block_smartsection_control', ['sectionid' => $sectionid]);
    $name = $courseformat->get_section_name($section);

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
    $isconfigured = (bool) $record;
    $itemclass = 'ssc-section-item' . ($isconfigured ? ' is-configured' : ' is-unmanaged');

    // Status badge.
    $status = '';
    $needsreview = false;
    if ($record) {
        $statusinfo = \block_smartsection_control\helper::get_unlock_status($record, $course);
        if ($statusinfo['status'] === 'unlocked') {
            $status = html_writer::span(
                get_string('unlocked', 'block_smartsection_control'),
                'ssc-status ssc-status--unlocked'
            );
        } else if ($statusinfo['status'] === 'scheduled' && !empty($statusinfo['unlocktime'])) {
            $status = html_writer::span(
                get_string('locked_until', 'block_smartsection_control',
                    userdate((int) $statusinfo['unlocktime'], get_string('strftimedate', 'langconfig'))),
                'ssc-status ssc-status--scheduled'
            );
        } else if (($record->unlocktype ?? '') === 'event') {
            $conditions = is_array($eventconditions) ? $eventconditions : [];
            if (is_array($conditions) && !empty($conditions['restore_needs_review'])
                    && empty($conditions['activity_completion'])
                    && empty($conditions['grade_threshold'])) {
                $needsreview = true;
                $status = html_writer::span(
                    get_string('status_needs_review', 'block_smartsection_control'),
                    'ssc-status ssc-status--warning',
                    ['title' => get_string('needs_review_help', 'block_smartsection_control')]
                );
            } else {
                $status = html_writer::span(
                    get_string('status_waiting_completion', 'block_smartsection_control'),
                    'ssc-status ssc-status--waiting'
                );
            }
        } else if (($record->unlocktype ?? '') === 'relative') {
            $status = html_writer::span(
                get_string('status_relative_release', 'block_smartsection_control'),
                'ssc-status ssc-status--info'
            );
        } else {
            $status = html_writer::span(
                get_string('locked', 'block_smartsection_control'),
                'ssc-status ssc-status--locked'
            );
        }
    }

    // Collapsed rule summary.
    if (!$record) {
        $rulesummary = get_string('unlocktype_none', 'block_smartsection_control');
    } else if (($record->unlocktype ?? '') === 'absolute') {
        $rulesummary = get_string('unlocktype_absolute', 'block_smartsection_control');
        if (!empty($record->unlocktime)) {
            $rulesummary .= ' · ' . userdate((int) $record->unlocktime, get_string('strftimedate', 'langconfig'));
        }
        $rulesummary .= ' · ' . (($record->locktype ?? 'hard') === 'soft'
            ? get_string('locktype_soft_short', 'block_smartsection_control')
            : get_string('locktype_hard_short', 'block_smartsection_control'));
    } else if (($record->unlocktype ?? '') === 'relative') {
        $rulesummary = get_string('unlocktype_relative', 'block_smartsection_control');
        if ($relativedays !== '' && $relativedays !== null) {
            if ($relativebase === 'prev_section') {
                $rulesummary .= ' · ' . get_string('relative_days_after_prev_section', 'block_smartsection_control')
                    . ': ' . $relativedays;
            } else {
                $rulesummary .= ' · ' . get_string('relative_days_after_start', 'block_smartsection_control')
                    . ': ' . $relativedays;
            }
        }
    } else if (($record->unlocktype ?? '') === 'event') {
        $rulesummary = get_string('unlocktype_event', 'block_smartsection_control');
        if ($needsreview) {
            $rulesummary .= ' · ' . get_string('status_needs_review', 'block_smartsection_control');
        } else if (!empty($selectedactivity) && isset($completionactivities[(int) $selectedactivity])) {
            $rulesummary .= ' · ' . $completionactivities[(int) $selectedactivity];
        }
    } else {
        $rulesummary = get_string('unlocktype_none', 'block_smartsection_control');
    }

    $lockselectattrs = [
        'class' => 'form-select form-select-sm',
        'id' => "locktype_{$sectionid}",
        'aria-label' => get_string('locktype', 'block_smartsection_control') . ' — ' . format_string($name),
    ];
    if (!$hasruleui) {
        $lockselectattrs['disabled'] = 'disabled';
    }

    $typesel = html_writer::select(
        [
            'absolute' => get_string('unlocktype_absolute', 'block_smartsection_control'),
            'relative' => get_string('unlocktype_relative', 'block_smartsection_control'),
            'event' => get_string('unlocktype_event', 'block_smartsection_control'),
            'none' => get_string('unlocktype_none', 'block_smartsection_control'),
        ],
        "unlocktype_{$sectionid}",
        $unlocktype,
        false,
        [
            'class' => 'form-select form-select-sm',
            'id' => "unlocktype_{$sectionid}",
            'aria-label' => get_string('unlocktype', 'block_smartsection_control') . ' — ' . format_string($name),
        ]
    );

    $locksel = html_writer::select(
        [
            'hard' => get_string('locktype_hard', 'block_smartsection_control'),
            'soft' => get_string('locktype_soft', 'block_smartsection_control'),
        ],
        "locktype_{$sectionid}",
        $record ? $locktype : 'hard',
        false,
        $lockselectattrs
    );

    $datefield = html_writer::empty_tag('input', [
        'type' => 'date',
        'name' => "unlock_{$sectionid}",
        'value' => $current,
        'class' => 'form-control form-control-sm',
        'id' => "unlock_date_{$sectionid}",
        'aria-label' => get_string('unlockdate', 'block_smartsection_control') . ' — ' . format_string($name),
    ]);

    $relativebasesel = html_writer::select(
        [
            'start' => get_string('relative_base_start', 'block_smartsection_control'),
            'prev_section' => get_string('relative_base_prev_section', 'block_smartsection_control'),
        ],
        "relativebase_{$sectionid}",
        $relativebase,
        false,
        [
            'class' => 'form-select form-select-sm',
            'id' => "relativebase_{$sectionid}",
            'aria-label' => get_string('relative_base', 'block_smartsection_control') . ' — ' . format_string($name),
        ]
    );
    $relativeinput = html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => "relativedays_{$sectionid}",
        'value' => $relativedays,
        'min' => '0',
        'class' => 'form-control form-control-sm',
        'id' => "relativedays_{$sectionid}",
        'placeholder' => get_string('relative_days', 'block_smartsection_control'),
        'aria-label' => get_string('relative_days', 'block_smartsection_control') . ' — ' . format_string($name),
    ]);

    if (!empty($completionactivities)) {
        $activityselect = html_writer::select(
            $completionactivities,
            "event_activity_{$sectionid}",
            $selectedactivity,
            ['' => get_string('event_activity_completion', 'block_smartsection_control')],
            [
                'class' => 'form-select form-select-sm',
                'id' => "event_activity_{$sectionid}",
                'aria-label' => get_string('event_activity_completion', 'block_smartsection_control') . ' — ' . format_string($name),
            ]
        );
    } else {
        $activityselect = html_writer::div(
            get_string('no_completion_activities', 'block_smartsection_control'),
            'small text-danger'
        );
    }

    $delayinput = html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => "event_delay_{$sectionid}",
        'value' => $pacingdelay,
        'min' => '0',
        'class' => 'form-control form-control-sm',
        'id' => "event_delay_{$sectionid}",
        'placeholder' => get_string('pacing_delay_days_placeholder', 'block_smartsection_control'),
        'aria-label' => get_string('pacing_delay_days', 'block_smartsection_control') . ' — ' . format_string($name),
    ]);

    $gradeactivityselect = '';
    if (!empty($gradedactivities)) {
        $gradeactivityselect = html_writer::select(
            $gradedactivities,
            "event_grade_activity_{$sectionid}",
            $selectedgradeactivity,
            ['' => get_string('event_grade_activity', 'block_smartsection_control')],
            [
                'class' => 'form-select form-select-sm',
                'id' => "event_grade_activity_{$sectionid}",
                'aria-label' => get_string('event_grade_activity', 'block_smartsection_control') . ' — ' . format_string($name),
            ]
        );
    }

    $grademinimuminput = html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => "event_grade_minimum_{$sectionid}",
        'value' => $grademinimum,
        'min' => '0',
        'max' => '100',
        'step' => '0.1',
        'class' => 'form-control form-control-sm',
        'id' => "event_grade_minimum_{$sectionid}",
        'placeholder' => get_string('event_grade_minimum_placeholder', 'block_smartsection_control'),
        'aria-label' => get_string('event_grade_minimum', 'block_smartsection_control') . ' — ' . format_string($name),
    ]);

    $manualbtn = '';
    if ($record && empty($record->manualoverride)) {
        $muformid = 'ssc-manual-unlock-' . $sectionid;
        $manualbtn = html_writer::tag('button', get_string('manual_unlock', 'block_smartsection_control'), [
            'type' => 'submit',
            'name' => 'manualunlock',
            'value' => (string) $sectionid,
            'form' => $muformid,
            'class' => 'btn btn-sm btn-outline-secondary ssc-unlock-now',
            'data-sectionid' => (string) $sectionid,
            'onclick' => 'return confirm(' . json_encode(get_string('manual_unlock_confirm', 'block_smartsection_control')) . ');',
        ]);
        $manualunlockforms[$sectionid] = $muformid;
    }

    $icon = $OUTPUT->pix_icon('i/folder', '', 'core', [
        'class' => 'icon ssc-section-icon',
        'aria-hidden' => 'true',
    ]);

    $detailsattrs = [
        'class' => $itemclass,
        'role' => 'listitem',
        'data-sectionid' => (string) $sectionid,
    ];
    // Keep rows needing review expanded so the warning is visible.
    if ($needsreview) {
        $detailsattrs['open'] = 'open';
    }

    echo html_writer::start_tag('details', $detailsattrs);

    echo html_writer::start_tag('summary', ['class' => 'ssc-section-summary']);
    echo html_writer::span($icon, 'ssc-section-icon-wrap');
    echo html_writer::start_div('ssc-section-main');
    echo html_writer::span(format_string($name), 'ssc-section-name');
    echo html_writer::span(s($rulesummary), 'ssc-section-meta');
    echo html_writer::end_div();
    echo html_writer::div($status, 'ssc-section-status');
    echo html_writer::span('', 'ssc-chevron', ['aria-hidden' => 'true']);
    echo html_writer::end_tag('summary');

    echo html_writer::start_div('ssc-section-body');
    echo html_writer::start_div('ssc-field-grid');

    echo html_writer::start_div('ssc-field');
    echo html_writer::tag('label', get_string('releaserule', 'block_smartsection_control'), [
        'class' => 'ssc-label',
        'for' => "unlocktype_{$sectionid}",
    ]);
    echo $typesel;
    echo html_writer::end_div();

    echo html_writer::start_div('ssc-field ssc-lock-wrapper', [
        'id' => "locktypewrapper_{$sectionid}",
        'style' => 'display: ' . ($hasruleui ? 'flex' : 'none') . ';',
    ]);
    echo html_writer::tag('label', get_string('locktype', 'block_smartsection_control'), [
        'class' => 'ssc-label',
        'for' => "locktype_{$sectionid}",
    ]);
    echo $locksel;
    echo html_writer::tag(
        'p',
        get_string('soft_lock_event_forced_hard', 'block_smartsection_control'),
        [
            'class' => 'ssc-lock-event-note',
            'id' => "lockeventnote_{$sectionid}",
            'style' => 'display: ' . ($unlocktype === 'event' ? 'block' : 'none') . ';',
        ]
    );
    echo html_writer::end_div();

    echo html_writer::start_div('ssc-field ssc-rule-fields', [
        'id' => "datewrapper_{$sectionid}",
        'style' => 'display: ' . ($unlocktype === 'absolute' ? 'flex' : 'none') . ';',
    ]);
    echo html_writer::tag('label', get_string('unlockdate', 'block_smartsection_control'), [
        'class' => 'ssc-label',
        'for' => "unlock_date_{$sectionid}",
    ]);
    echo $datefield;
    echo html_writer::end_div();

    echo html_writer::start_div('ssc-field ssc-rule-fields', [
        'id' => "relativewrapper_{$sectionid}",
        'style' => 'display: ' . ($unlocktype === 'relative' ? 'flex' : 'none') . ';',
    ]);
    echo html_writer::tag('label', get_string('relative_base', 'block_smartsection_control'), [
        'class' => 'ssc-label',
        'for' => "relativebase_{$sectionid}",
    ]);
    echo $relativebasesel;
    echo html_writer::tag('label', get_string('relative_days', 'block_smartsection_control'), [
        'class' => 'ssc-label mt-2',
        'for' => "relativedays_{$sectionid}",
    ]);
    echo $relativeinput;
    echo html_writer::end_div();

    echo html_writer::start_div('ssc-field ssc-rule-fields', [
        'id' => "eventwrapper_{$sectionid}",
        'style' => 'display: ' . ($unlocktype === 'event' ? 'flex' : 'none') . ';',
    ]);
    if ($needsreview) {
        echo html_writer::tag('p', get_string('needs_review_help', 'block_smartsection_control'), [
            'class' => 'ssc-review-help',
        ]);
    }
    echo html_writer::tag('label', get_string('event_activity_completion', 'block_smartsection_control'), [
        'class' => 'ssc-label',
        'for' => "event_activity_{$sectionid}",
    ]);
    echo $activityselect;
    echo html_writer::tag('label', get_string('pacing_delay_days', 'block_smartsection_control'), [
        'class' => 'ssc-label mt-2',
        'for' => "event_delay_{$sectionid}",
    ]);
    echo $delayinput;
    if ($gradeactivityselect !== '') {
        echo html_writer::tag('label', get_string('event_grade_activity', 'block_smartsection_control'), [
            'class' => 'ssc-label mt-2',
            'for' => "event_grade_activity_{$sectionid}",
        ]);
        echo $gradeactivityselect;
        echo html_writer::tag('label', get_string('event_grade_minimum', 'block_smartsection_control'), [
            'class' => 'ssc-label mt-2',
            'for' => "event_grade_minimum_{$sectionid}",
        ]);
        echo $grademinimuminput;
    }
    echo html_writer::end_div();

    echo html_writer::end_div(); // .ssc-field-grid

    if ($manualbtn !== '') {
        echo html_writer::div($manualbtn, 'ssc-section-actions');
    }

    echo html_writer::end_div(); // .ssc-section-body
    echo html_writer::end_tag('details');
}

echo html_writer::end_div(); // .ssc-manager-list
echo html_writer::end_div(); // .ssc-manager

echo html_writer::start_div('ssc-save-bar');
echo html_writer::tag('button', get_string('savechanges'), [
    'type' => 'submit',
    'name' => 'action',
    'value' => 'save',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

foreach ($manualunlockforms as $muSectionid => $muFormid) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url->out(false),
        'id' => $muFormid,
        'class' => 'd-none ssc-manual-unlock-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::end_tag('form');
}

// Bulk operations — one secondary surface (separate forms; never nested in Save).
echo html_writer::start_div('ssc-surface ssc-surface--secondary ssc-bulk');
echo html_writer::start_div('ssc-surface-header');
echo html_writer::tag('h2', get_string('bulkactions', 'block_smartsection_control'), ['class' => 'ssc-surface-title']);
echo html_writer::tag('p', get_string('bulkactions_help', 'block_smartsection_control'), ['class' => 'ssc-surface-desc']);
echo html_writer::end_div();

echo html_writer::start_div('ssc-bulk-body');

// Immediate operations.
echo html_writer::start_div('ssc-bulk-group');
echo html_writer::tag('h3', get_string('bulk_immediate', 'block_smartsection_control'), ['class' => 'ssc-group-label']);

echo html_writer::start_tag('details', ['class' => 'ssc-disclosure']);
echo html_writer::start_tag('summary');
echo html_writer::start_div('ssc-disclosure-main');
echo html_writer::span(get_string('lockall', 'block_smartsection_control'), 'ssc-disclosure-title');
echo html_writer::span(get_string('lockall_summary', 'block_smartsection_control'), 'ssc-disclosure-meta');
echo html_writer::end_div();
echo html_writer::span('', 'ssc-chevron', ['aria-hidden' => 'true']);
echo html_writer::end_tag('summary');
echo html_writer::start_div('ssc-disclosure-body');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'value' => 'lockall']);
echo html_writer::start_div('ssc-field-grid');
echo html_writer::start_div('ssc-field');
echo html_writer::tag('label', get_string('lockalldate', 'block_smartsection_control'), [
    'class' => 'ssc-label',
    'for' => 'lockalldate',
]);
echo html_writer::empty_tag('input', [
    'type' => 'datetime-local',
    'name' => 'lockalldate',
    'id' => 'lockalldate',
    'required' => true,
    'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();
echo html_writer::start_div('ssc-field');
echo html_writer::tag('label', get_string('locktype', 'block_smartsection_control'), [
    'class' => 'ssc-label',
    'for' => 'lockalllocktype',
]);
echo html_writer::select(
    [
        'hard' => get_string('locktype_hard', 'block_smartsection_control'),
        'soft' => get_string('locktype_soft', 'block_smartsection_control'),
    ],
    'lockalllocktype',
    'hard',
    false,
    ['class' => 'form-select form-select-sm', 'id' => 'lockalllocktype']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ssc-disclosure-actions');
echo html_writer::tag('button', get_string('lockall_submit', 'block_smartsection_control'), [
    'type' => 'submit',
    'class' => 'btn btn-primary btn-sm',
    'onclick' => 'return confirm(' . json_encode(get_string('lockallconfirm', 'block_smartsection_control')) . ');',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_tag('details');

echo html_writer::start_div('ssc-bulk-quick');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'd-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'value' => 'unlockall']);
echo html_writer::tag('button', get_string('unlockall_action', 'block_smartsection_control'), [
    'type' => 'submit',
    'class' => 'btn btn-outline-warning btn-sm',
]);
echo html_writer::end_tag('form');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'd-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'value' => 'clearall']);
echo html_writer::tag('button', get_string('clearall_action', 'block_smartsection_control'), [
    'type' => 'submit',
    'class' => 'btn btn-outline-danger btn-sm',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div(); // .ssc-bulk-group immediate

// Scheduling tools.
echo html_writer::start_div('ssc-bulk-group');
echo html_writer::tag('h3', get_string('bulk_schedule_tools', 'block_smartsection_control'), ['class' => 'ssc-group-label']);

echo html_writer::start_tag('details', ['class' => 'ssc-disclosure']);
echo html_writer::start_tag('summary');
echo html_writer::start_div('ssc-disclosure-main');
echo html_writer::span(get_string('shift_all_dates', 'block_smartsection_control'), 'ssc-disclosure-title');
echo html_writer::span(get_string('shift_summary', 'block_smartsection_control'), 'ssc-disclosure-meta');
echo html_writer::end_div();
echo html_writer::span('', 'ssc-chevron', ['aria-hidden' => 'true']);
echo html_writer::end_tag('summary');
echo html_writer::start_div('ssc-disclosure-body');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'value' => 'shiftdates']);
echo html_writer::start_div('ssc-field');
echo html_writer::tag('label', get_string('shift_days', 'block_smartsection_control'), [
    'class' => 'ssc-label',
    'for' => 'shiftdays',
]);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'shiftdays',
    'id' => 'shiftdays',
    'required' => true,
    'class' => 'form-control form-control-sm',
    'value' => '0',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-check');
echo html_writer::checkbox(
    'skip_weekends_holidays',
    1,
    false,
    get_string('skip_weekends_holidays', 'block_smartsection_control'),
    ['class' => 'form-check-input', 'id' => 'skip_weekends_holidays']
);
echo html_writer::end_div();
echo html_writer::start_div('ssc-disclosure-actions');
echo html_writer::tag('button', get_string('shift_submit', 'block_smartsection_control'), [
    'type' => 'submit',
    'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_tag('details');

echo html_writer::start_tag('details', ['class' => 'ssc-disclosure']);
echo html_writer::start_tag('summary');
echo html_writer::start_div('ssc-disclosure-main');
echo html_writer::span(get_string('set_section_interval', 'block_smartsection_control'), 'ssc-disclosure-title');
echo html_writer::span(get_string('interval_summary', 'block_smartsection_control'), 'ssc-disclosure-meta');
echo html_writer::end_div();
echo html_writer::span('', 'ssc-chevron', ['aria-hidden' => 'true']);
echo html_writer::end_tag('summary');
echo html_writer::start_div('ssc-disclosure-body');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'value' => 'applyinterval']);
echo html_writer::start_div('ssc-field-grid');
echo html_writer::start_div('ssc-field');
echo html_writer::tag('label', get_string('start_date', 'block_smartsection_control'), [
    'class' => 'ssc-label',
    'for' => 'intervalstart',
]);
echo html_writer::empty_tag('input', [
    'type' => 'datetime-local',
    'name' => 'intervalstart',
    'id' => 'intervalstart',
    'required' => true,
    'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();
echo html_writer::start_div('ssc-field');
echo html_writer::tag('label', get_string('interval_days', 'block_smartsection_control'), [
    'class' => 'ssc-label',
    'for' => 'intervaldays',
]);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'intervaldays',
    'id' => 'intervaldays',
    'required' => true,
    'min' => '1',
    'class' => 'form-control form-control-sm',
    'value' => '7',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ssc-disclosure-actions');
echo html_writer::tag('button', get_string('interval_submit', 'block_smartsection_control'), [
    'type' => 'submit',
    'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_tag('details');
echo html_writer::end_div(); // .ssc-bulk-group tools

echo html_writer::end_div(); // .ssc-bulk-body
echo html_writer::end_div(); // .ssc-bulk

echo html_writer::end_div(); // .ssc-page
echo $OUTPUT->footer();
