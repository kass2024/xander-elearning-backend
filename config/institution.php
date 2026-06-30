<?php

return [
    'signup_fee_cents' => (int) env('INSTITUTION_SIGNUP_FEE_CENTS', 9900),
    'signup_currency' => env('INSTITUTION_SIGNUP_CURRENCY', 'usd'),
    'signup_product_name' => env('INSTITUTION_SIGNUP_PRODUCT_NAME', 'Partner Institution Platform Access'),
    /** Demo / QA partner accounts may log in even when payment_status is unpaid. */
    'demo_partner_email_suffix' => env('INSTITUTION_DEMO_EMAIL_SUFFIX', '.demo'),
    'demo_partner_slugs' => array_filter(array_map('trim', explode(',', env(
        'INSTITUTION_DEMO_SLUGS',
        'acme-language-academy,global-scholars-partner'
    )))),
    /** When false, unpaid partners are never blocked at login (reminders only). */
    'block_login_for_unpaid_payment' => filter_var(
        env('INSTITUTION_BLOCK_LOGIN_FOR_UNPAID', false),
        FILTER_VALIDATE_BOOL
    ),
];
