<?php
/**
 * ORVIX PAY — auto schema migration.
 * Runs once (when settings.schema_v < SCHEMA_VERSION). Works on fresh DBs and on
 * DBs created by the first version (adds the new columns/tables). Never throws:
 * every statement is isolated and failures are stored in settings.schema_err.
 */
const ORVIX_SCHEMA_VERSION = 7;

function orvix_migrate(PDO $pdo): void {
    $errors = [];
    $run = function (string $sql, bool $ignore = false) use ($pdo, &$errors) {
        try { $pdo->exec($sql); }
        catch (Throwable $ex) {
            $m = $ex->getMessage();
            // "duplicate column" / "already exists" => already migrated, fine
            if (!$ignore && stripos($m, 'duplicate column') === false && stripos($m, 'already exists') === false) {
                $errors[] = substr($sql, 0, 60) . ' => ' . substr($m, 0, 120);
            }
        }
    };

    $run('CREATE TABLE IF NOT EXISTS merchants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        mobile VARCHAR(20) DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT "merchant",
        status VARCHAR(10) NOT NULL DEFAULT "active",
        api_key VARCHAR(80) NOT NULL UNIQUE,
        famapi_connection_id VARCHAR(120) DEFAULT NULL,
        connected_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $run('CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(40) NOT NULL UNIQUE,
        merchant_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        merchant_ref VARCHAR(100) DEFAULT NULL,
        customer_mobile VARCHAR(20) DEFAULT NULL,
        redirect_url VARCHAR(2000) DEFAULT NULL,
        famapi_order_id VARCHAR(80) NOT NULL,
        upi_id VARCHAR(120) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT "pending",
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        paid_at DATETIME DEFAULT NULL,
        last_checked DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $run('CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(60) NOT NULL PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $run('CREATE TABLE IF NOT EXISTS wallet_txns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        type VARCHAR(20) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        balance_after DECIMAL(12,2) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        ref VARCHAR(60) DEFAULT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $run('CREATE TABLE IF NOT EXISTS plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        days INT NOT NULL DEFAULT 30,
        fee_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        monthly_orders INT NOT NULL DEFAULT 0,
        perks VARCHAR(255) DEFAULT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $run('CREATE TABLE IF NOT EXISTS subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        plan_id INT DEFAULT NULL,
        plan_name VARCHAR(60) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        days INT NOT NULL,
        started_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT "wallet"
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // ---- v3 tables: ads/popups, mystery box, pay links, activity logs
    $run('CREATE TABLE IF NOT EXISTS popups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        body TEXT,
        image_url VARCHAR(500) DEFAULT NULL,
        btn_text VARCHAR(40) DEFAULT NULL,
        btn_url VARCHAR(500) DEFAULT NULL,
        starts_at DATETIME DEFAULT NULL,
        ends_at DATETIME DEFAULT NULL,
        audience VARCHAR(10) NOT NULL DEFAULT "all",
        frequency VARCHAR(10) NOT NULL DEFAULT "daily",
        active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $run('CREATE TABLE IF NOT EXISTS mystery_opens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        day VARCHAR(10) NOT NULL,
        slot INT NOT NULL,
        label VARCHAR(120) DEFAULT NULL,
        rtype VARCHAR(10) DEFAULT NULL,
        rvalue DECIMAL(12,2) NOT NULL DEFAULT 0,
        opened_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $run('CREATE TABLE IF NOT EXISTS paylinks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        code VARCHAR(16) NOT NULL UNIQUE,
        type VARCHAR(10) NOT NULL DEFAULT "fixed",
        title VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) DEFAULT NULL,
        min_amount DECIMAL(12,2) NOT NULL DEFAULT 1,
        active TINYINT NOT NULL DEFAULT 1,
        uses INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $run('CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL DEFAULT 0,
        action VARCHAR(60) NOT NULL,
        detail VARCHAR(255) DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        ua VARCHAR(160) DEFAULT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $run('CREATE TABLE IF NOT EXISTS payment_utrs (
        utr VARCHAR(40) NOT NULL PRIMARY KEY,
        order_id VARCHAR(40) NOT NULL,
        merchant_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        received DECIMAL(12,2) DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        credited_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // old default theme key "classic" (v2) becomes the new ZapUPI-style default "orvix"
    $run('UPDATE merchants SET theme_panel = "orvix" WHERE theme_panel = "classic"', true);
    $run('UPDATE merchants SET theme_pay = "orvix" WHERE theme_pay = "classic"', true);

    // v2 columns (for databases created by v1)
    $cols = [
        ['merchants', 'business_name', 'VARCHAR(120) DEFAULT NULL'],
        ['merchants', 'logo_url',      'VARCHAR(500) DEFAULT NULL'],
        ['merchants', 'webhook_url',   'VARCHAR(500) DEFAULT NULL'],
        ['merchants', 'theme_pay',     'VARCHAR(30) NOT NULL DEFAULT "orvix"'],
        ['merchants', 'theme_panel',   'VARCHAR(30) NOT NULL DEFAULT "orvix"'],
        ['merchants', 'wallet',        'DECIMAL(12,2) NOT NULL DEFAULT 0'],
        ['merchants', 'plan_until',    'DATETIME DEFAULT NULL'],
        ['merchants', 'provider',      'VARCHAR(20) NOT NULL DEFAULT "fampay"'],
        ['merchants', 'ref_code',      'VARCHAR(12) DEFAULT NULL'],
        ['merchants', 'referred_by',   'INT DEFAULT NULL'],
        ['merchants', 'plan_id',       'INT DEFAULT NULL'],
        ['merchants', 'auto_renew',    'TINYINT NOT NULL DEFAULT 0'],
        ['merchants', 'pay_mode',      'VARCHAR(10) NOT NULL DEFAULT "famapi"'],
        ['merchants', 'gm_upi',        'VARCHAR(120) DEFAULT NULL'],
        ['merchants', 'gm_email',      'VARCHAR(190) DEFAULT NULL'],
        ['merchants', 'gm_pass',       'VARCHAR(400) DEFAULT NULL'],
        ['merchants', 'gm_verify',     'VARCHAR(5) NOT NULL DEFAULT "utr"'],
        ['merchants', 'gm_err',        'VARCHAR(160) DEFAULT NULL'],
        ['merchants', 'gm_unlocked',   'TINYINT NOT NULL DEFAULT 0'],
        ['merchants', 'gm_ok',         'TINYINT NOT NULL DEFAULT 0'],
        ['orders',    'pay_method',    'VARCHAR(10) NOT NULL DEFAULT "famapi"'],
        ['orders',    'utr',           'VARCHAR(40) DEFAULT NULL'],
        ['orders',    'utr_at',        'DATETIME DEFAULT NULL'],
        ['orders',    'utr_tries',     'INT NOT NULL DEFAULT 0'],
        ['orders',    'reject_reason', 'VARCHAR(40) DEFAULT NULL'],
        ['paylinks',  'one_time',      'TINYINT NOT NULL DEFAULT 0'],
        ['paylinks',  'deliver_url',   'VARCHAR(500) DEFAULT NULL'],
        ['paylinks',  'deliver_text',  'TEXT'],
        ['paylinks',  'file_name',     'VARCHAR(150) DEFAULT NULL'],
        ['paylinks',  'file_path',     'VARCHAR(80) DEFAULT NULL'],
        ['orders',    'kind',          'VARCHAR(10) NOT NULL DEFAULT "payment"'],
        ['orders',    'fee',           'DECIMAL(10,2) NOT NULL DEFAULT 0'],
        ['orders',    'manual',        'TINYINT NOT NULL DEFAULT 0'],
        ['orders',    'webhook_sent',  'TINYINT NOT NULL DEFAULT 0'],
        ['orders',    'settled',       'TINYINT NOT NULL DEFAULT 0'],
    ];
    foreach ($cols as [$t, $c, $ddl]) $run("ALTER TABLE $t ADD COLUMN $c $ddl");

    // Orders that were already successful before v2 must never be settled (fee/webhook) again
    $run('UPDATE orders SET settled = 1 WHERE status = "success"', true);

    // default plans (only when the plans table is empty) — admin can edit/delete them
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
            $ip = $pdo->prepare('INSERT INTO plans (name, price, days, fee_percent, monthly_orders, perks, active, sort_order) VALUES (?,?,?,?,?,?,1,?)');
            foreach ([['Starter', 149, 30, 0.75, 2000, 'All 12 themes, your logo & name on Cashier page', 1],
                      ['Pro', 299, 30, 0.50, 0, 'Unlimited orders, lowest monthly fee, all themes', 2],
                      ['Pro Yearly', 2499, 365, 0.40, 0, 'Unlimited orders, best rate, all themes', 3]] as $pl) $ip->execute($pl);
        }
    } catch (Throwable $ex) { $errors[] = 'plans seed: ' . substr($ex->getMessage(), 0, 100); }

    foreach ([['merchants', 'idx_ref', 'ref_code'], ['orders', 'idx_m', 'merchant_id'], ['orders', 'idx_s', 'status'], ['wallet_txns', 'idx_wm', 'merchant_id'], ['activity_logs', 'idx_am', 'merchant_id'], ['paylinks', 'idx_pm', 'merchant_id']] as [$t, $i, $c]) {
        $run("CREATE INDEX $i ON $t ($c)", true);
    }

    $run('CREATE UNIQUE INDEX uq_mo ON mystery_opens (merchant_id, day, slot)', true);
    try {
        $upd = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
        $ins = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
        foreach (['schema_v' => (string)ORVIX_SCHEMA_VERSION, 'schema_err' => implode("\n", $errors)] as $k => $v) {
            $upd->execute([$v, $k]);
            if ($upd->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE k = ?'); $chk->execute([$k]);
                if ((int)$chk->fetchColumn() === 0) $ins->execute([$k, $v]);
            }
        }
    } catch (Throwable $ex) { error_log('ORVIX migrate settings: ' . $ex->getMessage()); }
    if ($errors) error_log('ORVIX migrate issues: ' . implode(' | ', $errors));
}
