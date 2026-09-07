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
 * Tab navigation context builder for the SmartSection Control block.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_smartsection_control\output;

/**
 * Builds the template context for the tab bar shared by the plugin's pages.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class navigation {
    /** @var string Manage page tab key. */
    public const TAB_MANAGE = 'manage';

    /** @var string Timeline page tab key. */
    public const TAB_TIMELINE = 'timeline';

    /** @var string Audit history page tab key. */
    public const TAB_HISTORY = 'history';

    /**
     * Return the tab descriptors for the nav_tabs template.
     *
     * @param int    $courseid Course the pages belong to.
     * @param string $active   One of the TAB_* constants.
     * @return array<int, array<string, mixed>> Tab descriptors.
     */
    public static function tabs(int $courseid, string $active): array {
        $pages = [
            self::TAB_MANAGE => [
                'script' => '/blocks/smartsection_control/manage.php',
                'label'  => get_string('manage', 'block_smartsection_control'),
            ],
            self::TAB_TIMELINE => [
                'script' => '/blocks/smartsection_control/timeline.php',
                'label'  => get_string('timeline_view', 'block_smartsection_control'),
            ],
            self::TAB_HISTORY => [
                'script' => '/blocks/smartsection_control/history.php',
                'label'  => get_string('history_view', 'block_smartsection_control'),
            ],
        ];

        $tabs = [];
        foreach ($pages as $key => $page) {
            $tabs[] = [
                'url'    => (new \moodle_url($page['script'], ['id' => $courseid]))->out(false),
                'label'  => $page['label'],
                'active' => ($key === $active),
            ];
        }

        return $tabs;
    }
}
