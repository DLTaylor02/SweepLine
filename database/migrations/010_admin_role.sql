-- Retain existing member accounts and the protected single SuperAdmin.
ALTER TABLE app_users DROP CONSTRAINT app_users_role_check;
ALTER TABLE app_users ADD CONSTRAINT app_users_role_check CHECK(role IN ('superadmin','admin','member'));

