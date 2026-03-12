# Agenda - Advanced Calendar Application

## What Is This?

Agenda is a full-stack calendar management application built with PHP, MySQL, and JavaScript. It provides comprehensive event scheduling, multi-user collaboration, and productivity tools — comparable in scope to Google Calendar.

---

## Tech Stack

| Layer    | Technology                          |
|----------|-------------------------------------|
| Backend  | PHP 7.x+, MySQLi (prepared statements) |
| Database | MySQL / MariaDB (utf8mb4)           |
| Frontend | Vanilla JavaScript (ES6+), HTML5, CSS3 |
| Libraries| Marked.js (Markdown rendering)      |
| Fonts    | Google Fonts (Inter)                |

---

## Core Features

### Event Management
- **Create, edit, delete** events with course name, instructor, date/time range, location, notes, and more.
- **Recurrence** — daily, weekly, monthly, or yearly with end date or occurrence count. Individual occurrences can be edited or skipped.
- **Conflict detection** — warns when new events overlap with existing ones.
- **Soft delete with undo** — deleted events are recoverable.
- **Drag-and-drop rescheduling** in weekly/biweekly views.
- **Duplicate events** and **bulk operations** (reschedule, recolor, re-categorize, delete).
- **File attachments** uploaded per event.
- **Markdown-enabled notes** rendered via Marked.js.
- **Priority levels** (low / medium / high) and **deadline tracking**.
- **Version history** — every edit is snapshot for audit and optimistic locking prevents concurrent-edit conflicts.

### Calendar Views
- **Monthly** — traditional grid with event cards and hover overlays for quick add/edit.
- **Weekly** — time-based grid (hours 0–24) with overlapping-event layout.
- **Biweekly** — 14-day time grid.
- **List** — sequential event listing over 7, 14, 30, or 90 days.
- **Jump to date** picker and **Today** button for quick navigation.
- **Collapsible hours** to show only working-hour slots.
- **Current-time indicator** line that updates in real time.

### Multiple Calendars
- Create unlimited calendars per user, each with its own name, color, and group.
- Toggle calendar visibility on or off.
- Archive calendars.
- Share calendars via token-based public links.
- Sidebar mini-calendar and grouped calendar list.

### Search & Filtering
- **Real-time search** across titles, categories, notes, locations, tags, and attendees.
- **Filter panel** with category, priority, color, tag, attendee, location, and date-range filters.
- **AND / OR logic toggle** for combining filters.
- **Saved filter presets** — name and recall your filter combinations.
- **Focus mode** — hides low-priority past events.

### User Accounts & Permissions
- **Registration and login** with hashed passwords (`password_hash` / `password_verify`).
- **Remember-me** cookies (30-day token).
- **User profiles** — display name, avatar upload, timezone, email, password change.
- **Event-level permissions** — grant view or edit access to other users.
- **Calendar sharing** between users.
- **Authentication toggle** (`REQUIRE_AUTH` in config) for single-user or multi-user setups.

### Collaboration
- **Attendees** per event with RSVP tracking (pending / accepted / declined / tentative).
- **Comment threads** on events (Markdown supported).
- **Activity log** — tracks all create / edit / delete actions with user and timestamp.
- **In-app notifications** — bell icon with unread count and notification center.

### Import & Export
- **Export** to CSV, iCal (ICS), SQL backup, PDF (client-side), and static HTML.
- **Import** from CSV files and ICS/iCal feeds (including Google Calendar and Outlook URLs).
- Recurring events are expanded correctly during import.

### REST API
- API key authentication (generate and revoke keys from the profile page).
- Endpoints for listing, getting, creating, updating, and deleting events.
- Health-check ping endpoint.
- CORS headers for cross-origin access.

### Notifications & Reminders
- SMTP email reminders (configurable in `config.php`).
- Twilio SMS reminders (optional).
- In-app notification queue.

### UI & Personalization
- **Dark mode** — toggle persisted in localStorage, respects system preference.
- **Clock format** — 12-hour or 24-hour.
- **Date format** — MDY or DMY.
- **First day of week** — Sunday or Monday.
- **Keyboard shortcuts** modal.
- **Public US holidays** displayed automatically.
- **Zoom URL field** for video-meeting links.
- **Pomodoro timer** built in.
- **Responsive design** — works on desktop, tablet, and mobile.

---

## File Structure

```
agenda/
├── index.php          Main calendar page (HTML + inline config)
├── calendar.php       Backend CRUD, import/export, recurrence logic
├── calendar.js        Frontend rendering, interactions, drag-and-drop
├── style.css          All styling, dark mode, responsive breakpoints
├── ajax.php           AJAX endpoints (time updates, bulk ops, lazy loading)
├── api.php            REST API with key-based auth
├── connection.php     MySQL database connection
├── config.php         Feature flags & SMTP / Twilio credentials
├── auth.php           Session check & authentication guard
├── login.php          Login page
├── register.php       Registration page
├── logout.php         Session destruction
├── profile.php        User profile & API key management
├── setup.sql          Initial database schema
├── plan.md            Original build plan & feature list
├── uploads/           Uploaded attachments & avatars
└── ABOUT.md           This file
```

---

## Database

The application uses a `calendar` database with these tables:

| Table               | Purpose                                      |
|---------------------|----------------------------------------------|
| `appointments`      | All events (30+ columns, soft-delete enabled) |
| `users`             | User accounts and auth tokens                |
| `calendars`         | Per-user calendars with color and grouping   |
| `event_attendees`   | Attendee list and RSVP status per event      |
| `event_comments`    | Discussion threads on events                 |
| `event_history`     | Version snapshots for audit / undo           |
| `event_permissions` | Fine-grained view/edit access per user       |
| `activity_log`      | Audit trail of all user actions              |
| `calendar_shares`   | Calendar sharing between users               |
| `api_keys`          | REST API authentication tokens               |
| `filter_presets`    | Saved search/filter configurations           |
| `webhooks`          | Outbound POST integrations                   |
| `notifications`     | In-app notification queue                    |
| `app_settings`      | Global key-value configuration store         |

Schema migrations happen automatically — `calendar.php` checks for missing columns on each load and adds them via `ALTER TABLE`.

---

## Configuration

Edit **`config.php`** for:

| Setting        | Purpose                                |
|----------------|----------------------------------------|
| `DEV_MODE`     | Enables API key generation and SQL restore |
| `REQUIRE_AUTH` | Toggles login requirement (true/false) |
| `SMTP_*`       | Email reminder settings                |
| `TWILIO_*`     | SMS reminder settings                  |

Edit **`connection.php`** for database host, user, password, and port.

---

## Getting Started

1. **Set up a local server** — WAMP, XAMPP, or similar with PHP and MySQL.
2. **Create the database** — run `setup.sql` to create the `calendar` database and base tables.
3. **Configure the connection** — update `connection.php` with your MySQL credentials.
4. **Open in browser** — navigate to `http://localhost/agenda/` (or your configured path).
5. **Register an account** (if `REQUIRE_AUTH` is enabled) or start using the calendar immediately.

---

## Security

- SQL injection prevention via prepared statements (`bind_param`).
- XSS prevention via `htmlspecialchars()` on output.
- Passwords stored with `password_hash(PASSWORD_DEFAULT)`.
- File upload validation (type and size checks).
- Session-based authentication with optional remember-me tokens.
- Soft deletes preserve data for audit trails.
