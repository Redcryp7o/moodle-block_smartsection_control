<?php
/**
 * Timeline Visualization Page
 *
 * Displays a visual timeline of section unlock dates
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

$PAGE->set_url(new moodle_url('/blocks/smartsection_control/timeline.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('timeline_view', 'block_smartsection_control'));
$PAGE->set_heading(format_string($course->fullname) . ' - ' . get_string('timeline_view', 'block_smartsection_control'));

global $DB, $OUTPUT;

$PAGE->requires->css('/blocks/smartsection_control/styles.css');

$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();

$unlockdata = [];
$now = time();

foreach ($sections as $section) {
    if (!\block_smartsection_control\helper::is_valid_section_info($section)) {
        continue;
    }

    $sectionid = \block_smartsection_control\helper::resolve_section_id_from_info($section, $courseid);
    $record = $DB->get_record('block_smartsection_control', ['sectionid' => $sectionid]);

    if (!$record) {
        continue;
    }

    $name = \block_smartsection_control\helper::get_section_display_name_from_info($course, $section);
    $unlocktime = \block_smartsection_control\helper::calculate_unlock_time($record, $course);
    $locktype = $record->locktype ?? 'hard';
    $locklabel = ($locktype === 'soft')
        ? get_string('locktype_soft_short', 'block_smartsection_control')
        : get_string('locktype_hard_short', 'block_smartsection_control');

    $needsreview = false;
    $eventconditions = [];
    if (($record->unlocktype ?? '') === 'event' && !empty($record->eventconditions)) {
        $eventconditions = json_decode($record->eventconditions, true);
        if (is_array($eventconditions) && !empty($eventconditions['restore_needs_review'])
                && empty($eventconditions['activity_completion'])
                && empty($eventconditions['grade_threshold'])) {
            $needsreview = true;
        }
    }

    if ($needsreview) {
        $unlockdata[] = [
            'section' => (int) $section->section,
            'name' => $name,
            'unlocktime' => 0,
            'status' => 'needsreview',
            'locktype' => $locktype,
            'locklabel' => $locklabel,
            'rulelabel' => get_string('unlocktype_event', 'block_smartsection_control'),
            'metaline' => get_string('status_needs_review', 'block_smartsection_control') . ' · ' . $locklabel,
            'sort' => PHP_INT_MAX - 1,
        ];
        continue;
    }

    $rulelabel = get_string('unlocktype_absolute', 'block_smartsection_control');
    if (($record->unlocktype ?? '') === 'relative') {
        $rulelabel = get_string('unlocktype_relative', 'block_smartsection_control');
    } else if (($record->unlocktype ?? '') === 'event') {
        $rulelabel = get_string('unlocktype_event', 'block_smartsection_control');
    } else if (($record->unlocktype ?? '') === 'none') {
        continue;
    }

    if ($unlocktime) {
        $status = 'locked';
        if ($record->manualoverride || ($unlocktime <= $now)) {
            $status = 'unlocked';
        } else if ($unlocktime > $now) {
            $status = 'scheduled';
        }

        $unlockdata[] = [
            'section' => (int) $section->section,
            'name' => $name,
            'unlocktime' => (int) $unlocktime,
            'status' => $status,
            'locktype' => $locktype,
            'locklabel' => $locklabel,
            'rulelabel' => $rulelabel,
            'metaline' => userdate((int) $unlocktime, get_string('strftimedate', 'langconfig'))
                . ' · ' . $rulelabel . ' · ' . $locklabel,
            'sort' => (int) $unlocktime,
        ];
    } else if (($record->unlocktype ?? '') === 'event') {
        $unlockdata[] = [
            'section' => (int) $section->section,
            'name' => $name,
            'unlocktime' => 0,
            'status' => 'scheduled',
            'locktype' => $locktype,
            'locklabel' => $locklabel,
            'rulelabel' => $rulelabel,
            'metaline' => $rulelabel . ' · ' . $locklabel,
            'sort' => PHP_INT_MAX - 2,
        ];
    } else if (($record->unlocktype ?? '') === 'relative') {
        $unlockdata[] = [
            'section' => (int) $section->section,
            'name' => $name,
            'unlocktime' => 0,
            'status' => 'scheduled',
            'locktype' => $locktype,
            'locklabel' => $locklabel,
            'rulelabel' => $rulelabel,
            'metaline' => $rulelabel . ' · ' . $locklabel,
            'sort' => PHP_INT_MAX - 3,
        ];
    }
}

usort($unlockdata, static function(array $a, array $b): int {
    if ($a['sort'] !== $b['sort']) {
        return $a['sort'] <=> $b['sort'];
    }
    return $a['section'] <=> $b['section'];
});

echo $OUTPUT->header();

echo html_writer::start_div('ssc-page ssc-timeline ssc-ui');

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
        ['class' => 'nav-link active', 'aria-current' => 'page']
    ),
    ['class' => 'nav-item']
);
echo html_writer::tag('li',
    html_writer::link(
        new moodle_url('/blocks/smartsection_control/history.php', ['id' => $courseid]),
        get_string('history_view', 'block_smartsection_control'),
        ['class' => 'nav-link']
    ),
    ['class' => 'nav-item']
);
echo html_writer::end_tag('ul');

echo html_writer::start_div('ssc-surface ssc-surface--primary ssc-manager ssc-timeline-surface');
echo html_writer::start_div('ssc-surface-header ssc-manager-header');
echo html_writer::tag('h2', get_string('timeline_view', 'block_smartsection_control'), ['class' => 'ssc-surface-title ssc-manager-title']);
echo html_writer::tag('p', get_string('timeline_desc', 'block_smartsection_control'), ['class' => 'ssc-surface-desc ssc-manager-desc']);
echo html_writer::end_div();

$legenditems = [
    ['class' => 'ssc-status-unlocked', 'label' => get_string('unlocked', 'block_smartsection_control')],
    ['class' => 'ssc-status-scheduled', 'label' => get_string('scheduled', 'block_smartsection_control')],
    ['class' => 'ssc-status-locked', 'label' => get_string('locked', 'block_smartsection_control')],
    ['class' => 'ssc-status-warning', 'label' => get_string('status_needs_review', 'block_smartsection_control')],
];

echo html_writer::start_div('ssc-timeline-legend', ['role' => 'note']);
foreach ($legenditems as $item) {
    echo html_writer::start_span('ssc-legend-item');
    echo html_writer::span('', 'ssc-status-dot ' . $item['class'], ['aria-hidden' => 'true']);
    echo html_writer::span($item['label'], 'ssc-legend-label');
    echo html_writer::end_span();
}
echo html_writer::end_div();

if (empty($unlockdata)) {
    echo html_writer::start_div('ssc-empty ssc-timeline-empty', ['role' => 'status']);
    echo html_writer::div(
        $OUTPUT->pix_icon('i/calendar', '', 'core', ['class' => 'icon', 'aria-hidden' => 'true']),
        'ssc-empty-icon'
    );
    echo html_writer::tag('p', get_string('no_timeline', 'block_smartsection_control'), ['class' => 'ssc-empty-title']);
    echo html_writer::end_div();
    echo html_writer::end_div(); // surface
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('ssc-roadmap', ['role' => 'list']);

foreach ($unlockdata as $data) {
    $statusclass = $data['status'] === 'needsreview' ? 'scheduled' : $data['status'];
    $statuslabel = $data['status'] === 'needsreview'
        ? get_string('status_needs_review', 'block_smartsection_control')
        : get_string($data['status'], 'block_smartsection_control');
    $statusmod = 'ssc-status--' . ($data['status'] === 'needsreview' ? 'warning' : $data['status']);

    echo html_writer::start_div('ssc-roadmap-node ' . $statusclass, ['role' => 'listitem']);
    echo html_writer::div('', 'ssc-roadmap-marker', ['aria-hidden' => 'true']);

    echo html_writer::start_div('ssc-roadmap-content');
    echo html_writer::start_div('ssc-roadmap-main');
    echo html_writer::tag('h3', format_string($data['name']), ['class' => 'ssc-roadmap-title']);
    echo html_writer::tag('p', s($data['metaline']), ['class' => 'ssc-roadmap-meta']);
    echo html_writer::end_div();

    echo html_writer::span($statuslabel, 'ssc-status ' . $statusmod);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div(); // surface
echo html_writer::end_div();
echo $OUTPUT->footer();
