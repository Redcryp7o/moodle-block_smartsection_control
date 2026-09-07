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
 * Moodle 5.x hook listener for the SmartSection Control block.
 *
 * Registers with the core\hook\output\before_http_headers hook (introduced in
 * Moodle 4.4) to run the section enforcement logic early in every request, before
 * HTTP headers are sent. This replaces the legacy before_http_headers() plugin
 * callback that was deprecated in Moodle 4.4 and removed in Moodle 5.x.
 *
 * Since the plugin requires Moodle 5.2+ (where this hook is always active),
 * the previously registered course_viewed event observer has been removed to
 * prevent double execution of the same enforcement logic.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_smartsection_control;

/**
 * Hook listener callbacks for the SmartSection Control block.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Execute section enforcement before HTTP headers are sent.
     *
     * Loads lib.php explicitly because that file contains procedural global
     * functions that are not autoloaded by Moodle's PSR-0 class loader. The
     * require_once is idempotent — if the block is on the current page and has
     * already required lib.php via block_smartsection_control.php, the file will
     * not be loaded a second time.
     *
     * @param \core\hook\output\before_http_headers $hook The hook instance (unused).
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/smartsection_control/lib.php');
        block_smartsection_control_before_http_headers();
    }
}
