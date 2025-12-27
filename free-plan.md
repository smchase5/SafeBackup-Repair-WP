# SafeBackup & Repair WP – FREE Version Plan

## 1. Overview

**Plugin Name:** SafeBackup & Repair WP  
**Edition:** Free  
**Primary Goals:**

1. Give non-technical users a **simple, safe backup & restore** solution.
2. Provide an **out-of-WordPress Recovery Portal** to fix common crashes:
   - Disable plugins/themes
   - View debug logs
   - Trigger a **full site restore** from the latest backup.
3. Establish a solid technical foundation for the Pro features:
   - Shared architecture (backup engine, recovery portal, job system, etc.).

Free must feel **useful on its own**, not crippleware. Pro should feel like a powerful upgrade, not a necessary fix.

---

## 2. Core Feature Set (Free)

### 2.1 Backup & Restore (Local Only)

- Manual backups (on-demand):
  - **Types:**
    - DB-only
    - DB + `wp-content` (plugins, themes, uploads)
  - Stored locally in:
    - `wp-content/uploads/safebackup/`
- Basic retention:
  - Keep last **3–5 backups** (configurable small range)
  - Old backups auto-pruned.
- One-click restore (from wp-admin):
  - Restore **DB + files** or **DB-only**.
  - Strong confirmation UX and logs.

### 2.2 “Backup Before Updating” Helper

- In wp-admin:
  - A clear button: **“Backup before updating plugins/themes/core”**.
- Behavior:
  - Trigger a full local backup.
  - On success, optionally:
    - Redirect user to the Updates screen or optionally auto-open it.
- This is *not* the full mock-update system – just a pre-update safety net.

### 2.3 Recovery Portal (Out-of-WP UI – Lite)

Accessible when wp-admin is broken.

Features:

1. **Recovery URL**
   - Example: `https://example.com/?sbwp_recovery=1`
   - Or pretty URL: `/sbwp-recovery/` via rewrite rule.

2. **Authentication**
   - Login with existing WordPress admin credentials **or**
   - Single-use/time-limited recovery token generated in wp-admin.

3. **Safe Mode for Portal Requests**
   - For Recovery Portal requests:
     - Filter out most plugins via `option_active_plugins` / `option_active_sitewide_plugins`.
     - Minimal plugin load to reduce crash risk.

4. **Lite Recovery Tools**
   - List all installed plugins:
     - Name, slug, active/inactive status.
     - Actions:
       - Disable / Enable (via option filter or folder rename).
   - List installed themes:
     - Status: active/inactive.
     - Action:
       - Switch active theme to a default (e.g., Twenty Twenty-Five).
   - **View `debug.log`** (last X lines):
     - Basic tail viewer.
     - Simple search filter (string match).

5. **Full Restore from Latest Backup (Free!)**
   - From Recovery Portal:
     - See the **latest backup** (timestamp, type, size).
     - Action:
       - “Restore latest backup (full site)”
   - Behavior:
     - Put site in maintenance mode.
     - Restore DB + files from the newest local backup only.
   - No backup selection UI in free: **always latest backup**.

### 2.4 Basic Status Dashboard

In wp-admin → **SafeBackup & Repair WP → Dashboard**:

- Cards:
  - Last backup date/time, type, size.
  - Number of local backups stored.
  - Debug mode status (on/off).
- Quick actions:
  - “Run backup now”
  - “Open Recovery Portal”
  - “Generate Recovery Token”

---

## 3. Architecture (Free)

### 3.1 Directory Structure

