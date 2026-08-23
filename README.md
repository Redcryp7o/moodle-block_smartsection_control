# SmartSection Control for Moodle

[![Moodle 5.2](https://img.shields.io/badge/Moodle-5.2-orange.svg)](https://moodle.org)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-green.svg)](http://www.gnu.org/copyleft/gpl.html)

**SmartSection Control** (`block_smartsection_control`) is a Moodle 5.2 block plugin for automated course-section release scheduling, content drip, and learner pacing.

Instructors and course managers can release sections using **fixed dates**, **relative schedules**, or **learner completion and grade triggers**. The plugin supports **Hard Lock** and **Soft Lock** modes, learner-specific pacing, Moodle Calendar synchronization, instructor notifications, audit history, backup and restore, course reset integration, and an Upcoming Releases countdown widget for learners.

SmartSection Control is designed exclusively for **individual Moodle course pages**.

---

## Key Features

### 1. Flexible Section Release Methods

- **Fixed Date & Time (`absolute`)**  
  Release sections on an exact calendar date and time.

- **Relative Timing (`relative`)**  
  Schedule sections dynamically based on:
  - Days after the course start date.
  - Days after the previous section's release date.

- **Completion & Grade Triggers (`event`)**  
  Automatically unlock sections when learners meet configured criteria:
  - Activity completion, such as submitting an assignment, completing a SCORM package, or finishing a quiz.
  - Minimum grade thresholds on an activity.
  - Optional learner-specific pacing delays after the trigger activity is completed.

### 2. Hard Lock and Soft Lock Modes

- **Hard Lock**  
  Completely conceals a section from the course outline until its release condition is satisfied.

- **Soft Lock (Teaser Mode)**  
  Keeps the section visible in the course outline while restricting access through Moodle's native availability system and server-side request handling.

> **Note:** Soft Lock is supported for fixed-date and relative schedules. Completion-triggered schedules use Hard Lock.

### 3. Role-Based Course Block Experience

#### Learners

The **Upcoming Releases** widget:

- Shows the next **3 scheduled future sections** in chronological order.
- Displays live countdown timers such as `2d 10h 29m`, `10h 29m 14s`, or `14s`.
- Progressively rotates cards as sections become available.
- Highlights the next scheduled release.
- Uses accessible countdown behavior that avoids repetitive screen-reader announcements.

#### Instructors and Managers

The course block provides:

- Section status counters.
- Quick access to:
  - **Manage schedules**
  - **Timeline**
  - **Audit History**

### 4. Schedule Management

The centralized management interface supports:

- Per-section release configuration.
- Release method selection.
- Lock mode selection.
- Completion and grade conditions.
- Relative scheduling.
- Learner pacing options.

Bulk actions include:

- **Schedule All** — Apply a shared release date and lock mode across sections.
- **Shift Dates** — Move existing schedules forward or backward by a specified number of days.
- **Apply Interval** — Generate evenly spaced release dates across sections.
- **Release All** — Clear schedules and release sections currently controlled by Hard Lock.
- **Clear Schedules** — Remove release rules while preserving current section visibility.
- **Release Now** — Immediately release a scheduled section using a CSRF-protected confirmation flow.

The management interface also provides pacing warnings when prerequisite completion is low before an upcoming release.

### 5. Moodle Calendar Integration

SmartSection Control integrates with Moodle Calendar to:

- Create course-level section release events.
- Keep release events synchronized with schedule changes.
- Display events in a readable `{Section name} · Release` format.
- Use a dedicated release icon.
- Preserve Moodle's native calendar event interactions.

### 6. Instructor Notifications and Delay Actions

A scheduled task checks for sections due to unlock within the next 24 hours and sends instructors a summary of upcoming releases.

Notifications can include:

- Upcoming section information.
- Learner pacing statistics.
- Signed delay actions allowing instructors to defer a scheduled release by **1, 2, or 3 days** with explicit confirmation.

### 7. Backup and Restore

SmartSection Control integrates with Moodle's Backup and Restore APIs.

During restore, the plugin:

- Remaps section IDs to the restored course.
- Remaps completion and grade trigger course-module IDs (`cmid`).
- Flags rules that reference missing activities with a **Requires Review** state.
- Keeps course scheduling configuration separate from learner pacing records and audit history.

### 8. Course Reset Integration

The plugin integrates with Moodle's Course Reset workflow and allows instructors to selectively purge:

- Audit history.
- Learner pacing timestamps.

Schedule rules remain available unless intentionally changed.

### 9. Moodle Privacy API

SmartSection Control implements Moodle's Privacy Subsystem API for plugin-managed personal data.

Supported privacy operations include:

- User data discovery.
- Data export.
- User-specific deletion.
- Context-based deletion.
- Learner pacing data handling.
- Audit-history data handling.

---

## Requirements

| Component | Requirement |
|---|---|
| **Moodle** | Moodle 5.2 |
| **Component** | `block_smartsection_control` |
| **Placement** | Course pages only |
| **PHP** | A PHP version supported by Moodle 5.2 |
| **Additional PHP Extensions** | None beyond Moodle 5.2 and the standard PHP runtime |
| **PHP Setting** | `max_input_vars >= 5000` recommended for large courses |
| **Database** | A database platform supported by Moodle 5.2 |
| **Cron** | Standard Moodle scheduled cron execution |

Release metadata:

```php
$plugin->component = 'block_smartsection_control';
$plugin->version = 2026082301;
$plugin->requires = 2026042000;
$plugin->supported = [502, 502];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0.0';
```

---

## Installation

### Option 1 — Manual Installation

1. Download or clone the plugin.
2. Place the plugin directory at:

   ```text
   blocks/smartsection_control
   ```

3. Log in to Moodle as an administrator.
4. Navigate to:

   **Site administration → Notifications**

5. Complete the installation or upgrade process.

### Option 2 — Install from ZIP

If installing from a Moodle plugin ZIP package:

1. Navigate to:

   **Site administration → Plugins → Install plugins**

2. Upload the SmartSection Control ZIP package.
3. Follow Moodle's installation prompts.
4. Complete the database upgrade.

---

## Add SmartSection Control to a Course

SmartSection Control is a **course-level block** and is not designed for the Moodle Dashboard, My page, or Site Home.

To add it:

1. Open the target course.
2. Enable **Edit mode**.
3. Select **Add a block**.
4. Choose **SmartSection Control**.
5. Open **Manage schedules** to configure section release rules.

---

## Scheduled Tasks

SmartSection Control registers two scheduled tasks in `db/tasks.php`.

| Task | Schedule | Purpose |
|---|---|---|
| `\block_smartsection_control\task\check_section_visibility` | Every 5 minutes | Evaluates scheduled releases, relative rules, section visibility, and lock expiration. |
| `\block_smartsection_control\task\send_notifications` | Daily at 08:00 | Sends instructor notifications for sections scheduled to unlock within the next 24 hours. |

To execute the tasks manually from Moodle CLI:

```bash
php admin/cli/scheduled_task.php --execute="\\block_smartsection_control\\task\\check_section_visibility"
php admin/cli/scheduled_task.php --execute="\\block_smartsection_control\\task\\send_notifications"
```

A properly configured Moodle cron is required for scheduled releases and instructor notifications.

---

## Capabilities and Permissions

SmartSection Control defines the following capabilities:

| Capability | Default Roles | Purpose |
|---|---|---|
| `block/smartsection_control:manage` | Manager, Course Creator, Editing Teacher | Manage release schedules, bulk actions, and manual releases. |
| `block/smartsection_control:addinstance` | Manager, Editing Teacher | Add the block to a course page. |
| `block/smartsection_control:myaddinstance` | Prevented | Prevents use on standard My / Dashboard pages. |

The plugin validates course context and permissions before allowing schedule-management operations.

---

## Database Tables

The plugin creates three tables. Names below are shown **without the site-specific Moodle database prefix**.

1. **`block_smartsection_control`**  
   Stores section release rules, unlock times, lock modes, relative scheduling settings, and trigger conditions.

2. **`block_smartsection_control_h`**  
   Stores audit history for scheduling actions, manual releases, and automated state changes.

3. **`block_smartsection_control_u`**  
   Stores learner-specific pacing unlock timestamps for completion-based release workflows.

---

## Architecture

```text
blocks/smartsection_control/
├── amd/
│   ├── build/
│   │   ├── manage.min.js
│   │   └── student_widget.min.js
│   └── src/
│       ├── manage.js
│       └── student_widget.js
├── backup/
│   └── moodle2/
│       ├── backup_smartsection_control_block_task.class.php
│       ├── backup_smartsection_control_stepslib.php
│       ├── restore_smartsection_control_block_task.class.php
│       └── restore_smartsection_control_stepslib.php
├── classes/
│   ├── output/
│   │   ├── dashboard_widget.php
│   │   └── renderer.php
│   ├── privacy/
│   │   └── provider.php
│   ├── task/
│   │   ├── check_section_visibility.php
│   │   └── send_notifications.php
│   ├── calendar.php
│   ├── helper.php
│   ├── hook_listener.php
│   └── observer.php
├── db/
│   ├── access.php
│   ├── events.php
│   ├── hooks.php
│   ├── install.xml
│   ├── messages.php
│   ├── tasks.php
│   ├── uninstall.php
│   └── upgrade.php
├── lang/
│   └── en/
│       └── block_smartsection_control.php
├── pix/
│   ├── icon.png
│   └── sectionrelease.svg
├── scripts/
│   └── build_marketplace_zip.ps1
├── templates/
│   ├── student_timeline.mustache
│   └── teacher_block.mustache
├── block_smartsection_control.php
├── history.php
├── lib.php
├── manage.php
├── MARKETPLACE_LISTING.md
├── settings.php
├── styles.css
├── timeline.php
└── version.php
```

---

## Testing

The repository includes PHPUnit and Behat tests for key plugin behavior.

### PHPUnit

From a configured Moodle development environment:

```bash
vendor/bin/phpunit --testsuite=block_smartsection_control_testsuite
```

Test coverage includes:

- Soft Lock availability-tree merging.
- Backup and restore remapping.
- HMAC delay-token generation and verification.
- Bulk operation validation.
- Manual unlock transitions.
- Section ID normalization.
- Audit-history queries.

### Behat

From a configured Moodle Behat environment:

```bash
vendor/bin/behat --config /path/to/behat.yml --tags @block_smartsection_control
```

The Behat feature covers schedule-management workflows.

---

## Uninstallation

When SmartSection Control is uninstalled through Moodle:

- Plugin-owned Soft Lock availability markers are removed.
- Hard-Locked sections controlled by the plugin are restored appropriately.
- Plugin-created calendar events are removed.
- Plugin database tables are removed by Moodle.
- Unrelated availability restrictions are preserved.

As with any production Moodle plugin, create a database backup before uninstalling.

---

## Security and Data Handling

SmartSection Control uses Moodle-native APIs for:

- Authentication.
- Course-context authorization.
- Capability checks.
- CSRF protection.
- Parameter validation.
- Database access.
- Availability restrictions.
- Scheduled tasks.
- Calendar events.
- Notifications.
- Privacy operations.
- Backup and restore.

Signed instructor delay links use HMAC-based verification and require explicit confirmation before state-changing actions are applied.

---

## Support

For bug reports, feature requests, or technical questions, use the GitHub issue tracker:

https://github.com/Redcryp7o/moodle-block_smartsection_control/issues

- **Maintainer:** M. AFZAL RIAZ
- **Organization:** EncryptEdge Labs
- **Repository:** https://github.com/Redcryp7o/moodle-block_smartsection_control
- **Component:** `block_smartsection_control`
- **Target Moodle Version:** 5.2

---

## License

SmartSection Control is licensed under the **GNU General Public License v3.0 or later**.

See the included [`LICENSE`](LICENSE) file for details.
