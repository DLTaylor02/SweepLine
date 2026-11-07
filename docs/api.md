# API reference

[Documentation index](README.md) | [Project overview](../README.md)

## API

JSON, same-origin sessions, no permissive CORS. Call `GET /api/session` to obtain session state and a CSRF token. Include `X-CSRF-Token` on POST/PATCH requests.

| Endpoint | Purpose |
| --- | --- |
| `GET /api/health` | Public database/PostGIS/schema health |
| `GET /api/profiles` | Public profile definitions |
| `GET /api/session` | Current user, CSRF token, administrator readiness |
| `POST /api/login`, `POST /api/logout` | Web session login/logout |
| `POST /api/search` | Authenticated road discovery and automatic preparation |
| `GET /api/zips/{zip}` | Authenticated local ZIP lookup; 202 during preparation |
| `GET /api/coverage` | Authenticated active coverage |
| `GET /api/roads/123?profile=twisty` | Authenticated road details and score dimensions |
| `GET/POST /api/admin/users` | SuperAdmin user listing/creation |
| `POST /api/admin/users/123` | SuperAdmin enable/disable/password reset |
| `GET /api/admin/data` | SuperAdmin worker/ZIP/task status |
| `POST /api/admin/tasks/123/retry` | SuperAdmin retry of a failed preparation task |

Login uses `{"username":"superadmin","password":"<YOUR_PASSWORD>"}`. SuperAdmin user creation requires a username, name and initial password; email may be omitted. The installer never passes the password in command-line arguments, writes it to `.env`, or prints it; only its hash is stored in the database.

Search request template (replace `<US_ZIP>` with your chosen five-digit US ZIP code):

```json
{"zip":"<US_ZIP>","radius_miles":50,"profile":"twisty","min_length_miles":2,"max_length_miles":20,"max_stops":1,"paved_only":true}
```

200 means local data is ready. 202 includes `preparation` state/progress and any currently available results; poll the same search (the UI does this automatically). A terminal preparation failure is represented by `preparation.state="failed"` with a visible explanation; retry it through SuperAdmin management. Invalid inputs return 422; authentication 401; CSRF/authorization 403; absent resources 404; incorrect methods 405; account conflicts 409; request/queue throttles 429; unavailable infrastructure 503. New or changed User/Admin passwords follow the configurable policy. Terminal provisioning locks against concurrent account creation and never resets an existing administrator. There is no email delivery, self-registration, forgotten-password email flow, yet.

