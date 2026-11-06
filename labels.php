<?php
/** All visible menu/section words live here (ZapUPI-style terms). Change a word once, it changes site-wide. */
$GLOBALS['ORVIX_LABELS'] = [
    'register'     => 'Register',
    'login'        => 'Login',
    'logout'       => 'Logout',
    'dashboard'    => 'Dashboard',
    'merchant'     => 'Merchant',
    'cashier'      => 'Cashier',
    'connect'      => 'Connect Merchant',
    'transactions' => 'Transactions',
    'wallet'       => 'API Wallet',
    'api_token'    => 'API Token',
    'docs'         => 'API Docs',
    'ai'           => 'AI Assistant',
    'themes'       => 'Themes',
    'premium'      => 'Plans & Subscription',
    'refer'        => 'Refer & Earn',
    'settings'     => 'Settings',
    'test_payment' => 'Test Payment',
    'admin'        => 'Admin Panel',
    'order_id'     => 'Order ID',
    'amount'       => 'Amount',
    'status'       => 'Status',
    'pay_now'      => 'Pay Now',
    'scan_pay'     => 'Scan & Pay',
];
function t(string $k): string { return $GLOBALS['ORVIX_LABELS'][$k] ?? $k; }
