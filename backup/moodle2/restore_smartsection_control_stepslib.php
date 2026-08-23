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
 * Restore structure step for block_smartsection_control.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore course-scoped section unlock rules (configuration only).
 */
class restore_smartsection_control_block_structure_step extends restore_structure_step {

    /**
     * Define paths processed by this step.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element(
            'smartsection_control_rule',
            '/block/smartsection_control/rules/rule'
        );
        return $paths;
    }

    /**
     * Process one configuration rule row.
     *
     * @param array|object $data
     */
    protected function process_smartsection_control_rule($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;

        if (!$this->task->get_blockid()) {
            return;
        }

        // Avoid double-restore when multiple block instances exist in the course.
        $oldcourseid = $this->task->get_old_courseid();
        if ($this->get_mappingid('block_smartsection_control_course', $oldcourseid, 0)) {
            return;
        }

        $newsectionid = $this->get_mappingid('course_section', (int) $data->sectionid, 0);
        if (!$newsectionid) {
            return;
        }

        if ($DB->record_exists('block_smartsection_control', ['sectionid' => $newsectionid])) {
            return;
        }

        $new = new stdClass();
        $new->courseid = $this->get_courseid();
        $new->sectionid = $newsectionid;
        $new->unlocktime = $this->apply_date_offset((int) ($data->unlocktime ?? 0));
        $new->timecreated = isset($data->timecreated) ? (int) $data->timecreated : time();
        $new->timemodified = time();
        $new->unlocktype = $data->unlocktype ?? 'absolute';
        $new->relativesettings = $data->relativesettings ?? null;
        // CM IDs inside eventconditions are remapped in the task after_restore().
        $new->eventconditions = $data->eventconditions ?? null;
        $new->manualoverride = isset($data->manualoverride) ? (int) $data->manualoverride : 0;
        $new->timezone = $data->timezone ?? 'server';
        $new->locktype = $data->locktype ?? 'hard';
        $new->notifysentfor = 0;

        // Soft Lock + completion is unsupported.
        if (($new->unlocktype === 'event') && ($new->locktype === 'soft')) {
            $new->locktype = 'hard';
        }

        $newid = $DB->insert_record('block_smartsection_control', $new);
        $this->set_mapping('block_smartsection_control', $oldid, $newid);
    }

    /**
     * Mark course as restored so sibling block instances skip duplicate inserts.
     */
    protected function after_execute() {
        if (!$this->task->get_blockid()) {
            return;
        }

        $oldcourseid = $this->task->get_old_courseid();
        if (!$this->get_mappingid('block_smartsection_control_course', $oldcourseid, 0)) {
            $this->set_mapping(
                'block_smartsection_control_course',
                $oldcourseid,
                $this->get_courseid()
            );
        }
    }
}
