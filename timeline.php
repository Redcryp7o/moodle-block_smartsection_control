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
 * Timeline Visualization Page
 *
 * Displays a visual timeline of section unlock dates
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

$PAGE->set_url(new moodle_url('/blocks/smartsection_control/timeline.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('timeline_view', 'block_smartsection_control'));
$PAGE->set_heading(format_string($course->fullname) . ' - ' . get_string('timeline_view', 'block_smartsection_control'));

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
        if (
            is_array($eventconditions) && !empty($eventconditions['restore_needs_review'])
                && empty($eventconditions['activity_completion'])
                && empty($eventconditions['grade_threshold'])
        ) {
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

usort($unlockdata, static function (array $a, array $b): int {
    if ($a['sort'] !== $b['sort']) {
        return $a['sort'] <=> $b['sort'];
    }
    return $a['section'] <=> $b['section'];
});

// Build the template context: presentation markup lives in the Mustache template.
$nodes = [];
foreach ($unlockdata as $data) {
    $isneedsreview = ($data['status'] === 'needsreview');
    $nodes[] = [
        'statusclass' => $isneedsreview ? 'scheduled' : $data['status'],
        'statusmodifier' => 'ssc-status--' . ($isneedsreview ? 'warning' : $data['status']),
        'statuslabel' => $isneedsreview
            ? get_string('status_needs_review', 'block_smartsection_control')
            : get_string($data['status'], 'block_smartsection_control'),
        'name' => format_string($data['name']),
        'metaline' => $data['metaline'],
    ];
}

$templatedata = [
    'tabs' => \block_smartsection_control\output\navigation::tabs(
        $courseid,
        \block_smartsection_control\output\navigation::TAB_TIMELINE
    ),
    'title' => get_string('timeline_view', 'block_smartsection_control'),
    'description' => get_string('timeline_desc', 'block_smartsection_control'),
    'legend' => [
        ['dotclass' => 'ssc-status-unlocked', 'label' => get_string('unlocked', 'block_smartsection_control')],
        ['dotclass' => 'ssc-status-scheduled', 'label' => get_string('scheduled', 'block_smartsection_control')],
        ['dotclass' => 'ssc-status-locked', 'label' => get_string('locked', 'block_smartsection_control')],
        ['dotclass' => 'ssc-status-warning', 'label' => get_string('status_needs_review', 'block_smartsection_control')],
    ],
    'hasnodes' => !empty($nodes),
    'emptymessage' => get_string('no_timeline', 'block_smartsection_control'),
    'nodes' => $nodes,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_smartsection_control/timeline_page', $templatedata);
echo $OUTPUT->footer();
