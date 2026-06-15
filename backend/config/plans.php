<?php

/**
 * SaaS subscription plans. Drives super-admin MRR estimate, the trial-gate
 * pricing cards, and (later) plan-based feature gating + Razorpay billing.
 */
return [
    'prices' => [
        'starter'    => 2999,
        'growth'     => 5999,
        'pro'        => 9999,
        'enterprise' => 19999,
    ],

    'limits' => [
        'starter'    => ['users' => 3,   'einvoice' => false],
        'growth'     => ['users' => 10,  'einvoice' => false],
        'pro'        => ['users' => 0,   'einvoice' => true],   // 0 = unlimited
        'enterprise' => ['users' => 0,   'einvoice' => true],
    ],

    'trial_days' => 14,
];
