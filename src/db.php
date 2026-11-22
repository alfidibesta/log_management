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
        role          ENUM('admin','personnel','leader') NOT NULL DEFAULT 'personnel',
        active        TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Roles: admin (everything) · leader (approves document take-outs) ·
    // personnel (record & verify). Older databases may still say 'operator'.
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($col && strpos((string) $col['Type'], "'leader'") === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','personnel','operator','leader') NOT NULL DEFAULT 'personnel'");
        $pdo->exec("UPDATE users SET role = 'personnel' WHERE role = 'operator'");
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','personnel','leader') NOT NULL DEFAULT 'personnel'");
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
        person_type ENUM('personnel','visitor','contractor') NOT NULL DEFAULT 'personnel',
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
        KEY idx_logs_person_type (person_type),
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

    // migration: exception register (src/exceptions.php). A remark that says the
    // belongings did not come back stands the crossing up as an open case, and
    // somebody has to close it — so the state lives on the row, next to the
    // remark that opened it.
    if (!$pdo->query("SHOW COLUMNS FROM logs LIKE 'exception_state'")->fetch()) {
        $pdo->exec("ALTER TABLE logs
            ADD COLUMN exception_state ENUM('none','open','closed') NOT NULL DEFAULT 'none' AFTER verify_remark,
            ADD COLUMN exception_note VARCHAR(255) NOT NULL DEFAULT '' AFTER exception_state,
            ADD COLUMN exception_closed_at DATETIME NULL AFTER exception_note,
            ADD COLUMN exception_closed_by INT UNSIGNED NULL AFTER exception_closed_at,
            ADD KEY idx_logs_exception (exception_state)");
    }

    // One row = one document taken out of the site. The scan/photo is stored
    // compressed (≈100–200 KB JPEG) in the row itself, so shared hosting needs
    // no writable upload folder. Approval is done by a leader or an admin.
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person        VARCHAR(120) NOT NULL,
        person_type   ENUM('personnel','visitor','contractor') NOT NULL DEFAULT 'personnel',
        dept          VARCHAR(80)  NOT NULL DEFAULT '',
        doc_no        VARCHAR(80)  NOT NULL DEFAULT '',
        title         VARCHAR(160) NOT NULL,
        purpose       VARCHAR(180) NOT NULL DEFAULT '',
        copies        INT UNSIGNED NOT NULL DEFAULT 1,
        taken_at      DATETIME     NOT NULL,
        note          VARCHAR(255) NOT NULL DEFAULT '',
        photo         MEDIUMBLOB   NULL,
        photo_mime    VARCHAR(40)  NOT NULL DEFAULT '',
        photo_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
        file_name     VARCHAR(160) NOT NULL DEFAULT '',
        file_mime     VARCHAR(80)  NOT NULL DEFAULT '',
        file_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
        file_data     MEDIUMBLOB   NULL,
        status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        approved_by   INT UNSIGNED NULL,
        approved_at   DATETIME     NULL,
        approval_note VARCHAR(255) NOT NULL DEFAULT '',
        user_id       INT UNSIGNED NULL,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_documents_status (status),
        KEY idx_documents_taken (taken_at),
        KEY idx_documents_person (person),
        KEY idx_documents_person_type (person_type),
        CONSTRAINT fk_documents_user     FOREIGN KEY (user_id)     REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_documents_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // migration: an attached PDF of the document itself (the paper rather
    // than a photo of it). Added after the table shipped, so old databases
    // get the columns without a manual step.
    if (!$pdo->query("SHOW COLUMNS FROM documents LIKE 'file_data'")->fetch()) {
        $pdo->exec("ALTER TABLE documents
            ADD COLUMN file_name  VARCHAR(160) NOT NULL DEFAULT '' AFTER photo_bytes,
            ADD COLUMN file_mime  VARCHAR(80)  NOT NULL DEFAULT '' AFTER file_name,
            ADD COLUMN file_bytes INT UNSIGNED NOT NULL DEFAULT 0 AFTER file_mime,
            ADD COLUMN file_data  MEDIUMBLOB   NULL AFTER file_bytes");
    }

    // migration: distinguish employees, visitors and contractor workers
    foreach (['logs', 'documents'] as $table) {
        if (!$pdo->query("SHOW COLUMNS FROM {$table} LIKE 'person_type'")->fetch()) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN person_type ENUM('personnel','visitor','contractor') NOT NULL DEFAULT 'personnel' AFTER person");
        }
    }

    // Visitor / contractor register: reception checks them in here, the gate
    // officer logs their belongings and documents against the same name, and
    // reception checks them out on the way home.
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitors (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(120) NOT NULL,
        person_type ENUM('visitor','contractor') NOT NULL DEFAULT 'visitor',
        company     VARCHAR(120) NOT NULL DEFAULT '',
        id_no       VARCHAR(60)  NOT NULL DEFAULT '',
        phone       VARCHAR(40)  NOT NULL DEFAULT '',
        host        VARCHAR(120) NOT NULL DEFAULT '',
        purpose     VARCHAR(180) NOT NULL DEFAULT '',
        badge_no    VARCHAR(40)  NOT NULL DEFAULT '',
        time_in     DATETIME     NOT NULL,
        time_out    DATETIME     NULL,
        note        VARCHAR(255) NOT NULL DEFAULT '',
        user_id     INT UNSIGNED NULL,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_visitors_time_in (time_in),
        KEY idx_visitors_time_out (time_out),
        KEY idx_visitors_name (name),
        CONSTRAINT fk_visitors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(48) NOT NULL,
        v VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Audit trail (src/audit.php): who verified / corrected / approved or
    // deleted a record, with the changed values before and after, a reason and
    // a timestamp. Rows are insert-only, so a gate entry stays defensible as
    // evidence. The operator's name is copied in, so history survives a
    // rename, a role change or a disabled account.
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        entity      VARCHAR(24)  NOT NULL,
        entity_id   INT UNSIGNED NULL,
        target      VARCHAR(160) NOT NULL DEFAULT '',
        action      VARCHAR(24)  NOT NULL,
        reason      VARCHAR(255) NOT NULL DEFAULT '',
        before_json TEXT         NULL,
        after_json  TEXT         NULL,
        user_id     INT UNSIGNED NULL,
        username    VARCHAR(64)  NOT NULL DEFAULT '',
        user_name   VARCHAR(120) NOT NULL DEFAULT '',
        ip          VARCHAR(45)  NOT NULL DEFAULT '',
        created_at  DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_audit_entity (entity, entity_id, created_at),
        KEY idx_audit_created (created_at)
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
