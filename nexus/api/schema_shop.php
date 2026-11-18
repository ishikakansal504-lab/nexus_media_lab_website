<?php
// Creates the shop tables if they do not exist yet (safe to run on every request).
// Tables: services, cart_items, orders, order_items, expenses, settings.
function ensure_shop_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'Other',
        description VARCHAR(500) NOT NULL DEFAULT '',
        unit VARCHAR(30) NOT NULL DEFAULT 'one-time',
        price DECIMAL(10,2) NOT NULL,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cart_items (
        user_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        qty SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (user_id, service_id),
        CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_cart_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Orders keep a copy of the customer's name and email, and of every price, so that the
    // sales history stays correct even if a customer is deleted or a price changes later.
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        customer_name VARCHAR(80) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL,
        interest DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL,
        cost_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_method ENUM('card','cod','emi','online') NOT NULL,
        payment_status ENUM('pending','paid','refunded') NOT NULL DEFAULT 'pending',
        status ENUM('placed','confirmed','completed','cancelled') NOT NULL DEFAULT 'placed',
        emi_months TINYINT UNSIGNED NULL,
        emi_monthly DECIMAL(12,2) NULL,
        pay_ref VARCHAR(80) NOT NULL DEFAULT '',
        note VARCHAR(300) NOT NULL DEFAULT '',
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_orders_user (user_id),
        KEY idx_orders_date (created_at),
        CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NULL,
        name VARCHAR(120) NOT NULL,
        unit VARCHAR(30) NOT NULL DEFAULT '',
        unit_price DECIMAL(10,2) NOT NULL,
        unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        qty SMALLINT UNSIGNED NOT NULL,
        KEY idx_items_order (order_id),
        CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_item_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(40) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(200) NOT NULL DEFAULT '',
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_exp_date (expense_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NULL,
        kind VARCHAR(12) NOT NULL,
        to_addr VARCHAR(190) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        status ENUM('sent','failed','not_configured') NOT NULL,
        error VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_notif_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Older databases: the customer's contact and address details were added to orders later.
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['customer_phone' => 'VARCHAR(15)', 'address_line' => 'VARCHAR(200)', 'city' => 'VARCHAR(60)', 'state' => 'VARCHAR(60)', 'pincode' => 'VARCHAR(10)'] as $col => $type) {
        if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $col $type NOT NULL DEFAULT ''");
    }
    $nc = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('channel', $nc, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN channel VARCHAR(10) NOT NULL DEFAULT 'email' AFTER order_id");

    // Default settings (only the ones that are missing).
    $defaults = ['pay_cod' => '1', 'pay_emi' => '1', 'pay_online' => '1', 'online_auto' => '0',
                 'emi_rate' => '0', 'emi_months' => '3,6,9,12', 'emi_min' => '10000',
                 'upi_id' => '', 'qr_file' => '', 'qr_note' => ''];
    $ins = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

    // First time only: the services from the "Work & Prices" page of the website.
    // The cost column is the agency's own cost to deliver. These are starting estimates (60% of the
    // price). The admin edits them on the Services page so that profit and loss are real.
    if ((int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
        $seed = [
            ['Social Media: Starter', 'Social media marketing', 'per month', 9999, '8 posts and 2 reels per month, 1 platform, basic hashtag research, monthly report.'],
            ['Social Media: Growth', 'Social media marketing', 'per month', 19999, '16 posts and 6 reels per month, 2 platforms, content calendar, community replies, fortnightly report.'],
            ['Social Media: Pro', 'Social media marketing', 'per month', 34999, '24 posts and 10 reels per month, 3 platforms, 1 video shoot per month, paid promotion set-up, weekly report.'],
            ['Website: 5-page business site', 'Web development', 'one-time', 15000, 'Responsive 5-page business website, mobile-first, basic SEO set-up.'],
            ['Website: Pro (up to 10 pages)', 'Web development', 'one-time', 30000, 'Up to 10 pages, enquiry forms, speed optimisation.'],
            ['SEO', 'SEO', 'per month', 8000, 'Keyword research, meta descriptions, on-page fixes, monthly report.'],
            ['Email Marketing', 'Email marketing', 'per month', 6000, '2 campaigns per month, template design, subscriber reports.'],
            ['PPC Advertising', 'PPC advertising', 'per month', 7500, 'Campaign set-up, ad creatives, weekly optimisation (ad spend is extra).'],
            ['Photo Shoot', 'Photo and video', 'per session', 3500, 'Product photography session with lighting, backdrop and editing.'],
            ['Video Shoot', 'Photo and video', 'per day', 7000, 'Half-day brand video shoot with camera, lights and crew.'],
            ['Video & Photo Editing', 'Photo and video', 'per video', 1500, 'Reels, ads, colour correction and retouching.'],
        ];
        $st = $pdo->prepare('INSERT INTO services (name, category, unit, price, cost, description, sort_order) VALUES (?,?,?,?,?,?,?)');
        foreach ($seed as $i => $r) $st->execute([$r[0], $r[1], $r[2], $r[3], round($r[3] * 0.6, 2), $r[4], ($i + 1) * 10]);
    }
    $done = true;
}
