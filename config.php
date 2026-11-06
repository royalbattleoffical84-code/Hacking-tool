<?php
/**
 * ORVIX PAY — CONFIG
 * ------------------------------------------------------------
 * Do tareeke:
 *  A) InfinityFree: neeche ke '...' wale values seedha edit karo.
 *  B) Render (Docker): Environment Variables me same naam se set karo
 *     (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSL, FAMAPI_KEY, SITE_URL).
 *     Env variable set ho to wahi use hota hai.
 * YOUR_FAMAPI_KEY = FamApi dashboard -> API Hub -> API Keys wali key (sirf server pe rehti hai).
 */
function cfg(string $k, string $default): string { $v = getenv($k); return ($v !== false && $v !== '') ? $v : $default; }

define('DB_HOST', cfg('DB_HOST', 'sql208.infinityfree.com'));
define('DB_PORT', cfg('DB_PORT', '3306'));
define('DB_NAME', cfg('DB_NAME', 'if0_43352573_Test'));
define('DB_USER', cfg('DB_USER', 'if0_43352573'));
define('DB_PASS', cfg('DB_PASS', 'TbMTXVN7LgIl'));
define('DB_SSL',  cfg('DB_SSL', '0') === '1');   // TiDB / Aiven jaise cloud MySQL ke liye 1

define('FAMAPI_KEY',  cfg('FAMAPI_KEY', 'fam_live_a9d435b5a05fed4498a32344fae08a0884db1fe44b41b6d1'));   // <-- EDIT (ya env var)
define('FAMAPI_BASE', 'https://famapi-orcin.vercel.app');

// Render Gmail IMAP bridge (keep the API key secret; never commit this file to GitHub).
define('ORVIX_IMAP_API_URL', rtrim(cfg('ORVIX_IMAP_API_URL', 'https://orvix-pay.onrender.com'), '/'));
define('ORVIX_IMAP_API_KEY', cfg('ORVIX_IMAP_API_KEY', 'ORVIX_ORVIXPAY_9f82a7c1d6e4b390x7p5')); // Keep identical to Render

define('SITE_NAME', 'ORVIX PAY');
define('SITE_URL',  cfg('SITE_URL', 'https://orvixpayoffical.infinityfree.io/'));   // e.g. https://orvixpay.onrender.com (bina trailing slash)
define('TIMEZONE',  'Asia/Kolkata');
define('ORDER_EXPIRY_MIN', 15);
