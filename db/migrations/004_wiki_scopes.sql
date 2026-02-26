-- Add scope and project_id to pages for general vs project-specific wiki
ALTER TABLE pages ADD COLUMN scope VARCHAR(20) NOT NULL DEFAULT 'general';
ALTER TABLE pages ADD COLUMN project_id INT NULL DEFAULT NULL;
ALTER TABLE pages ADD FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE;
