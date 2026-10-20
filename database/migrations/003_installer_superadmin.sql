-- Preserve existing accounts, passwords, roles and sessions while introducing username login.
ALTER TABLE app_users ADD COLUMN username text;
UPDATE app_users SET username=CASE WHEN role='superadmin' THEN 'superadmin' ELSE 'user-'||id::text END;
ALTER TABLE app_users ALTER COLUMN username SET NOT NULL;
ALTER TABLE app_users ADD CONSTRAINT app_users_username_format CHECK(username ~ '^[a-z0-9][a-z0-9_.-]{2,63}$');
CREATE UNIQUE INDEX app_users_username_unique ON app_users(lower(username));
ALTER TABLE app_users ALTER COLUMN email DROP NOT NULL;
-- Browser account claiming is no longer supported.
DELETE FROM app_settings WHERE key='setup_token_hash';
