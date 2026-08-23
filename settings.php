<?php
/**
 * Settings for block_smartsection_control plugin
 *
 * Moodle core creates the admin settings page for blocks with has_config().
 * Add settings to the provided $settings object only — do not register a duplicate page.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'block_smartsection_control/enabled',
        get_string('enabled', 'block_smartsection_control'),
        get_string('enabled_desc', 'block_smartsection_control'),
        1
    ));
}
