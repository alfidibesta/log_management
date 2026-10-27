<?php
declare(strict_types=1);

/** PDO connection (lazy) + schema bootstrap. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require __DIR__ . '/../config.php';
    $d   = $cfg['db'];
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($d['host'] !== '') {
        $dsn  = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['port'], $d['name']);
        $user = $d['user'] !== '' ? $d['user'] : 'root';
        $pass = $d['pass'];
    } else {
        $dsn  = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $d['unix_socket'], $d['name']);
        $user = $d['user'] !== '' ? $d['user'] : local_os_user();
        $pass = $d['pass'];
    }

    $pdo = new PDO($dsn, $user, $pass, $opt);
    ensure_schema($pdo);

    return $pdo;
}

function local_os_user(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }
    return (string) (getenv('USER') ?: getenv('LOGNAME') ?: 'root');
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        username      VARCHAR(64)  NOT NULL,
        name          VARCHAR(120) NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','personnel') NOT NULL DEFAULT 'personnel',
        active        TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // migrate older databases that still use the 'operator' role name
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($col && strpos((string) $col['Type'], 'personnel') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','personnel','operator') NOT NULL DEFAULT 'personnel'");
        $pdo->exec("UPDATE users SET role = 'personnel' WHERE role = 'operator'");
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','personnel') NOT NULL DEFAULT 'personnel'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name   VARCHAR(60)  NOT NULL,
        active TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_categories_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // One row = one person crossing the main gate carrying belongings,
    // tools, documents, etc. No stock is kept: totals are counted per period
    // and per category.
    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person      VARCHAR(120) NOT NULL,
        dept        VARCHAR(80)  NOT NULL DEFAULT '',
        category_id INT UNSIGNED NOT NULL,
        item        VARCHAR(160) NOT NULL,
        qty         INT UNSIGNED NOT NULL DEFAULT 1,
        type        ENUM('in','out') NOT NULL,
        gate        VARCHAR(40)  NOT NULL DEFAULT 'Main Gate',
        note        VARCHAR(255) NOT NULL DEFAULT '',
        logged_at   DATETIME     NOT NULL,
        user_id     INT UNSIGNED NULL,
        verified    TINYINT(1)   NOT NULL DEFAULT 0,
        verified_by INT UNSIGNED NULL,
        verified_at DATETIME     NULL,
        verify_remark VARCHAR(255) NOT NULL DEFAULT '',
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_logs_logged_at (logged_at),
        KEY idx_logs_type (type),
        KEY idx_logs_category (category_id),
        KEY idx_logs_verified (verified),
        CONSTRAINT fk_logs_category FOREIGN KEY (category_id) REFERENCES categories (id),
        CONSTRAINT fk_logs_user     FOREIGN KEY (user_id)     REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_logs_vby      FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // migration: remark recorded at verification (e.g. belongings not returned)
    if (!$pdo->query("SHOW COLUMNS FROM logs LIKE 'verify_remark'")->fetch()) {
        $pdo->exec("ALTER TABLE logs ADD COLUMN verify_remark VARCHAR(255) NOT NULL DEFAULT '' AFTER verified_at");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(48) NOT NULL,
        v VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    seed_sample_data($pdo);
}

/** One-time sample data so the dashboard is meaningful on first run. */
function seed_sample_data(PDO $pdo): void
{
    $done = $pdo->prepare("SELECT v FROM settings WHERE k = 'seeded'");
    $done->execute();
    if ($done->fetch()) {
        return;
    }

    $cats = ['Tools & Equipment', 'Documents', 'Personal Belongings', 'Spare Parts', 'Others'];
    $ins  = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
    foreach ($cats as $c) {
        $ins->execute([$c]);
    }

    $people = [
        ['Budi Santoso', 'Production'],
        ['Andi Wijaya', 'Maintenance'],
        ['Rina Putri', 'Warehouse'],
        ['Joko Prasetyo', 'Logistics'],
        ['Siti Aminah', 'Quality Control'],
    ];
    $items = [
        [1, 'Cordless drill + battery'],
        [1, 'Digital caliper 300mm'],
        [2, 'Drawing sheet A1 / PO printout'],
        [2, 'Inspection report binder'],
        [3, 'Laptop Lenovo + charger'],
        [3, 'PPE set (helmet, gloves, boots)'],
        [4, 'Bearing 6205 (2 pcs)'],
        [5, 'Personal water bottle'],
    ];

    $log = $pdo->prepare("INSERT INTO logs
        (person, dept, category_id, item, qty, type, gate, note, logged_at, verified, verified_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    mt_srand(20261027);
    for ($i = 6; $i >= 0; $i--) {
        $rows = mt_rand(3, 5);
        for ($n = 0; $n < $rows; $n++) {
            $day     = date('Y-m-d', strtotime("-$i days"));
            $time    = sprintf('%02d:%02d:00', mt_rand(6, 18), mt_rand(0, 59));
            $person  = $people[mt_rand(0, count($people) - 1)];
            $item    = $items[mt_rand(0, count($items) - 1)];
            $type    = mt_rand(0, 1) ? 'in' : 'out';
            $verified = ($i > 1 || mt_rand(0, 1)) ? 1 : 0;
            $log->execute([
                $person[0],
                $person[1],
                $item[0],
                $item[1],
                mt_rand(1, 3),
                $type,
                'Main Gate',
                $type === 'in' ? 'Returned from site' : 'Taken to site',
                "$day $time",
                $verified,
                $verified ? "$day " . sprintf('%02d:%02d:00', mt_rand(19, 21), mt_rand(0, 59)) : null,
            ]);
        }
    }

    $set = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('seeded', '1')");
    $set->execute();
}
