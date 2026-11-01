INSERT INTO app_settings(key,value) VALUES ('session_timeout_days','7') ON CONFLICT(key) DO NOTHING;

