<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App;

/**
 * Logger fichier minimal.
 */
class Logger
{
    public static function info(string $message, array $context = [])
    {
        self::write('INFO', $message, $context);
    }

    public static function warn(string $message, array $context = [])
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = [])
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context = [])
    {
        $file = (string) (defined('LOG_FILE') ? LOG_FILE : (__DIR__ . '/../logs/app.log'));
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dt = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $line = sprintf(
            "[%s] %s %s %s\n",
            $dt,
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
