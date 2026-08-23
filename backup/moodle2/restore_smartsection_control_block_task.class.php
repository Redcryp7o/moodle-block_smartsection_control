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
 * Restore task for block_smartsection_control.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/smartsection_control/backup/moodle2/restore_smartsection_control_stepslib.php');

/**
 * Specialised restore task for block_smartsection_control.
 */
class restore_smartsection_control_block_task extends restore_block_task {

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
        $this->add_step(new restore_smartsection_control_block_structure_step(
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
     * Remap JSON-stored course-module IDs after activities are restored.
     */
    public function after_restore() {
        global $DB;

        if (!$this->get_blockid()) {
            return;
        }

        $courseid = $this->get_courseid();
        $rules = $DB->get_records('block_smartsection_control', ['courseid' => $courseid]);

        foreach ($rules as $rule) {
            if (empty($rule->eventconditions)) {
                continue;
            }

            $mapper = function(int $oldcmid): int {
                return (int) $this->get_mappingid('course_module', $oldcmid, 0);
            };

            $result = \block_smartsection_control\helper::remap_eventconditions_cmids(
                (string) $rule->eventconditions,
                $mapper
            );
            $remapped = $result['json'];

            if ($result['dropped']) {
                $this->log(
                    'SmartSection Control: completion/grade activity missing after restore for section rule id '
                        . $rule->id . ' — condition marked for teacher review',
                    \backup::LOG_WARNING
                );
                \block_smartsection_control\helper::log_history(
                    (int) $rule->sectionid,
                    $courseid,
                    'updated',
                    'restore_needs_review',
                    0,
                    'event',
                    'needs_review'
                );
            }

            if ($remapped !== (string) $rule->eventconditions) {
                $rule->eventconditions = ($remapped === '') ? null : $remapped;
                $rule->timemodified = time();
                $DB->update_record('block_smartsection_control', $rule);
            }
        }
    }

    /**
     * @return array
     */
    public static function define_decode_contents() {
        return [];
    }

    /**
     * @return array
     */
    public static function define_decode_rules() {
        return [];
    }
}
