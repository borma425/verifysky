<?php

return [
    'default' => 'starter',

    'aliases' => [
        'free' => 'starter',
        'standard' => 'starter',
    ],

    'plans' => [
        'starter' => [
            'name' => 'Free',
            'price_monthly' => 0,
            'limits' => [
                'domains' => 1,
                'custom_rules' => 5,
                'protected_sessions' => 5000,
                'bot_fair_use' => 5000,
            ],
            'upgrade_to' => 'growth',
        ],
        'growth' => [
            'name' => 'Starter',
            'price_monthly' => 9,
            'limits' => [
                'domains' => 2,
                'custom_rules' => 15,
                'protected_sessions' => 100000,
                'bot_fair_use' => 100000,
            ],
            'upgrade_to' => 'pro',
        ],
        'pro' => [
            'name' => 'Pro',
            'price_monthly' => 29,
            'limits' => [
                'domains' => 5,
                'custom_rules' => 50,
                'protected_sessions' => 300000,
                'bot_fair_use' => 300000,
            ],
            'upgrade_to' => 'business',
        ],
        'business' => [
            'name' => 'Business',
            'price_monthly' => 99,
            'limits' => [
                'domains' => 10,
                'custom_rules' => 150,
                'protected_sessions' => 1000000,
                'bot_fair_use' => 1000000,
            ],
            'upgrade_to' => 'scale',
        ],
        'scale' => [
            'name' => 'Scale',
            'price_monthly' => 249,
            'limits' => [
                'domains' => 25,
                'custom_rules' => 500,
                'protected_sessions' => 3000000,
                'bot_fair_use' => 3000000,
            ],
            'upgrade_to' => null,
        ],
    ],
];
