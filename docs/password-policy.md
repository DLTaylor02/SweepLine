# Password workflow decisions

The existing Auth service, app_users table, sessions and app_settings remain authoritative. PasswordPolicy handles policy enforcement for creation, admin reset and self-change; PwnedPasswords handles bounded privacy-preserving range transport. No second authentication system is introduced.

Flag-only mutations do not increment session_version: the existing session must remain valid to verify the current password. Self-change increments session_version and updates the current session after session_regenerate_id(true), signing out other sessions. Other account/security changes retain session invalidation.

The existing internal member role is the displayed User. Admins retain their existing authority over regular Users; SuperAdmin manages privileged accounts. The protected SuperAdmin bypasses configurable policy but not safe input formatting.

Only successful self-service changes clear the flag automatically; authorized administrators can explicitly clear it. Ordinary administrative password resets preserve it. Policy updates never retroactively flag users.

The existing JSON API carries a redirect instruction for restricted authenticated requests; HTML routes use HTTP 303. Session/policy metadata are the minimal exceptions needed to render Change password. Successful self-change uses a one-time session notice rendered as text after redirect, rather than putting messages in URLs.

Existing password storage uses PHP PASSWORD_DEFAULT (adaptive bcrypt today), retaining a 72-byte limit to prevent truncation. Unicode code points count toward configured length. All breach checks occur on submission, never per keystroke. Unavailable screening is not evidence of compromise.

Account mutations move from PATCH to POST for password-related CSRF consistency. Storage-budget PATCH is unrelated and remains compatible.

