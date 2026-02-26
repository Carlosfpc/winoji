-- users.avatar was VARCHAR(255) which is too short for base64 data URLs
ALTER TABLE users MODIFY COLUMN avatar TEXT DEFAULT NULL;
