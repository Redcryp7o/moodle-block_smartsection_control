<?php
/**
 * Hook registrations for block_smartsection_control
 *
 * This file is auto-discovered by Moodle's hook system.
 * It replaces the legacy 'before_http_headers' plugin callback.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
