# SmartSection Control for Moodle

[![Moodle 5.2](https://img.shields.io/badge/Moodle-5.2-orange.svg)](https://moodle.org)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-green.svg)](http://www.gnu.org/copyleft/gpl.html)

**SmartSection Control** (`block_smartsection_control`) is a modern, enterprise-ready Moodle 5.2 block plugin that provides centralized schedule automation, content drip, and pacing control for course sections. 

It allows instructors and course managers to release course sections automatically using **fixed dates**, **relative time intervals**, or **learner completion triggers**, supporting both **Hard Lock** (hidden from outline) and **Soft Lock** (teaser visible, access restricted) modes with native calendar synchronization, instructor notification alerts with one-click deferrals, and a live upcoming releases countdown widget for learners.

---

## Key Features

### 1. Flexible Section Release Methods
* **Fixed Date & Time (`absolute`):** Release sections on an exact calendar date and time.
* **Relative Timing (`relative`):** Schedule sections dynamically based on:
  * Days after course start date.
  * Days after the previous section's release date.
* **Completion Triggers (`event`):** Automate unlocking when learners meet specific criteria:
  * Activity completion (e.g., submit an assignment, finish a SCORM package, or complete a quiz).
  * Minimum grade threshold on an activity (e.g., score $\ge 80\%$ on Module 1 Quiz).
  * Optional individual learner pacing delay (e.g., unlock 2 days after the trigger activity is completed).

### 2. Dual Lock Enforcement Architecture
* **Hard Lock:** Completely conceals the section from the course outline until its release date/condition is satisfied.
* **Soft Lock (Teaser Mode):** Keeps the section visible in the course outline while restricting access using Moodle's native availability engine (`course_sections.availability`) and server-side URL intercept hooks.
  * *Note:* Soft Lock is supported for fixed-date and relative schedules. Completion triggers use Hard Lock.

### 3. Role-Differentiated Block Interface (Course Pages Only)
* **Learners:** A modern **Upcoming Releases** widget:
  * Shows the next **3 scheduled future sections** in chronological order.
  * **Live countdown timers** with tabular numerals (`2d 10h 29m`, `10h 29m 14s`, `14s`).
  * Automatic progressive card rotation when a section unlocks without requiring a full page refresh.
  * Visual emphasis on the imminent `[NEXT]` release and warm amber alerts when unlocking within 24 hours.
  * Accessible, lightweight design with zero screen reader timer spam (polite announcements on unlock only).
* **Instructors & Managers:** A summary dashboard:
  * Real-time statistics counters (Total, Available, Scheduled, Restricted sections).
  * Quick links to the Section Schedule Manager, Timeline View, and Audit History.

### 4. Comprehensive Schedule Management Board (`manage.php`)
* **Individual Section Accordion:** Intuitive controls to configure release method, dates, lock modes, and conditions per section.
* **Bulk Scheduling Tools:**
  * **Schedule All:** Apply a uniform release date and lock mode across all sections.
  * **Shift Dates:** Move existing fixed-date schedules forward or backward by $N$ days, with an option to skip weekends and holidays.
  * **Apply Interval:** Generate evenly spaced release dates across sections starting from a baseline date.
  * **Release All:** Clear all schedules and restore Hard-Locked sections to visible immediately.
  * **Clear Schedules:** Remove release rules while preserving current section visibility.
* **Immediate Manual Release:** "Release now" action with CSRF-protected POST confirmation.
* **Pacing Guard Warnings:** Alerts instructors when previous-section learner completion is below 70%, suggesting deferral.

### 5. Native Calendar Integration (`classes/calendar.php`)
* Automatically creates and synchronizes course-level calendar events (`eventtype = 'sectionrelease'`).
* Formats events as `{Section name} · Release` for readability in narrow calendar cells.
* Includes custom vector SVG release icon (`pix/sectionrelease.svg`) and scoped event chip styling.
* Fully preserves Moodle's native event popup and modal interactions.

### 6. Instructor Notifications & Delay Links (`send_notifications.php`)
* Daily background sweep detects sections unlocking in the next 24 hours.
* Sends rich HTML notification emails to course instructors summarizing upcoming releases and learner pacing stats.
* Includes signed HMAC delay action links allowing instructors to defer an unlock by 1, 2, or 3 days with one click and explicit confirmation.

### 7. Course Backup & Restore (`backup/moodle2/`)
* Complete Moodle Backup & Restore API integration (`backup_block_task` / `restore_block_task`).
* Remaps section IDs to target course sections during restore.
* Remaps completion and grade trigger course module IDs (`cmid`).
* Flags missing activities with a "Requires Review" safety status (`restore_needs_review`) to prevent orphaned unlocks.
* Safely isolates course configuration from student pacing data and audit logs.

### 8. Course Reset Integration
* Seamless integration with Moodle's Course Reset form.
* Allows instructors to selectively purge audit history or learner pacing timestamps when starting a new cohort.

### 9. Privacy & GDPR Compliance (`classes/privacy/provider.php`)
* Full implementation of the Moodle Privacy Subsystem API.
* Exports learner pacing timestamps and audit history logs.
* Handles data deletion and anonymization requests.

---

## Technical Specifications & Requirements

| Component | Requirement |
|:---|:---|
| **Moodle Version** | Moodle 5.2 (`$plugin->requires = 2026042000;`, `$plugin->supported = [502, 502];`) |
| **Placement** | Course pages only (`applicable_formats`: `'course' => true, 'site' => false, 'my' => false`) |
| **PHP Version** | A PHP version supported by Moodle 5.2 (PHP 8.3+ required by Moodle 5.2 LMS core; `declare(strict_types=1);` compliant) |
| **Additional PHP Extensions** | None beyond those required by Moodle 5.2 and standard PHP runtime (`ext-json` and `ext-hash` are standard built-in) |
| **PHP Settings** | `max_input_vars` $\ge$ 5000 (recommended for large courses with many sections) |
| **Databases** | A database platform supported by Moodle 5.2 (PostgreSQL 16+, MySQL 8.4+, MariaDB 10.11.0+, Microsoft SQL Server 2019+) |
| **Moodle Cron** | Standard scheduled cron execution |

---

## Architecture & Codebase Structure

```
blocks/smartsection_control/
├── amd/
│   ├── build/
│   │   ├── manage.min.js             # Minified management interface AMD bundle
│   │   └── student_widget.min.js     # Minified student live countdown & rotation AMD bundle
│   └── src/
│       ├── manage.js                 # Management UI interactivity (disclosure, form sync)
│       └── student_widget.js         # Dual-interval countdown timer & progressive card rotation
├── backup/
│   └── moodle2/
│       ├── backup_smartsection_control_block_task.class.php
│       ├── backup_smartsection_control_stepslib.php
│       ├── restore_smartsection_control_block_task.class.php
│       └── restore_smartsection_control_stepslib.php
├── classes/
│   ├── output/
│   │   ├── dashboard_widget.php      # Data preparation for student & teacher block widgets
│   │   └── renderer.php              # Plugin renderer bridging Mustache templates
│   ├── privacy/
│   │   └── provider.php              # Moodle Privacy API (GDPR) implementation
│   ├── task/
│   │   ├── check_section_visibility.php  # Scheduled task: enforces visibility (every 5 min)
│   │   └── send_notifications.php        # Scheduled task: instructor release alerts (daily at 08:00)
│   ├── calendar.php                  # Native Moodle Calendar event integration
│   ├── helper.php                    # Core business logic, calculations, date math, HMAC tokens
│   ├── hook_listener.php             # Moodle 5.2 hook listener (before_http_headers intercept)
│   └── observer.php                  # Event observer for activity completion triggers
├── db/
│   ├── access.php                    # Capability definitions
│   ├── events.php                    # Observer registration (course_module_completion_updated)
│   ├── hooks.php                     # Moodle 5.2 output hook registration
│   ├── install.xml                   # XMLDB schema (3 database tables)
│   ├── messages.php                  # Message provider registration (unlock_notification)
│   ├── tasks.php                     # Scheduled task definitions
│   ├── uninstall.php                 # Safe cleanup on plugin uninstallation
│   └── upgrade.php                   # Database upgrade steps and savepoints
├── lang/
│   └── en/
│       └── block_smartsection_control.php  # Localized English string catalog
├── pix/
│   ├── icon.png                      # Standard block icon
│   └── sectionrelease.svg            # Vector calendar release event icon
├── templates/
│   ├── student_timeline.mustache     # Student upcoming releases widget template
│   └── teacher_block.mustache        # Teacher dashboard block template
├── tests/
│   ├── behat/
│   │   └── manage_schedule.feature   # Behat BDD acceptance tests
│   ├── availability_merge_test.php   # PHPUnit: Soft lock availability merging
│   ├── backup_restore_test.php       # PHPUnit: Backup and restore structure & remapping
│   ├── bulk_post_policy_test.php     # PHPUnit: Bulk operations validation & transactions
│   ├── delay_token_test.php          # PHPUnit: HMAC token generation & verification
│   ├── helper_remap_test.php         # PHPUnit: Section ID remapping
│   ├── history_query_test.php        # PHPUnit: Paginated audit history queries
│   ├── manual_unlock_test.php        # PHPUnit: Immediate manual unlock transitions
│   └── section_id_normalize_test.php # PHPUnit: Section resolution & validation
├── block_smartsection_control.php    # Block base class and content dispatcher
├── history.php                       # Paginated audit history view
├── lib.php                           # Global library, enforcement routines, course reset hooks
├── manage.php                        # Section schedule manager & bulk operations
├── settings.php                      # Site administration settings
├── styles.css                        # Scoped CSS for block, manager, student widget & calendar
├── timeline.php                      # Course visual release timeline
└── version.php                       # Plugin version metadata (version=2026071226, release=1.0.0, maturity=MATURITY_STABLE)
```

---

## Database Tables

The plugin creates 3 database tables defined in `db/install.xml`:

1. **`mdl_block_smartsection_control`**: Stores the schedule rules per course section (unlock time, unlock type, lock mode, relative parameters, completion trigger conditions).
2. **`mdl_block_smartsection_control_history`**: Maintains a full audit trail of all scheduling actions, state changes, manual releases, and automated unlocks.
3. **`mdl_block_smartsection_user_unlocks`**: Tracks individual learner pacing unlock timestamps for completion-based triggers.

---

## Capabilities & Permissions

Defined in `db/access.php`:

| Capability | Risk | Allowed Roles (Default) | Description |
|:---|:---|:---|:---|
| `block/smartsection_control:manage` | `RISK_CONFIG` | Manager, Course Creator, Editing Teacher | Configure release schedules, perform bulk operations, and unlock sections. |
| `block/smartsection_control:addinstance` | `RISK_SPAM` / `RISK_XSS` | Manager, Editing Teacher | Add the SmartSection Control block to a course page. |
| `block/smartsection_control:myaddinstance` | None | Prevented (User) | Prevented on standard My / Dashboard pages (course-specific block). |

---

## Installation & Placement

SmartSection Control is a **course-level block**. It operates exclusively within individual course contexts.

1. **Download / Extract:**
   Place the plugin directory inside your Moodle installation under:
   ```
   blocks/smartsection_control
   ```
2. **Execute Upgrade:**
   Log in as an Administrator and navigate to **Site administration $\rightarrow$ Notifications** to complete the installation.
3. **Enable Global Setting (Optional):**
   Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Blocks $\rightarrow$ SmartSection Control** to verify the plugin is globally enabled.
4. **Add Block to Course:**
   * Navigate to the target course.
   * Enable **Edit mode** (top-right toggle).
   * Click **Add a block** in the block drawer / sidebar and select **SmartSection Control**.
   * Instructors can immediately click **Manage schedules** to configure release rules.

---

## Scheduled Tasks (Cron)

SmartSection Control registers two scheduled tasks in `db/tasks.php`:

| Task Class | Schedule | Purpose |
|:---|:---|:---|
| `\block_smartsection_control\task\check_section_visibility` | `*/5 * * * *` (Every 5 min) | Evaluates release timestamps, checks relative rules, enforces section visibility, logs audit entries, and clears locks upon expiry. |
| `\block_smartsection_control\task\send_notifications` | `0 8 * * *` (Daily at 08:00) | Sweeps sections scheduled to unlock in the next 24 hours, checks completion rates, and dispatches email alerts to instructors with HMAC delay buttons. |

To trigger tasks manually via CLI:
```bash
php admin/cli/scheduled_task.php --execute=\\block_smartsection_control\\task\\check_section_visibility
php admin/cli/scheduled_task.php --execute=\\block_smartsection_control\\task\\send_notifications
```

---

## Testing & Quality Assurance

The plugin includes automated tests covering all critical execution paths:

### 1. PHPUnit Tests
Run the PHPUnit test suite:
```bash
vendor/bin/phpunit --testsuite=block_smartsection_control_testsuite
```

Covered areas:
* Soft Lock availability JSON merging and tag preservation.
* Backup and Restore XML structure generation and CM ID remapping.
* HMAC delay token generation, tamper detection, and verification.
* Atomic bulk operations and database transactions.
* Manual unlock state transitions and audit logging.

### 2. Behat Acceptance Tests
Run the Behat acceptance feature:
```bash
vendor/bin/behat --config /path/to/behat.yml --tags @block_smartsection_control
```

---

## Uninstallation

When uninstalled via **Site administration $\rightarrow$ Plugins $\rightarrow$ Plugins overview**:
* Soft Lock availability markers are cleanly stripped.
* Hard-Locked sections are safely restored to visible.
* Associated calendar events are deleted.
* All 3 plugin tables (`block_smartsection_control`, `history`, `user_unlocks`) are dropped.
* Unrelated Moodle availability restrictions and teacher visibility settings remain completely preserved.

---

## License & Support

* **License:** [GNU General Public License v3.0 or later](http://www.gnu.org/copyleft/gpl.html)
* **Author / Maintainer:** EncryptEdge Labs
* **Target Environment:** Moodle 5.2 (LMS)
