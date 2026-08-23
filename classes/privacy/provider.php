<?php
declare(strict_types=1);
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
 * Privacy subsystem implementation for the SmartSection Control block.
 *
 * Declares all personal data stored, and implements the full set of GDPR export,
 * deletion, and context-discovery operations required by Moodle's Privacy API.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_smartsection_control\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for block_smartsection_control.
 *
 * Personal data is stored in two tables:
 * - block_smartsection_user_unlocks  : per-student pacing unlock timestamps
 * - block_smartsection_control_history : audit trail (records who triggered changes)
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider
{

    /**
     * Describe the types of personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to populate.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {

        // Configuration table — no user identifiers; declared for completeness.
        $collection->add_database_table('block_smartsection_control', [
            'courseid'   => 'privacy:metadata',
            'sectionid'  => 'privacy:metadata',
            'unlocktime' => 'privacy:metadata',
        ], 'privacy:metadata');

        $collection->add_database_table('block_smartsection_user_unlocks', [
            'userid'      => 'privacy:metadata:block_smartsection_user_unlocks:userid',
            'sectionid'   => 'privacy:metadata',
            'courseid'    => 'privacy:metadata',
            'unlocktime'  => 'privacy:metadata:block_smartsection_user_unlocks:unlocktime',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:block_smartsection_user_unlocks');

        $collection->add_database_table('block_smartsection_control_history', [
            'sectionid'    => 'privacy:metadata',
            'courseid'     => 'privacy:metadata',
            'action'       => 'privacy:metadata',
            'trigger_type' => 'privacy:metadata',
            'triggered_by' => 'privacy:metadata:triggered_by',
            'timecreated'  => 'privacy:metadata:timecreated',
        ], 'privacy:metadata');

        return $collection;
    }

    /**
     * Return all course contexts in which the specified user has stored data.
     *
     * @param int $userid The user ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // Personal pacing unlocks.
        $sql     = "SELECT DISTINCT courseid FROM {block_smartsection_user_unlocks} WHERE userid = :userid";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);
        foreach ($records as $record) {
            $context = \context_course::instance((int) $record->courseid, IGNORE_MISSING);
            if ($context) {
                $contextlist->add_from_context($context);
            }
        }

        // Audit history entries triggered by this user.
        $sql     = "SELECT DISTINCT courseid FROM {block_smartsection_control_history} WHERE triggered_by = :userid";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);
        foreach ($records as $record) {
            $context = \context_course::instance((int) $record->courseid, IGNORE_MISSING);
            if ($context) {
                $contextlist->add_from_context($context);
            }
        }

        return $contextlist;
    }

    /**
     * Populate a userlist with all users who have data within the given context.
     *
     * @param userlist $userlist The list to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {block_smartsection_user_unlocks} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]
        );

        $userlist->add_from_sql(
            'triggered_by',
            "SELECT triggered_by AS userid FROM {block_smartsection_control_history} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]
        );
    }

    /**
     * Export all data held about the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved list of contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $userdata = new \stdClass();

            $unlocks = $DB->get_records('block_smartsection_user_unlocks', [
                'courseid' => $context->instanceid,
                'userid'   => $user->id,
            ]);
            if (!empty($unlocks)) {
                $userdata->user_unlocks = $unlocks;
            }

            $history = $DB->get_records('block_smartsection_control_history', [
                'courseid'     => $context->instanceid,
                'triggered_by' => $user->id,
            ]);
            if (!empty($history)) {
                $userdata->history = $history;
            }

            if (!empty($userdata->user_unlocks) || !empty($userdata->history)) {
                \core_privacy\local\request\writer::with_context($context)
                    ->export_data([get_string('pluginname', 'block_smartsection_control')], $userdata);
            }
        }
    }

    /**
     * Delete all data for all users within the given context.
     *
     * @param \context $context The context to purge.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $DB->delete_records('block_smartsection_user_unlocks',    ['courseid' => $context->instanceid]);
        $DB->delete_records('block_smartsection_control_history', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete data for a specific user in their approved contexts.
     *
     * @param approved_contextlist $contextlist Approved list of contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $DB->delete_records('block_smartsection_user_unlocks',    ['courseid' => $context->instanceid, 'userid' => $user->id]);
            $DB->delete_records('block_smartsection_control_history', ['courseid' => $context->instanceid, 'triggered_by' => $user->id]);
        }
    }

    /**
     * Delete data for a list of users within the given context.
     *
     * @param approved_userlist $userlist The approved userlist to delete data for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $params1 = ['courseid' => $context->instanceid] + $userparams;
        $DB->delete_records_select('block_smartsection_user_unlocks',
            "courseid = :courseid AND userid {$usersql}", $params1);

        $params2 = ['courseid' => $context->instanceid] + $userparams;
        $DB->delete_records_select('block_smartsection_control_history',
            "courseid = :courseid AND triggered_by {$usersql}", $params2);
    }
}
