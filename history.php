<?php
/**
 * History/Logging View
 *
 * Shows visibility change history for auditing
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

$PAGE->requires->css('/blocks/smartsection_control/styles.css');

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

echo $OUTPUT->header();

echo html_writer::start_div('ssc-page ssc-history ssc-ui');

echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs ssc-nav-tabs', 'role' => 'tablist']);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/manage.php', ['id' => $courseid]),
        get_string('manage', 'block_smartsection_control'),
        ['class' => 'nav-link']
    ),
    ['class' => 'nav-item']
);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/timeline.php', ['id' => $courseid]),
        get_string('timeline_view', 'block_smartsection_control'),
        ['class' => 'nav-link']
    ),
    ['class' => 'nav-item']
);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/history.php', ['id' => $courseid]),
        get_string('history_view', 'block_smartsection_control'),
        ['class' => 'nav-link active', 'aria-current' => 'page']
    ),
    ['class' => 'nav-item']
);
echo html_writer::end_tag('ul');

echo html_writer::start_div('ssc-surface ssc-surface--primary ssc-manager ssc-history-surface');
echo html_writer::start_div('ssc-surface-header ssc-manager-header');
echo html_writer::tag('h2', get_string('history_view', 'block_smartsection_control'), ['class' => 'ssc-surface-title ssc-manager-title']);
echo html_writer::tag('p', get_string('history_desc', 'block_smartsection_control'), ['class' => 'ssc-surface-desc ssc-manager-desc']);
echo html_writer::end_div();

if (empty($history)) {
    echo html_writer::start_div('ssc-empty ssc-history-empty', ['role' => 'status']);
    echo html_writer::div(
        $OUTPUT->pix_icon('i/report', '', 'core', ['class' => 'icon', 'aria-hidden' => 'true']),
        'ssc-empty-icon'
    );
    echo html_writer::tag('p', get_string('no_history', 'block_smartsection_control'), ['class' => 'ssc-empty-title']);
    echo html_writer::end_div();
    echo html_writer::end_div(); // surface
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('ssc-history-table-wrap');

$table = new html_table();
$table->attributes['class'] = 'table align-middle ssc-history-table mb-0';
$table->head = [
    get_string('sectionname', 'block_smartsection_control'),
    get_string('action', 'block_smartsection_control'),
    get_string('trigger_type', 'block_smartsection_control'),
    get_string('triggered_by', 'block_smartsection_control'),
    get_string('old_state', 'block_smartsection_control'),
    get_string('new_state', 'block_smartsection_control'),
    get_string('timecreated', 'block_smartsection_control'),
];
$table->data = [];

foreach ($history as $record) {
    $sectionname = '';
    if (!empty($record->sectionname)) {
        $sectionname = is_array($record->sectionname)
            ? (get_string('sectionname', 'block_smartsection_control') . ' ' . (int) $record->section)
            : (string) $record->sectionname;
    } else {
        $sectionname = get_string('sectionname', 'block_smartsection_control') . ' ' . (int) $record->section;
    }

    $actionkey = (string) $record->action;
    $actiontext = s(ucfirst($actionkey));
    $statusmod = 'ssc-status--muted';
    if ($actionkey === 'unlocked' || $actionkey === 'created') {
        $statusmod = 'ssc-status--created';
    } else if ($actionkey === 'locked') {
        $statusmod = 'ssc-status--locked';
    } else if ($actionkey === 'cleared') {
        $statusmod = 'ssc-status--cleared';
    } else if ($actionkey === 'updated') {
        $statusmod = 'ssc-status--updated';
    }
    $actiondisplay = html_writer::span($actiontext, 'ssc-status ' . $statusmod);

    $triggeredby = s(\block_smartsection_control\helper::format_history_actor(
        $record,
        $historyquery['fieldprefix']
    ));

    $oldstatebadge = $record->old_state
        ? html_writer::span(s(ucfirst((string) $record->old_state)), 'ssc-status ssc-status--info')
        : '-';
    $newstatebadge = $record->new_state
        ? html_writer::span(s(ucfirst((string) $record->new_state)), 'ssc-status ssc-status--updated')
        : '-';

    $table->data[] = [
        format_string($sectionname),
        $actiondisplay,
        $record->trigger_type
            ? s(ucfirst(str_replace('_', ' ', (string) $record->trigger_type)))
            : '-',
        $triggeredby,
        $oldstatebadge,
        $newstatebadge,
        html_writer::tag(
            'small',
            userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            ['class' => 'text-muted']
        ),
    ];
}

echo html_writer::table($table);
echo html_writer::end_div(); // .ssc-history-table-wrap

echo html_writer::div(
    $OUTPUT->paging_bar(
        $total,
        $page,
        $perpage,
        new moodle_url('/blocks/smartsection_control/history.php', ['id' => $courseid])
    ),
    'ssc-history-paging'
);

echo html_writer::end_div(); // .ssc-history-surface
echo html_writer::end_div(); // .ssc-page
echo $OUTPUT->footer();
