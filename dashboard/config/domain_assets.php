<?php

return [
    'trial_plan' => 'pro',
    'trial_days' => 14,
    'quarantine_days' => 30,
    'public_suffix_list_path' => resource_path('data/public_suffix_list.dat'),
    'shared_suffixes' => [
        'vercel.app',
        'netlify.app',
        'github.io',
        'pages.dev',
        'workers.dev',
        'firebaseapp.com',
        'web.app',
        'onrender.com',
    ],
];
