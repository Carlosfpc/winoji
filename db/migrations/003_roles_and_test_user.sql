-- Migration 003: change roles from admin/member to admin/manager/employee
--               and add a test user

-- 1. Map existing 'member' values to 'employee' before changing the ENUM
UPDATE users       SET role = 'employee' WHERE role = 'member';
UPDATE team_members SET role = 'employee' WHERE role = 'member';

-- 2. Change ENUM on users table
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee';

-- 3. Change ENUM on team_members table
ALTER TABLE team_members
    MODIFY COLUMN role ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee';

-- 4. Add test user (password: test123)
INSERT INTO users (name, email, password_hash, role)
VALUES ('Maria Lopez', 'maria@example.com', '$2y$10$uYzoffbsJkN60Stn69hWnOsSzgYR2UYr2OiHmcOHnu0smQsRRIBea', 'manager');

-- 5. Add her to My Team as manager
INSERT INTO team_members (user_id, team_id, role)
VALUES (LAST_INSERT_ID(), 1, 'manager');
