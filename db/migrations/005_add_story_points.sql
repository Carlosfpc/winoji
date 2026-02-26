-- Add story_points column to issues (run once)
ALTER TABLE issues ADD COLUMN story_points TINYINT UNSIGNED NULL DEFAULT NULL;
