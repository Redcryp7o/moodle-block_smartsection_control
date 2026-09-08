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
 * Output renderer for the SmartSection Control block.
 *
 * Bridges the data layer and Mustache templates, providing type-safe rendering
 * methods for both the teacher dashboard view and the student timeline view.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_smartsection_control\output;

/**
 * Plugin renderer for block_smartsection_control.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the teacher/manager dashboard block content.
     *
     * @param array $data Template context data (coursename, courseid, stats, manageurl, etc.).
     * @return string Rendered HTML string.
     */
    public function render_teacher_block(array $data): string {
        return $this->render_from_template('block_smartsection_control/teacher_block', $data);
    }

    /**
     * Render the student-facing block content from a dashboard widget.
     *
     * The countdown AMD module is initialised from the Mustache {{#js}} block
     * so it uses the same {{uniqid}} helper as the widget container element.
     *
     * @param dashboard_widget $widget The exportable widget containing upcoming unlocks.
     * @return string Rendered HTML string.
     */
    public function render_block_content(dashboard_widget $widget): string {
        $data = $widget->export_for_template($this);

        return $this->render_from_template('block_smartsection_control/student_timeline', $data);
    }
}
