<?php

return [
    'admin_user' => env('DASHBOARD_ADMIN_USER', ''),
    'admin_pass' => env('DASHBOARD_ADMIN_PASS', ''),
    'login_path' => env('DASHBOARD_LOGIN_PATH', 'wow/login'),
    'seed_reset_passwords' => env('SEED_RESET_PASSWORDS', false),
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD', 'Admin123!'),
    'seed_user_password' => env('SEED_USER_PASSWORD', 'User123!'),
];