```text
safebackup-repair-wp/
  safebackup-repair-wp.php
  /includes
    class-sbwp-activator.php
    class-sbwp-deactivator.php
    class-sbwp-backup-engine.php
    class-sbwp-restore-manager.php
    class-sbwp-recovery-portal.php
    class-sbwp-admin-ui.php
    class-sbwp-logger.php
    class-sbwp-rest-api.php (optional for admin JS)
  /assets
    /js
    /css
  /templates
    recovery-portal.php
    admin-views/
  /languages
3.2 Main Components
SBWP_Backup_Engine
Responsibilities:

Create DB and file backups.

Store metadata in custom table(s) and/or wp_options.

Handle retention (delete old backups).

Key methods:

create_backup( $args ):

$args includes: type (db, full), note, initiator (manual, pre_update, recovery).

get_backups( $limit = 10 )

delete_backup( $backup_id )

get_backup_file_paths( $backup_id )

SBWP_Restore_Manager
Responsibilities:

Restore DB, files, or both.

Support restore from latest backup (for Recovery Portal) and specific backup (wp-admin UI).

Key methods:

restore_full( $backup_id )

restore_db_only( $backup_id )

restore_files_only( $backup_id )

restore_latest_backup_full() (helper for Recovery Portal)

All restores:

Use maintenance mode during the process.

Log events.

SBWP_Recovery_Portal
Responsibilities:

Route handling and minimal bootstrap for the recovery UI.

Authentication (user credentials or token).

Safe-mode plugin filtering for Recovery Portal.

Key methods:

maybe_route_request() – hooked early.

render_portal() – loads templates/recovery-portal.php.

handle_actions() – handle form submissions like disable plugin, switch theme, restore latest backup.

generate_recovery_token(), validate_recovery_token().

SBWP_Admin_UI
Responsibilities:

Register admin menu & pages.

Render Dashboard, Backups list, Settings (lite).

Wire up backup/restore actions with nonces & capabilities.

4. Database & Options (Free)
4.1 Custom Table: wp_sb_backups
Columns (minimal version):

id (bigint, PK)

created_at (datetime)

type (db, full)

storage_location (local)

file_path_local (text)

size_bytes (bigint)

status (completed, failed)

notes (text)

4.2 Options
sbwp_general_settings (array):

retention_limit (int, default 5)

maintenance_mode_message (string)

sbwp_recovery_tokens (array of tokens with token, expires_at, created_at)

5. Admin UX (Free)
5.1 Menu & Pages
Top-level: SafeBackup & Repair WP

Subpages:

Dashboard

Status cards

Quick actions

Backups

Table of backups:

Date, Type, Size, Status, Actions (Restore, Download, Delete)

Button: “Create Backup Now”

Recovery & Tools

Generate Recovery Token (with copyable URL)

Link to open Recovery Portal

Toggle debug mode (optional)

Basic settings (retention limit, etc.)

Settings

Basic options (retention, log path info, etc.)


### 5.2 Tech Stack (Admin & Recovery Portal)

**Framework & Build:**
- **React** for all Admin interfaces and the Recovery Portal.
- **Vite** for the build system (fast HMR, optimized production builds).
- **Tailwind CSS** for styling (prefix-scoped to avoid WP Admin conflicts).

**UI Library:**
- **shadcn/ui** (built on Radix UI primitives) for accessible, reusable components.
- **Lucide React** for icons.

**Theming & Design:**
- **Mode:** Full **Light & Dark** mode support.
- **Aesthetics:**
  - "Premium" feel: Clean typography (Inter/Geist), subtle borders, refined spacing.
  - **Color Palette:**
    - **NO** purple-to-blue gradients.
    - Use neutral, reliable tones for base UI (Slate/Zinc).
    - Use a distinct primary color (e.g., Emerald, Indigo, or a deep Teal) but avoid the generic "tech gradient" look.
    - High contrast text for readability.

**Integration:**
- The plugin will enqueue a single compiled JS bundle and CSS file for the admin pages.
- A root root DOM element (e.g., `<div id="sbwp-admin-root"></div>`) will be the React entry point.
- The Recovery Portal will be a standalone React app loading the same shared components but with a different entry point.

6. Recovery Portal UX (Free)
Layout goals:

- **Technology**: React + shadcn/ui (reusing the same components as Admin).
- **Theme**: Dark mode by default for "Rescue Mode" (optional, but looks "pro"), or toggleable.
- Clean, distraction-free environment.


Make it obvious you’re in a Rescue Mode environment.

Sections:

Header

Title: “SafeBackup & Repair WP – Recovery Portal”

Site name

Logged in user / token info

Tabs / Navigation

“Plugins & Themes”

“Logs”

“Restore Latest Backup”

Plugins & Themes Section

Table of plugins:

Name, slug, status, checkbox to disable/enable.

Table of themes:

Active theme, other themes, “Activate” button.

Logs Section

debug.log tail (last 200–500 lines).

Simple text search input.

Restore Latest Backup Section

Show info about latest backup:

Timestamp, type, size.

Warning text explaining consequences.

Confirm button with extra confirm prompt (e.g. type RESTORE).

Calls SBWP_Restore_Manager::restore_latest_backup_full().

7. Implementation Phases (Free)
Phase 1 – Scaffold & Core

Plugin bootstrap, activator/deactivator.

Custom table wp_sb_backups.

SBWP_Backup_Engine with DB + file backups to local storage.

Basic Backups UI.

Phase 2 – Restore & Dashboard

SBWP_Restore_Manager with full restore.

Dashboard cards and quick actions.

“Backup before update” helper button.

Phase 3 – Recovery Portal

Recovery route, authentication, safe-mode plugin filtering.

Plugins/themes toggling.

debug.log viewer.

Full restore from latest backup.

Phase 4 – Polish

UX refinements, copy, accessibility.

Hardening restore error handling.

Add hooks to prepare for Pro (e.g. filters/actions to extend UI).