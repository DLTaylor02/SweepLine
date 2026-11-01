ALTER TABLE app_users ADD COLUMN IF NOT EXISTS must_change_password boolean NOT NULL DEFAULT false;
DO $ BEGIN
    IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='app_users'::regclass AND conname='superadmin_no_forced_password') THEN
        ALTER TABLE app_users ADD CONSTRAINT superadmin_no_forced_password CHECK(role<>'superadmin' OR must_change_password=false);
    END IF;
END $;
INSERT INTO app_settings(key,value) VALUES ('password_policy','{"minimum_length":12,"strength":"standard","screen_compromised":true}') ON CONFLICT(key) DO NOTHING;

