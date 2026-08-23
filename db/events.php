<?php
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
 * Event observer registrations for SmartSection Control.
 *
 * Note: The before_http_headers enforcement callback is registered as a Moodle 5.x
 * hook in db/hooks.php (which is always active on Moodle 5.2+). The course_viewed
 * event observer previously used for this purpose has been removed to prevent
 * double execution of the enforcement logic on every course page view.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$observers = [
    [
        // Trigger whenever an activity completion state changes.
        // Writes a personal pacing unlock timestamp to block_smartsection_control_u,
        // allowing per-student velocity-based content release.
        'eventname'   => '\core\event\course_module_completion_updated',
        'callback'    => '\block_smartsection_control\observer::course_module_completion_updated',
        'internal'    => false,
    ],
];
