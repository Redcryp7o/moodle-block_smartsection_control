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
 * Backup task for block_smartsection_control.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/smartsection_control/backup/moodle2/backup_smartsection_control_stepslib.php');

/**
 * Specialised backup task for block_smartsection_control.
 */
class backup_smartsection_control_block_task extends backup_block_task {

    /**
     * Define particular settings this block can have.
     */
    protected function define_my_settings() {
        // No special settings.
    }

    /**
     * Define particular steps this block can have.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_smartsection_control_block_structure_step(
            'smartsection_control_structure',
            'smartsection_control.xml'
        ));
    }

    /**
     * @return array
     */
    public function get_fileareas() {
        return [];
    }

    /**
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        return $content;
    }
}
