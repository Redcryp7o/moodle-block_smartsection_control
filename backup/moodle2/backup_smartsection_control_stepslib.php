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
 * Backup structure step for block_smartsection_control.
 *
 * Backs up course-scoped schedule configuration only.
 * History and per-user pacing records are intentionally excluded.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the smartsection_control structure for backup.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_smartsection_control_block_structure_step extends backup_block_structure_step {
    /**
     * Define the structure to be processed by this backup step.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $smartsection = new backup_nested_element('smartsection_control', ['id'], null);
        $rules = new backup_nested_element('rules');
        $rule = new backup_nested_element('rule', ['id'], [
            'courseid',
            'sectionid',
            'unlocktime',
            'timecreated',
            'timemodified',
            'unlocktype',
            'relativesettings',
            'eventconditions',
            'manualoverride',
            'timezone',
            'locktype',
        ]);

        $smartsection->add_child($rules);
        $rules->add_child($rule);

        $smartsection->set_source_array([(object) ['id' => $this->task->get_blockid()]]);
        $rule->set_source_table('block_smartsection_control', [
            'courseid' => backup::VAR_COURSEID,
        ]);

        $rule->annotate_ids('course_section', 'sectionid');

        return $this->prepare_block_structure($smartsection);
    }
}
