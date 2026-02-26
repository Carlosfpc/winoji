# Team App — Design Document
**Date:** 2026-02-21

## Overview

Internal team application combining wiki/documentation (Notion-style) and task management (Jira-style) with GitHub integration. Built for internal use by a single team, with user and team management.

**Stack:** PHP vanilla + HTML/CSS/JavaScript vanilla + MySQL
**Architecture:** Hybrid — PHP server-renders page shells, dynamic content loads via AJAX to PHP API endpoints.

---

## Architecture

### Request Flow
1. User accesses a URL → PHP renders the page shell (nav, sidebar, container)
2. Dynamic content (task lists, kanban, wiki) loads via `fetch()` from `/api/`
3. Mutations (create issue, move card, edit wiki) go via AJAX to `/api/`
4. PHP validates session on every request (pages and API)

### Folder Structure
```
/app
  /api          → PHP endpoints (JSON responses for AJAX)
  /pages        → Server-rendered PHP pages
  /includes     → Helpers, DB connection, auth middleware
  /assets
    /css
    /js         → Vanilla JS modules per feature
/config         → DB config, GitHub token, etc.
/public         → index.php (entry point), public assets
/tests          → Test scripts and seed data
  seed.sql
```

---

## Modules & Features

### 1. Auth & Users
- Login/logout with PHP sessions
- Roles: `admin` and `member`
- Admin can invite users, create teams, assign roles
- User profile with avatar and GitHub account connection

### 2. Wiki / Documentation
- Nested pages (page → subpages), Notion-style
- Rich-text editor with `contenteditable` + toolbar (bold, headers, lists, code)
- Auto-save via AJAX every 30 seconds
- Simple version history (save snapshots to DB)

### 3. Tasks / Kanban (Jira-style)
- Projects with Kanban board (columns: To Do, In Progress, Review, Done)
- Issues with: title, description, assignee, priority, labels, due date
- List view and board view
- Filters by assignee, priority, label

### 4. GitHub Integration
- Connect GitHub repo via Personal Access Token
- Create a branch directly from an issue (`feature/issue-42-name`)
- View PRs and commits associated with an issue
- Webhooks to update issue status when PR is merged

### 5. Team Management
- Workspace with team name and logo
- Invite members by email
- View all members and their roles

---

## Database Schema (MySQL)

```sql
-- Users and team
users         (id, name, email, password_hash, avatar, github_token, role, created_at)
team          (id, name, logo, created_at)
team_members  (user_id, team_id, role)

-- Wiki
pages         (id, title, parent_id, content, created_by, updated_at, created_at)
page_versions (id, page_id, content, saved_by, created_at)

-- Tasks
projects      (id, name, description, github_repo, created_by, created_at)
issues        (id, project_id, title, description, status, priority,
               assigned_to, created_by, due_date, created_at)
labels        (id, name, color, project_id)
issue_labels  (issue_id, label_id)
comments      (id, issue_id, user_id, content, created_at)

-- GitHub
github_repos  (id, project_id, repo_full_name, access_token)
branches      (id, issue_id, branch_name, created_by, created_at)
```

**Key relationships:**
- A `project` can be linked to a GitHub repo
- An `issue` can have multiple `labels`, `comments`, and `branches`
- `pages` nest via `parent_id` (recursive tree)

---

## Security & Error Handling

### Authentication & Sessions
- Passwords with PHP `password_hash()` / `password_verify()`
- Sessions with `session_regenerate_id()` on login (prevents session fixation)
- Auth middleware on every page and API endpoint — redirects to login if no active session

### Endpoint Protection
- All `/api/` endpoints validate session, return `401` if not authenticated
- Role-based permission checks (admin vs member) before sensitive operations
- Input sanitized with `htmlspecialchars()` and PDO prepared statements (prevents XSS and SQL injection)

### GitHub Token
- `github_token` stored encrypted in DB (`openssl_encrypt`)
- Never exposed to the frontend

### Error Handling
- API endpoints return JSON with uniform structure: `{ success, data, error }`
- PHP errors logged to file, never displayed to user in production
- JS shows friendly error messages in the UI when API returns an error

---

## Testing

- **Manual testing:** Checklist of critical flows (login, create issue, move kanban card, create GitHub branch, edit wiki) before each release
- **PHP test scripts:** Simple scripts in `/tests/` calling API endpoints and verifying responses using plain PHP `assert()`
- **Seed data:** `/tests/seed.sql` with sample users, projects, and issues for development
