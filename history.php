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
 * History/Logging View
 *
 * Shows visibility change history for auditing
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$courseid = \block_smartsection_control\helper::course_id_from($course);
require_login($course);
$context = context_course::instance($courseid);

require_capability('block/smartsection_control:manage', $context);

$PAGE->set_url(new moodle_url('/blocks/smartsection_control/history.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('history_view', 'block_smartsection_control'));
$PAGE->set_heading(format_string($course->fullname) . ' - ' . get_string('history_view', 'block_smartsection_control'));

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$historyquery = \block_smartsection_control\helper::get_history_list_sql($courseid);
$total = $DB->count_records('block_smartsection_control_h', ['courseid' => $courseid]);
$history = $DB->get_records_sql(
    $historyquery['sql'],
    $historyquery['params'],
    $page * $perpage,
    $perpage
);

// Action label to status-pill modifier map, keyed by the stored action value.
$actionmodifiers = [
    'unlocked' => 'ssc-status--created',
    'created'  => 'ssc-status--created',
    'locked'   => 'ssc-status--locked',
    'cleared'  => 'ssc-status--cleared',
    'updated'  => 'ssc-status--updated',
];

$placeholder = '-';
$rows = [];

foreach ($history as $record) {
    if (!empty($record->sectionname)) {
        $sectionname = is_array($record->sectionname)
            ? (get_string('sectionname', 'block_smartsection_control') . ' ' . (int) $record->section)
            : (string) $record->sectionname;
    } else {
        $sectionname = get_string('sectionname', 'block_smartsection_control') . ' ' . (int) $record->section;
    }

    $actionkey = (string) $record->action;

    $rows[] = [
        'sectionname'    => format_string($sectionname),
        'action'         => ucfirst($actionkey),
        'actionmodifier' => $actionmodifiers[$actionkey] ?? 'ssc-status--muted',
        'triggertype'    => $record->trigger_type
            ? ucfirst(str_replace('_', ' ', (string) $record->trigger_type))
            : $placeholder,
        'triggeredby'    => \block_smartsection_control\helper::format_history_actor(
            $record,
            $historyquery['fieldprefix']
        ),
        'hasoldstate'    => (bool) $record->old_state,
        'oldstate'       => ucfirst((string) ($record->old_state ?? '')),
        'hasnewstate'    => (bool) $record->new_state,
        'newstate'       => ucfirst((string) ($record->new_state ?? '')),
        'timecreated'    => userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'placeholder'    => $placeholder,
    ];
}

$templatedata = [
    'tabs' => \block_smartsection_control\output\navigation::tabs(
        $courseid,
        \block_smartsection_control\output\navigation::TAB_HISTORY
    ),
    'title' => get_string('history_view', 'block_smartsection_control'),
    'description' => get_string('history_desc', 'block_smartsection_control'),
    'hasrows' => !empty($rows),
    'emptymessage' => get_string('no_history', 'block_smartsection_control'),
    'headings' => [
        get_string('sectionname', 'block_smartsection_control'),
        get_string('action', 'block_smartsection_control'),
        get_string('trigger_type', 'block_smartsection_control'),
        get_string('triggered_by', 'block_smartsection_control'),
        get_string('old_state', 'block_smartsection_control'),
        get_string('new_state', 'block_smartsection_control'),
        get_string('timecreated', 'block_smartsection_control'),
    ],
    'rows' => $rows,
    'pagingbar' => $OUTPUT->paging_bar(
        $total,
        $page,
        $perpage,
        new moodle_url('/blocks/smartsection_control/history.php', ['id' => $courseid])
    ),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_smartsection_control/history_page', $templatedata);
echo $OUTPUT->footer();
