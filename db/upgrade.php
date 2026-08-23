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
 * SmartSection Control database upgrade steps.
 *
 * @package    block_smartsection_control
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin database schema.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool True on success.
 */
function xmldb_block_smartsection_control_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // -----------------------------------------------------------------------
    // Version 2026060401 — Sprint 0: added locktype column and user_unlocks.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026060401) {

        // Add locktype column to block_smartsection_control.
        $table = new xmldb_table('block_smartsection_control');
        $field = new xmldb_field('locktype', XMLDB_TYPE_CHAR, '20', null, false, null, 'hard', 'timezone');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create block_smartsection_user_unlocks table.
        $table = new xmldb_table('block_smartsection_user_unlocks');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('unlocktime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('idx_userid_section', XMLDB_INDEX_NOTUNIQUE, ['userid', 'sectionid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026060401, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071101 — Sprint 1: architecture stabilisation.
    //   1. Deduplicate user_unlocks rows before adding unique constraint.
    //   2. Replace non-unique idx_userid_section with a UNIQUE index.
    //   3. Drop the unused previewmode column (preview stored in user prefs).
    //   4. Migrate stale calendar events to correct block component format.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071101) {

        // -- Step 1: Deduplicate block_smartsection_user_unlocks --------------
        // Keep the row with the earliest unlock time per (userid, sectionid) pair.
        // This prevents the unique index addition from failing on any existing
        // duplicate rows created by a rare race condition.
        $sql = "SELECT MIN(id) AS keepid, userid, sectionid
                  FROM {block_smartsection_user_unlocks}
              GROUP BY userid, sectionid
                HAVING COUNT(*) > 1";

        $duplicates = $DB->get_records_sql($sql);
        foreach ($duplicates as $dup) {
            $DB->delete_records_select(
                'block_smartsection_user_unlocks',
                'userid = :userid AND sectionid = :sectionid AND id != :keepid',
                ['userid' => $dup->userid, 'sectionid' => $dup->sectionid, 'keepid' => $dup->keepid]
            );
        }

        // -- Step 2: Replace non-unique index with unique index ---------------
        $table    = new xmldb_table('block_smartsection_user_unlocks');
        $oldindex = new xmldb_index('idx_userid_section', XMLDB_INDEX_NOTUNIQUE, ['userid', 'sectionid']);

        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        $newindex = new xmldb_index('idx_userid_section', XMLDB_INDEX_UNIQUE, ['userid', 'sectionid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // -- Step 3: Drop unused previewmode column ---------------------------
        // Preview mode state is stored in Moodle user preferences, not in this column.
        $table2 = new xmldb_table('block_smartsection_control');
        $field  = new xmldb_field('previewmode');
        if ($dbman->field_exists($table2, $field)) {
            $dbman->drop_field($table2, $field);
        }

        // -- Step 4: Migrate calendar events to correct component format ------
        // Events were incorrectly created with modulename='smartsection_control'
        // (not a registered Moodle module). Migrate to the standard block pattern:
        // component='block_smartsection_control', modulename=''.
        $DB->execute(
            "UPDATE {event} SET component = :component, modulename = '' WHERE modulename = :modulename",
            ['component' => 'block_smartsection_control', 'modulename' => 'smartsection_control']
        );

        upgrade_block_savepoint(true, 2026071101, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071201 — Sprint 3: workflow validation and hardening.
    //   1. One-time garbage collection: purge orphaned records for any courses
    //      or sections that were deleted without triggering instance_delete().
    //      This covers sites upgraded from earlier versions.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071201) {

        // Remove block_smartsection_control rows for deleted courses or sections.
        $DB->execute("
            DELETE FROM {block_smartsection_control}
             WHERE courseid NOT IN (SELECT id FROM {course})
                OR sectionid NOT IN (SELECT id FROM {course_sections})
        ");

        // Remove history rows for deleted courses or sections.
        $DB->execute("
            DELETE FROM {block_smartsection_control_history}
             WHERE courseid NOT IN (SELECT id FROM {course})
                OR sectionid NOT IN (SELECT id FROM {course_sections})
        ");

        // Remove per-student pacing rows for deleted courses or sections.
        $DB->execute("
            DELETE FROM {block_smartsection_user_unlocks}
             WHERE courseid NOT IN (SELECT id FROM {course})
                OR sectionid NOT IN (SELECT id FROM {course_sections})
        ");

        upgrade_block_savepoint(true, 2026071201, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071208 — Production hardening: notification dedupe marker.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071208) {
        $table = new xmldb_table('block_smartsection_control');
        $field = new xmldb_field('notifysentfor', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'locktype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Soft Lock + completion is unsupported: coerce existing rows to Hard Lock.
        $DB->execute(
            "UPDATE {block_smartsection_control}
                SET locktype = 'hard'
              WHERE unlocktype = 'event'
                AND (locktype = 'soft' OR locktype IS NULL OR locktype = '')"
        );

        upgrade_block_savepoint(true, 2026071208, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071209 — Staging-readiness: no schema change; savepoint only.
    // Runtime ownership/cron-enabled behaviour ships with this version.
    // Soft+event → Hard already applied in 2026071208; re-assert for late installs.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071209) {
        $converted = $DB->count_records_select(
            'block_smartsection_control',
            "unlocktype = 'event' AND (locktype = 'soft' OR locktype IS NULL OR locktype = '')"
        );
        if ($converted > 0) {
            $DB->execute(
                "UPDATE {block_smartsection_control}
                    SET locktype = 'hard'
                  WHERE unlocktype = 'event'
                    AND (locktype = 'soft' OR locktype IS NULL OR locktype = '')"
            );
        }

        upgrade_block_savepoint(true, 2026071209, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071210 — Staging runtime fixes: no schema change; savepoint only.
    // Section ID normalization, atomic save, admin settings dedupe, timeline UI.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071210) {
        upgrade_block_savepoint(true, 2026071210, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071211 — Staging runtime consistency: type model, No rule UX,
    // Moodle 5.2 section visibility API, history logging. No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071211) {
        upgrade_block_savepoint(true, 2026071211, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071212 — Manual unlock dispatch fix, history fullname fields.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071212) {
        upgrade_block_savepoint(true, 2026071212, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071213 — History SQL: correct core_user\fields get_sql usage.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071213) {
        upgrade_block_savepoint(true, 2026071213, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071214 — Save workflow: explicit action=save; form wraps rows.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071214) {
        upgrade_block_savepoint(true, 2026071214, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071215 — Responsive Manage/Timeline/History UI polish.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071215) {
        upgrade_block_savepoint(true, 2026071215, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071216 — Final verification: CQ specificity, Soft+event UX.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071216) {
        upgrade_block_savepoint(true, 2026071216, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071217 — Fixed-date default, enterprise copy, UI polish.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071217) {
        upgrade_block_savepoint(true, 2026071217, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071218 — Premium accordion Section Schedule Manager UI.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071218) {
        upgrade_block_savepoint(true, 2026071218, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071219 — Unified premium design system (Bulk surface).
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071219) {
        upgrade_block_savepoint(true, 2026071219, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071220 — Design polish: Bulk density, tabs, empty states.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071220) {
        upgrade_block_savepoint(true, 2026071220, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071221 — Final audit packaging/CI/Behat string hygiene.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071221) {
        upgrade_block_savepoint(true, 2026071221, 'smartsection_control');
    }

    // -----------------------------------------------------------------------
    // Version 2026071222 — Marketplace listing assets (pix icon) + docs.
    // No schema change.
    // -----------------------------------------------------------------------
    if ($oldversion < 2026071222) {
        upgrade_block_savepoint(true, 2026071222, 'smartsection_control');
    }

    return true;
}
