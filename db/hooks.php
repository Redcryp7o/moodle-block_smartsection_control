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
 * Hook registrations for block_smartsection_control
 *
 * This file is auto-discovered by Moodle's hook system.
 * It replaces the legacy 'before_http_headers' plugin callback.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // Replaces the deprecated block_smartsection_control_before_http_headers()
        // function-based callback. Moodle 5.x requires hook listeners instead.
        'hook'     => \core\hook\output\before_http_headers::class,
        'callback' => \block_smartsection_control\hook_listener::class . '::before_http_headers',
        'priority' => 500,
    ],
];
