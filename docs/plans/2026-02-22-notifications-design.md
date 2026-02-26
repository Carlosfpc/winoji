# Notifications System — Design Document

**Date:** 2026-02-22
**Status:** Approved

## Overview

In-app notification system that alerts team members about activity across the project. Notifications are generated for all project events (issue created/assigned, comments, wiki edits, @mentions). Users see them via a bell icon in the sidebar (with unread badge) and a full notifications page.

## Database

New table `notifications`:

```sql
CREATE TABLE notifications (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  project_id   INT NOT NULL,
  actor_id     INT NOT NULL,
  type         VARCHAR(50) NOT NULL,
  entity_type  VARCHAR(20) NOT NULL,
  entity_id    INT NOT NULL,
  entity_title VARCHAR(255),
  read_at      DATETIME NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_read (user_id, read_at),
  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**`type` values:** `issue_created`, `issue_assigned`, `comment_added`, `page_created`, `page_updated`, `mention`

**Generation rule:** On each event, insert one row per project team member (excluding the actor). @mentions generate an additional `mention` notification for the specific user.

## API

**File:** `app/api/notifications.php`

| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | Last 50 notifications for current user (all, read + unread) |
| `unread_count` | GET | Count of unread notifications only |
| `mark_read` | POST `{id}` | Mark one notification as read |
| `mark_all_read` | POST | Mark all as read |

**Helper `notify_project()`** added to `app/api/activity.php`:
- Receives: `$project_id, $actor_id, $type, $entity_type, $entity_id, $entity_title`
- Fetches all `user_id` from `team_members` for the project
- Excludes the actor
- Bulk-inserts one `notifications` row per member

**Integration points:**
- `issues.php`: create → `issue_created`; update with new `assigned_to` → `issue_assigned`
- `comments.php`: create → `comment_added`; body contains `@name` → extra `mention` for that user
- `pages.php`: create → `page_created`; update → `page_updated`

## Frontend

### Bell icon (sidebar)

- Positioned between project switcher and nav list
- Shows 🔔 with red badge if unread count > 0
- Click toggles a dropdown panel
- Panel content:
  - Up to 20 recent notifications
  - Each row: type icon + description + `timeAgo()` timestamp
  - Unread rows have subtle highlight background
  - "Marcar todas como leídas" button
  - "Ver todas →" link to `/notifications` page
- Click on a notification: marks as read + navigates to entity
  - issue → `?page=issues&open_issue={id}`
  - page → `?page=wiki&open_page={id}`

### Notifications page (`app/pages/notifications.php`)

- Route: `?page=notifications`
- Full paginated list of notifications
- Filter tabs: Todas / No leídas
- Each row: avatar/initials of actor, action description, entity title, relative time
- Unread rows have distinct background

### Polling

- `setInterval` every 30s calls `unread_count`
- If count changes: update badge number
- If panel is open: reload panel content

## Navigation change

- Add `<li><a href="...?page=notifications">Notificaciones</a></li>` to sidebar nav
- Add `'notifications'` to `$allowed` pages in `public/index.php`
