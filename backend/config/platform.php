<?php

/**
 * Platform (SaaS seller) billing identity — used on GST tax invoices that YOU
 * raise to tenants for their subscription. Fill from env when you incorporate.
 */
return [
    'name'        => env('PLATFORM_NAME', 'PanelOS Technologies'),
    'gstin'       => env('PLATFORM_GSTIN', ''),
    'pan'         => env('PLATFORM_PAN', ''),
    'address'     => env('PLATFORM_ADDRESS', 'Vadodara, Gujarat'),
    'state'       => env('PLATFORM_STATE', 'Gujarat'),
    'state_code'  => env('PLATFORM_STATE_CODE', '24'),
    'email'       => env('PLATFORM_EMAIL', 'billing@panelos.app'),
    'phone'       => env('PLATFORM_PHONE', ''),
    'hsn_sac'     => env('PLATFORM_SAC', '997331'),   // SAC for software/SaaS
];
