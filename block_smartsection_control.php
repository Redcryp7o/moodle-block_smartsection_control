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
 * SmartSection Control Block class.
 *
 * Provides a role-differentiated sidebar block:
 * - Teachers / managers : quick statistics and navigation to the management board.
 * - Students            : a read-only timeline of upcoming section unlocks.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

/**
 * SmartSection Control Block.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_smartsection_control extends block_base {
    /**
     * Initialise the block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_smartsection_control');
    }

    /**
     * Return the rendered block content for the current user and context.
     *
     * Teachers and managers receive a quick-stats dashboard with links to the
     * management board, timeline view, and history view. Students receive a
     * read-only countdown timeline of their upcoming section unlocks.
     *
     * @return stdClass|null Block content object, or null when the block should not render.
     */
    public function get_content(): ?stdClass {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        if (empty($this->page->course->id)) {
            return $this->content;
        }

        $context = context_course::instance($this->page->course->id, IGNORE_MISSING);
        if (!$context) {
            return $this->content;
        }

        if (!get_config('block_smartsection_control', 'enabled')) {
            return $this->content;
        }

        // Section visibility enforcement is handled by the Moodle 5.x hook listener
        // (db/hooks.php). Do not call block_smartsection_control_before_http_headers()
        // here to avoid double execution on every page load.

        if (has_capability('block/smartsection_control:manage', $context)) {
            $this->content->text = $this->get_teacher_content();
        } else {
            $this->content->text = $this->get_student_content();
        }

        return $this->content;
    }

    /**
     * Build the student-facing view: a read-only upcoming-unlocks timeline.
     *
     * @return string Rendered HTML.
     */
    private function get_student_content(): string {
        global $COURSE;

        $courseid = !empty($COURSE->id) ? (int) $COURSE->id : (int) $this->page->course->id;
        $widget   = new \block_smartsection_control\output\dashboard_widget(true, $courseid);
        $renderer = $this->page->get_renderer('block_smartsection_control');

        return $renderer->render_block_content($widget);
    }

    /**
     * Build the teacher/manager view: quick statistics and navigation links.
     *
     * @return string Rendered HTML.
     */
    private function get_teacher_content(): string {
        global $COURSE;

        if (!empty($COURSE->id)) {
            $courseid = (int) $COURSE->id;
            $course   = $COURSE;
        } else {
            $courseid = (int) $this->page->course->id;
            $course   = get_course($courseid);
        }

        $data = [
            'coursename'  => format_string($course->fullname),
            'courseid'    => $courseid,
            'stats'       => $this->get_quick_stats($courseid),
            'manageurl'   => (string) new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]),
            'timelineurl' => (string) new moodle_url('/blocks/smartsection_control/timeline.php', ['id' => $courseid]),
            'historyurl'  => (string) new moodle_url('/blocks/smartsection_control/history.php', ['id' => $courseid]),
        ];

        $renderer = $this->page->get_renderer('block_smartsection_control');
        return $renderer->render_teacher_block($data);
    }

    /**
     * Return quick statistics for the course.
     *
     * Runs three lean COUNT queries: one for scheduled future unlocks, one for
     * already-passed unlocks, and derives locked count from the total.
     *
     * @param int $courseid The course ID.
     * @return array{total:int,unlocked:int,scheduled:int,locked:int}
     */
    private function get_quick_stats(int $courseid): array {
        global $DB;

        $now   = time();
        $total = $DB->count_records('block_smartsection_control', ['courseid' => $courseid]);

        $unlocked = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {block_smartsection_control}
              WHERE courseid = :courseid AND unlocktime > 0 AND unlocktime <= :now",
            ['courseid' => $courseid, 'now' => $now]
        );

        $scheduled = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {block_smartsection_control}
              WHERE courseid = :courseid AND unlocktime > :now",
            ['courseid' => $courseid, 'now' => $now]
        );

        return [
            'total'     => $total,
            'unlocked'  => $unlocked,
            'scheduled' => $scheduled,
            'locked'    => $total - $unlocked - $scheduled,
        ];
    }

    /**
     * Declare that this block exposes global admin settings.
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Only one instance of this block is allowed per course page.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Disable per-instance configuration; all settings are site-global or per-section.
     *
     * @return bool
     */
    public function instance_allow_config(): bool {
        return false;
    }

    /**
     * Restrict this block to course pages only.
     *
     * @return array<string,bool>
     */
    public function applicable_formats(): array {
        return [
            'course' => true,
            'site'   => false,
            'my'     => false,
        ];
    }

    /**
     * Clean up all course-specific data when the block instance is deleted from a course.
     *
     * @return bool
     */
    public function instance_delete(): bool {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $courseid = 0;
        if (!empty($this->course->id)) {
            $courseid = (int) $this->course->id;
        } else if (!empty($this->page->course->id)) {
            $courseid = (int) $this->page->course->id;
        }

        if ($courseid > 1) {
            // Course configuration is shared by all block instances. Only release
            // and delete when this is the last SmartSection Control instance.
            $coursecontext = \context_course::instance($courseid);
            $instancecount = $DB->count_records('block_instances', [
                'blockname' => 'smartsection_control',
                'parentcontextid' => $coursecontext->id,
            ]);
            if ($instancecount > 1) {
                return true;
            }

            $records = $DB->get_records('block_smartsection_control', ['courseid' => $courseid]);
            foreach ($records as $record) {
                \block_smartsection_control\calendar::delete_unlock_event((int) $record->sectionid, $courseid);
                $matchtime = ((int) $record->unlocktime > 0) ? (int) $record->unlocktime : null;
                $locktype = $record->locktype ?? 'hard';
                if (($record->unlocktype ?? '') === 'event') {
                    $locktype = 'hard';
                }
                \block_smartsection_control\helper::release_section_control(
                    (int) $record->sectionid,
                    $courseid,
                    $matchtime,
                    $locktype
                );
            }
            $DB->delete_records('block_smartsection_control', ['courseid' => $courseid]);
            $DB->delete_records('block_smartsection_control_h', ['courseid' => $courseid]);
            $DB->delete_records('block_smartsection_control_u', ['courseid' => $courseid]);
            rebuild_course_cache($courseid, true);
        }

        return true;
    }

    /**
     * Return the correct ARIA landmark role for this block.
     *
     * 'complementary' correctly identifies this as supplementary sidebar content
     * and does not suppress screen-reader keyboard shortcuts (unlike 'application').
     *
     * @return string
     */
    public function get_aria_role(): string {
        return 'complementary';
    }
}
