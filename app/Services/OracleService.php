<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Services;

use App\Infra\OracleConnection;
use App\Repositories\ActeRepository;

/**
 * Service d'accès Oracle en batch.
 */
class OracleService
{
    /**
     * @param array<int,array{id:string, origineinter:string}> $actes
     * @return array<int,array<string,mixed>>
     */
    public function fetchActsByIdsChunked(array $actes, int $chunkSize = 800): array
    {
        if (!$actes) return [];

        $pdo = OracleConnection::getPdo();
        $repo = new ActeRepository($pdo);

        $all = [];
        foreach (array_chunk($actes, max(1, $chunkSize)) as $chunk) {
            $rows = $repo->fetchByActeIds($chunk);
            if ($rows) {
                $all = array_merge($all, $rows);
            }
        }
        return $all;
    }
}
