<?php
/**
 * Database configuration.
 *
 * Defaults point at the local development MySQL/MariaDB socket (no password:
 * the local server authenticates the operating-system user).
 *
 * On a real host, override with environment variables:
 *   LOG_DB_HOST, LOG_DB_PORT, LOG_DB_NAME, LOG_DB_USER, LOG_DB_PASS
 */
return [
    'db' => [
        // Empty host => connect through the unix socket below.
        'host'        => getenv('LOG_DB_HOST') ?: '',
        'port'        => getenv('LOG_DB_PORT') ?: '3306',
        'name'        => getenv('LOG_DB_NAME') ?: 'log_management',
        'user'        => getenv('LOG_DB_USER') ?: '',
        'pass'        => getenv('LOG_DB_PASS') ?: '',
        'unix_socket' => getenv('LOG_DB_SOCKET') ?: dirname(__DIR__) . '/.tools/mysql-run/mysql.sock',
    ],
];
