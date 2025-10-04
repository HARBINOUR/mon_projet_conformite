<?php
declare(strict_types=1);
/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Infra;

use PDO;
use PDOException;
use App\Logger;

/**
 * Connexion PDO MySQL (MAMP).
 * NOTE: Le nom de classe reste "OracleConnection" pour compatibilité.
 */
class OracleConnection
{
    /** @var PDO|null */
    private static ?PDO $pdo = null;

    public static function getPdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        try {
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_CASE               => PDO::CASE_NATURAL,
            ]);
            return self::$pdo = $pdo;
        } catch (PDOException $e) {
            $hint = str_starts_with(DB_DSN, 'mysql:unix_socket=')
                ? 'Vérifie le socket MAMP: /Applications/MAMP/tmp/mysql/mysql.sock'
                : 'Vérifie host=127.0.0.1 port=8889 et les identifiants root/root';
            Logger::error('DB connection failed: ' . $e->getMessage() . ' | ' . $hint);
            throw $e;
        }
    }
}

