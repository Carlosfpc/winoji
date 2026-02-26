-- Migration 003: Add PR tracking columns to branches table
ALTER TABLE branches ADD COLUMN pr_number INT NULL;
ALTER TABLE branches ADD COLUMN pr_url VARCHAR(500) NULL;
