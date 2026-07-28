# Devon — Auth & Roles API contract

Base URL: `http://localhost:8000/api/v1`

Auth: Sanctum **token-based** (`Authorization: Bearer <token>`) — not
cookie-based SPA auth, since the frontend (Vite `:5173`) runs on a
different origin than the API (`:8000`).

Every response that carries a user matches the frontend `SessionUser`
shape exactly:

```ts
{ uuid: string; email: string; fullName: string; roles: Role[]; mustChangePassword: boolean }
```

(`status` and `createdAt` are additive fields used by the admin Users page;
harmless extras for any consumer that only reads the `SessionUser` fields.)

---

## POST `/auth/login`

No auth required.

**Request**
```json
{ "email": "admin@devon.uz", "password": "Demo2026!" }
```

**200 OK**
```json
{
  "token": "1|lv7Y7p8ZAZAcskAQMgQWnqNMF4MsJYSva9cEUrN93f645855",
  "user": {
    "uuid": "a25d504c-e8a7-4793-9ea1-ff05d1fc9b21",
    "email": "admin@devon.uz",
    "fullName": "Platforma administratori",
    "roles": ["ROLE_SUPER_ADMIN"],
    "mustChangePassword": false,
    "status": "ACTIVE",
    "createdAt": "2026-07-28T05:30:28+00:00"
  }
}
```

**401 Unauthorized** — wrong password, unknown email, or a `SUSPENDED` account:
```json
{ "message": "Invalid credentials." }
```

---

## GET `/auth/me`

`auth:sanctum`.

**200 OK** — flat `SessionUser` (no `{"data": ...}` envelope):
```json
{
  "uuid": "a25d504c-e8a7-4793-9ea1-ff05d1fc9b21",
  "email": "admin@devon.uz",
  "fullName": "Platforma administratori",
  "roles": ["ROLE_SUPER_ADMIN"],
  "mustChangePassword": false,
  "status": "ACTIVE",
  "createdAt": "2026-07-28T05:30:28+00:00"
}
```

**401** if the token is missing/invalid/revoked.

---

## POST `/auth/logout`

`auth:sanctum`. Revokes the current access token (only that token — other
sessions/devices stay logged in).

**204 No Content**

---

## POST `/auth/change-password`

`auth:sanctum`.

**Request**
```json
{ "current_password": "Demo2026!", "new_password": "NewPassw0rd!23" }
```

Password policy (shared with the frontend): min 8 chars, upper + lower
case, a digit, a special character.

**204 No Content** on success — server also sets
`must_change_password = false` and stamps `password_changed_at`.

**422 Unprocessable Entity** — wrong current password:
```json
{
  "message": "The current password is incorrect.",
  "errors": { "current_password": ["The current password is incorrect."] }
}
```

(A weak `new_password` produces the standard Laravel validation-error shape
under `errors.new_password`.)

---

## GET `/roles`

`auth:sanctum`. Static catalogue of the 7 Devon roles for the "new user"
dropdown.

**200 OK**
```json
[
  { "code": "ROLE_SUPER_ADMIN", "nameUz": "Bosh administrator" },
  { "code": "ROLE_HR_ADMIN", "nameUz": "Kadrlar administratori" },
  { "code": "ROLE_HR_OPERATOR", "nameUz": "Kadrlar operatori" },
  { "code": "ROLE_UNIT_HEAD", "nameUz": "Bo'linma rahbari" },
  { "code": "ROLE_EMPLOYEE", "nameUz": "Xodim" },
  { "code": "ROLE_AUDITOR", "nameUz": "Auditor" },
  { "code": "ROLE_DEVONXONA", "nameUz": "Devonxona xodimi" }
]
```

---

## GET `/users?search=`

`auth:sanctum` + `can:users.view` (`ROLE_SUPER_ADMIN`, `ROLE_HR_ADMIN`).
`search` matches `email` or `full_name` (optional, case-insensitive
substring).

**200 OK**
```json
[
  {
    "uuid": "...",
    "email": "hr@devon.uz",
    "fullName": "HR Test",
    "roles": ["ROLE_HR_ADMIN"],
    "mustChangePassword": true,
    "status": "ACTIVE",
    "createdAt": "2026-07-28T05:31:36+00:00"
  }
]
```

**403** if the caller lacks `users.view`.

---

## POST `/users`

`auth:sanctum` + `can:users.create` (`ROLE_SUPER_ADMIN`, `ROLE_HR_ADMIN`).

**Request**
```json
{
  "email": "hr@devon.uz",
  "fullName": "HR Test",
  "roles": ["ROLE_HR_ADMIN"],
  "password": "OptionalExplicitPassw0rd!"
}
```
`password` is optional. Omit it to have the server generate a secure
10-character temporary password.

**201 Created** — password omitted (temp password generated):
```json
{
  "user": {
    "uuid": "...",
    "email": "hr@devon.uz",
    "fullName": "HR Test",
    "roles": ["ROLE_HR_ADMIN"],
    "mustChangePassword": true,
    "status": "ACTIVE",
    "createdAt": "..."
  },
  "tempPassword": "+C6^77&9Ez"
}
```
`tempPassword` is returned **once** — never shown/retrievable again. It is
omitted entirely when the caller supplied an explicit `password`. Every
newly-created user starts with `mustChangePassword = true`.

**422** on duplicate email, unknown role code, or a weak explicit password.
**403** if the caller lacks `users.create`.

---

## PATCH `/users/{uuid}`

`auth:sanctum` + `can:users.update`.

**Request** (all fields optional)
```json
{ "fullName": "New Name", "roles": ["ROLE_AUDITOR"], "status": "SUSPENDED" }
```

**200 OK** — updated user (flat shape, same as `/auth/me`).

---

## POST `/users/{uuid}/reset-password`

`auth:sanctum` + `can:users.update`. Generates a new temporary password and
sets `must_change_password = true`.

**200 OK**
```json
{ "tempPassword": "kv8*uF9bXH" }
```

---

## Roles × Permissions (seeded)

| Permission | `ROLE_SUPER_ADMIN` | `ROLE_HR_ADMIN` | others |
|---|:---:|:---:|:---:|
| `users.view` | ✓ | ✓ | – |
| `users.create` | ✓ | ✓ | – |
| `users.update` | ✓ | ✓ | – |
| `users.deactivate` | ✓ | – | – |
| `roles.view` | ✓ | ✓ | – |

Guard: `web` (spatie/laravel-permission) — see
`config/devon_roles.php` for the role → `nameUz` catalogue and
`database/seeders/PermissionsSeeder.php` for the assignment source.
