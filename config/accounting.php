<?php

return [
    'organization' => [
        'name' => env('FRC_ORGANIZATION_NAME', 'Faizan Rehabilitation Centre'),
        'short_name' => env('FRC_ORGANIZATION_SHORT_NAME', 'FRC'),
        'logo_path' => env('FRC_ORGANIZATION_LOGO', 'images/frc-logo.png'),
    ],
    'currency' => [
        'code' => env('ACCOUNTING_CURRENCY_CODE', 'PKR'),
        'symbol' => env('ACCOUNTING_CURRENCY_SYMBOL', 'Rs.'),
        'decimals' => (int) env('ACCOUNTING_CURRENCY_DECIMALS', 2),
    ],
    'date_format' => env('ACCOUNTING_DATE_FORMAT', 'd M Y'),
    'initial_opening_date' => env('ACCOUNTING_INITIAL_OPENING_DATE', '2026-01-01'),
    'fiscal_year' => [
        'start_month' => (int) env('ACCOUNTING_FISCAL_START_MONTH', 1),
        'start_day' => (int) env('ACCOUNTING_FISCAL_START_DAY', 1),
    ],
    'approvals' => [
        'purchase_order_threshold' => env('PURCHASE_ORDER_APPROVAL_THRESHOLD', '150000.00'),
        'payment_threshold' => env('PAYMENT_APPROVAL_THRESHOLD', '150000.00'),
        'purchase_order_standard_policy' => env('PURCHASE_ORDER_STANDARD_APPROVAL_POLICY', 'authorized_approver'),
        'delegation_max_days' => (int) env('APPROVAL_DELEGATION_MAX_DAYS', 14),
    ],
    'numbering' => [
        'purchase_order_prefix' => env('PURCHASE_ORDER_NUMBER_PREFIX', 'PO'),
        'purchase_order_padding' => (int) env('PURCHASE_ORDER_NUMBER_PADDING', 5),
        'purchase_order_reset' => env('PURCHASE_ORDER_NUMBER_RESET', 'yearly'),
    ],
    'budget_warning_percentage' => (int) env('BUDGET_WARNING_PERCENTAGE', 80),
];
