<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * This class is licensed under the MIT License. See the LICENSE file for rights.
 */

namespace App\Core;

/**
 * URL utility to compute a clean base path.
 */
class UrlHelper
{
    /**
     * Computes the application's base path from server variables.
     * It removes any trailing '/public' segment to allow clean URLs
     * whether the webroot points to the project root or the public directory.
     *
     * @return string The normalized base path (e.g., '' or '/myapp').
     */
    public static function getBasePath(): string
    {
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        
        return preg_replace('`[/\\\\]public$`', '', $scriptDir) ?? '';
    }
}