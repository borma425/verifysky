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
                'protected_sessions' => 10000,
                'bot_fair_use' => 25000,
            ],
            'upgrade_to' => 'growth',
        ],
        'growth' => [
            'name' => 'Growth',
            'price_monthly' => 19,
            'limits' => [
                'domains' => 2,
                'custom_rules' => 15,
                'protected_sessions' => 30000,
                'bot_fair_use' => 50000,
            ],
            'upgrade_to' => 'pro',
        ],
        'pro' => [
            'name' => 'Pro',
            'price_monthly' => 49,
            'limits' => [
                'domains' => 5,
                'custom_rules' => 50,
                'protected_sessions' => 100000,
                'bot_fair_use' => 100000,
            ],
            'upgrade_to' => 'business',
        ],
        'business' => [
            'name' => 'Business',
            'price_monthly' => 99,
            'limits' => [
                'domains' => 10,
                'custom_rules' => 150,
                'protected_sessions' => 200000,
                'bot_fair_use' => 200000,
            ],
            'upgrade_to' => 'scale',
        ],
        'scale' => [
            'name' => 'Scale',
            'price_monthly' => 249,
            'limits' => [
                'domains' => 25,
                'custom_rules' => 500,
                'protected_sessions' => 500000,
                'bot_fair_use' => 500000,
            ],
            'upgrade_to' => null,
        ],
    ],
];
