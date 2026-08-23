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
 * Scheduled task: enforce section visibility across all courses.
 *
 * Runs every 5 minutes to evaluate release timestamps and enforce the correct
 * lock/unlock state across all courses.
 *
 * @package    block_smartsection_control
 * @copyright  2026 EncryptEdge Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_smartsection_control\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Cron task that sweeps all courses and enforces section visibility.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_section_visibility extends \core\task\scheduled_task {

    /**
     * Return the localised task name displayed in the Moodle admin interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_check_section_visibility', 'block_smartsection_control');
    }

    /**
     * Execute the visibility enforcement pass.
     *
     * Loads lib.php explicitly because it contains procedural global functions
     * that are not discovered by Moodle's PSR-0 autoloader.
     *
     * Logs a structured maintenance summary via mtrace() after the sweep so that
     * site administrators can confirm the task is running correctly and identify
     * any data quality issues (orphans, missing sections, deleted courses).
     */
    public function execute(): void {
        global $CFG;

        if (!get_config('block_smartsection_control', 'enabled')) {
            mtrace('SmartSection Control: plugin disabled in admin settings — visibility sweep skipped.');
            return;
        }

        require_once($CFG->dirroot . '/blocks/smartsection_control/lib.php');

        mtrace('SmartSection Control: starting section visibility sweep...');

        $stats = block_smartsection_control_check_sections_visibility();

        // --- Garbage collection report --------------------------------------
        $gc_total = $stats['orphaned_rules'] + $stats['orphaned_history'] + $stats['orphaned_pacing'];
        if ($gc_total > 0) {
            mtrace(sprintf(
                '  GC: removed %d orphaned rule(s), %d history row(s), %d pacing row(s).',
                $stats['orphaned_rules'],
                $stats['orphaned_history'],
                $stats['orphaned_pacing']
            ));
        } else {
            mtrace('  GC: no orphaned records found.');
        }

        // --- Enforcement report --------------------------------------------
        mtrace(sprintf(
            '  Sweep: %d section(s) enforced.',
            $stats['enforced']
        ));

        // Only log skip details when there is something worth noting.
        if ($stats['skipped_manual'] > 0) {
            mtrace(sprintf('  Skipped: %d section(s) with manual override active.', $stats['skipped_manual']));
        }
        if ($stats['skipped_deleted_course'] > 0) {
            mtrace(sprintf(
                '  Skipped: %d record(s) whose course no longer exists (will be removed on next GC pass).',
                $stats['skipped_deleted_course']
            ));
        }
        if ($stats['skipped_missing_section'] > 0) {
            mtrace(sprintf(
                '  Skipped: %d record(s) whose section no longer exists (will be removed on next GC pass).',
                $stats['skipped_missing_section']
            ));
        }

        mtrace('SmartSection Control: visibility sweep complete.');
    }
}
