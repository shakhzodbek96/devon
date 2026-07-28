<?php

/*
|--------------------------------------------------------------------------
| Devon roles catalogue
|--------------------------------------------------------------------------
|
| Single source of truth for the 7 Devon roles: the spatie/laravel-permission
| role name (must match the frontend `Role` union in
| frontend/dashboard/src/types/domain.ts verbatim) mapped to its Uzbek
| display label (mirrors frontend/dashboard/src/i18n/locales/uz.json
| `common.roles.*`). Consumed by RolesSeeder (creates the spatie Role rows)
| and RoleController (GET /roles dropdown response).
|
*/

return [
    'ROLE_SUPER_ADMIN' => 'Bosh administrator',
    'ROLE_HR_ADMIN' => 'Kadrlar administratori',
    'ROLE_HR_OPERATOR' => 'Kadrlar operatori',
    'ROLE_UNIT_HEAD' => "Bo'linma rahbari",
    'ROLE_EMPLOYEE' => 'Xodim',
    'ROLE_AUDITOR' => 'Auditor',
    'ROLE_DEVONXONA' => 'Devonxona xodimi',
];
