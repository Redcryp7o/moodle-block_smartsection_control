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
 * Uninstall hook for block_smartsection_control.
 *
 * Releases Soft Lock availability markers and restores section visibility before
 * Moodle drops the plugin tables, so courses are not left unintentionally locked.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Uninstall the plugin and release controlled sections.
 *
 * @return bool True on success.
 */
function xmldb_block_smartsection_control_uninstall(): bool {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/course/lib.php');

    if (!$DB->get_manager()->table_exists('block_smartsection_control')) {
        return true;
    }

    $records = $DB->get_records('block_smartsection_control');
    $courseids = [];

    foreach ($records as $record) {
        $courseid = (int) $record->courseid;
        $sectionid = (int) $record->sectionid;
        $matchtime = ((int) $record->unlocktime > 0) ? (int) $record->unlocktime : null;

        \block_smartsection_control\calendar::delete_unlock_event($sectionid, $courseid);
        $locktype = $record->locktype ?? 'hard';
        if (($record->unlocktype ?? '') === 'event') {
            $locktype = 'hard';
        }
        \block_smartsection_control\helper::release_section_control(
            $sectionid,
            $courseid,
            $matchtime,
            $locktype
        );
        $courseids[$courseid] = $courseid;
    }

    foreach ($courseids as $courseid) {
        if ($courseid > 1) {
            rebuild_course_cache($courseid, true);
        }
    }

    return true;
}
