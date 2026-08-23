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
 * Scheduled task: send instructor notifications for upcoming section unlocks.
 *
 * Runs daily at 08:00 and identifies sections with an absolute unlock time within the next
 * 24 hours. For each, it checks the previous-section completion rate and sends
 * a rich HTML email to all course instructors with optional delay-link buttons.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_smartsection_control\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Notification task for SmartSection Control.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_notifications extends \core\task\scheduled_task {

    /**
     * Return the localised task name displayed in the Moodle admin interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_notifications', 'block_smartsection_control');
    }

    /**
     * Execute the notification sweep.
     *
     * Finds all absolute-type sections unlocking in the next 24 hours, evaluates
     * the preceding section's student completion rate, and dispatches a styled
     * HTML notification with signed delay-link buttons to each course instructor.
     */
    public function execute(): void {
        global $DB;

        if (!get_config('block_smartsection_control', 'enabled')) {
            mtrace('SmartSection Control: plugin disabled in admin settings — notification sweep skipped.');
            return;
        }

        $now      = time();
        $in24h    = $now + (24 * HOURSECS);

        $sql = "SELECT bsu.*, cs.name AS sectionname, c.fullname AS coursename
                  FROM {block_smartsection_control} bsu
                  JOIN {course_sections} cs ON cs.id = bsu.sectionid
                  JOIN {course} c ON c.id = bsu.courseid
                 WHERE bsu.unlocktime > :now
                   AND bsu.unlocktime <= :in24h
                   AND bsu.unlocktype = 'absolute'
                   AND (bsu.notifysentfor IS NULL OR bsu.notifysentfor <> bsu.unlocktime)";

        $upcoming = $DB->get_records_sql($sql, ['now' => $now, 'in24h' => $in24h]);

        mtrace('SmartSection Control: starting notification sweep...');
        mtrace(sprintf('  Found %d section(s) unlocking in the next 24 hours.', count($upcoming)));

        $notificationssent = 0;
        $lockfactory = \core\lock\lock_config::get_lock_factory('block_smartsection_control');

        foreach ($upcoming as $record) {
            $lock = $lockfactory->get_lock('notify_' . (int) $record->id, 5);
            if (!$lock) {
                continue;
            }
            try {
                // Re-read under lock to avoid duplicate sends from overlapping cron.
                $fresh = $DB->get_record('block_smartsection_control', ['id' => $record->id]);
                if (!$fresh
                        || (int) $fresh->unlocktime !== (int) $record->unlocktime
                        || (int) ($fresh->notifysentfor ?? 0) === (int) $fresh->unlocktime) {
                    continue;
                }

                $course         = $DB->get_record('course', ['id' => $record->courseid], '*', MUST_EXIST);
                $sectionobj     = $DB->get_record('course_sections', ['id' => $record->sectionid], '*', MUST_EXIST);
                $courseformat   = course_get_format($course);
                $sectionnamestr = $courseformat->get_section_name($sectionobj);
                $coursenamestr  = format_string((string) $record->coursename);

                $instructors = get_enrolled_users(
                    \context_course::instance($course->id),
                    'block/smartsection_control:manage'
                );

                $completionrate = \block_smartsection_control\helper::get_prev_section_completion_rate(
                    (int) $record->sectionid,
                    (int) $record->courseid
                );

                $haswarning  = ($completionrate !== null && $completionrate < 0.70);
                $warningtext = $haswarning
                    ? get_string('pacing_warning_banner', 'block_smartsection_control', (int) round($completionrate * 100))
                    : '';

                if ($haswarning) {
                    mtrace(sprintf(
                        '  Pacing warning for section "%s" in "%s": completion rate %.0f%%.',
                        $sectionnamestr,
                        $coursenamestr,
                        ($completionrate * 100)
                    ));
                }

                foreach ($instructors as $instructor) {
                    $this->send_notification(
                        $instructor,
                        $sectionnamestr,
                        $coursenamestr,
                        (int) $record->sectionid,
                        (int) $record->courseid,
                        (int) $record->unlocktime,
                        $haswarning,
                        $warningtext
                    );
                    $notificationssent++;
                }

                $DB->set_field(
                    'block_smartsection_control',
                    'notifysentfor',
                    (int) $fresh->unlocktime,
                    ['id' => (int) $fresh->id]
                );
            } finally {
                $lock->release();
            }
        }

        mtrace(sprintf('  Sent %d notification email(s).', $notificationssent));
        mtrace('SmartSection Control: notification sweep complete.');
    }

    /**
     * Compose and dispatch a single notification message to one instructor.
     *
     * @param object $instructor     The instructor user object.
     * @param string $sectionname    Formatted section name.
     * @param string $coursename     Formatted course name.
     * @param int    $sectionid      Section database ID.
     * @param int    $courseid       Course ID.
     * @param int    $unlocktime     Unix timestamp of the scheduled unlock.
     * @param bool   $haswarning     Whether to include the pacing-guard alert.
     * @param string $warningtext    Pre-formatted warning string (empty when $haswarning is false).
     */
    private function send_notification(
        object $instructor,
        string $sectionname,
        string $coursename,
        int $sectionid,
        int $courseid,
        int $unlocktime,
        bool $haswarning,
        string $warningtext
    ): void {
        $subject  = get_string('notification_unlock_soon_subject', 'block_smartsection_control', $sectionname);
        $bodytext = get_string('notification_unlock_soon_body', 'block_smartsection_control', (object) [
            'section' => $sectionname,
            'course'  => $coursename,
            'date'    => userdate($unlocktime, get_string('strftimedatefullshort', 'langconfig')),
        ]);

        // Build signed delay-link URLs for 1, 2, and 3 day delays.
        $delayurls = [];
        for ($days = 1; $days <= 3; $days++) {
            $token            = \block_smartsection_control\helper::generate_delay_token(
                (int) $instructor->id,
                $sectionid,
                $days,
                $unlocktime
            );
            $url              = new \moodle_url('/blocks/smartsection_control/manage.php', [
                'id'           => $courseid,
                'delaysection' => $sectionid,
                'days'         => $days,
                'userid'       => $instructor->id,
                'token'        => $token,
            ]);
            $delayurls[$days] = $url->out(false);
        }

        // Plain-text body.
        $plainbody  = $bodytext . "\n\n";
        if ($haswarning) {
            $plainbody .= $warningtext . "\n\n";
        }
        $plainbody .= "Delay this section unlock:\n";
        for ($days = 1; $days <= 3; $days++) {
            $plainbody .= '- ' . get_string('delay_x_days', 'block_smartsection_control', $days) . ': ' . $delayurls[$days] . "\n";
        }

        // HTML body.
        $htmlbody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;background:#fff;">';
        $htmlbody .= '<h2 style="color:#1a73e8;margin-top:0;">' . s($subject) . '</h2>';
        $htmlbody .= '<p style="color:#3c4043;font-size:16px;line-height:1.5;">' . s($bodytext) . '</p>';

        if ($haswarning) {
            $htmlbody .= '<div style="background:#fce8e6;border-left:4px solid #d93025;padding:15px;margin:20px 0;border-radius:4px;color:#a51d24;font-weight:bold;">';
            $htmlbody .= s($warningtext);
            $htmlbody .= '</div>';
        }

        $htmlbody .= '<div style="margin:25px 0 10px 0;border-top:1px solid #e0e0e0;padding-top:20px;">';
        $htmlbody .= '<p style="color:#5f6368;font-weight:bold;margin-bottom:15px;">Delay Section Unlock:</p>';
        $htmlbody .= '<div style="display:flex;gap:10px;">';
        for ($days = 1; $days <= 3; $days++) {
            $label     = get_string('delay_x_days', 'block_smartsection_control', $days);
            $htmlbody .= '<a href="' . $delayurls[$days] . '" style="display:inline-block;padding:10px 15px;background:#1a73e8;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;">' . s($label) . '</a> ';
        }
        $htmlbody .= '</div></div></div>';

        $message                      = new \core\message\message();
        $message->component           = 'block_smartsection_control';
        $message->name                = 'unlock_notification';
        $message->userfrom            = \core_user::get_noreply_user();
        $message->userto              = $instructor;
        $message->subject             = $subject;
        $message->fullmessage         = $plainbody;
        $message->fullmessageformat   = FORMAT_PLAIN;
        $message->fullmessagehtml     = $htmlbody;
        $message->smallmessage        = $subject;
        $message->notification        = 1;

        message_send($message);
    }
}
