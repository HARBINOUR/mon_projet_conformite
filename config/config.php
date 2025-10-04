<?php
declare(strict_types=1);

// MAMP via UNIX socket (recommandé)
define('DB_DSN', 'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=chu_collecteur;charset=utf8mb4');
define('DB_USER', 'root');
define('DB_PASS', 'root'); // MAMP par défaut

// Alternative TCP si besoin
// define('DB_DSN', 'mysql:host=127.0.0.1;port=8889;dbname=chu_collecteur;charset=utf8mb4');
// define('DB_USER', 'root');
// define('DB_PASS', 'root');

define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);
define('DATE_TOLERANCE_MINUTES', 0);
define('CHUNK_SIZE', 800);
define('LOG_FILE', __DIR__ . '/../logs/app.log');