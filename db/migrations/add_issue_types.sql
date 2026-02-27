-- Migration: add issue_types table and type_id to issues
-- Run once against the database

CREATE TABLE IF NOT EXISTS issue_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6b7280',
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

ALTER TABLE issues ADD COLUMN IF NOT EXISTS type_id INT DEFAULT NULL AFTER priority;
ALTER TABLE issues ADD CONSTRAINT fk_issues_type FOREIGN KEY (type_id) REFERENCES issue_types(id) ON DELETE SET NULL;

-- Seed 4 default types for every existing project
INSERT INTO issue_types (project_id, name, color, description)
    SELECT id, 'Feature',  '#3b82f6', 'Nueva funcionalidad o mejora'               FROM projects;
INSERT INTO issue_types (project_id, name, color, description)
    SELECT id, 'Bug',      '#ef4444', 'Error o comportamiento incorrecto'           FROM projects;
INSERT INTO issue_types (project_id, name, color, description)
    SELECT id, 'Task',     '#6b7280', 'Tarea de desarrollo o mantenimiento'         FROM projects;
INSERT INTO issue_types (project_id, name, color, description)
    SELECT id, 'Story',    '#8b5cf6', 'Historia de usuario'                         FROM projects;
