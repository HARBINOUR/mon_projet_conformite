<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Logger;
use App\Services\CsvParser;
use App\Services\OracleService;
use App\Services\ComparisonService;
use PDOException;

final class ApiController extends Controller
{
    public function upload(): void
    {
        // Réponses API toujours en JSON, sans bruit
        header_remove();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        ini_set('display_errors', '0');
        ob_start();

        $tempPath = null;

        try {
            // --- Validation input fichier
            if (!isset($_FILES['file'])) {
                $this->badRequest('NO_FILE', 'Fichier manquant (champ "file").');
                return;
            }

            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->badRequest('UPLOAD_ERROR', 'Erreur de téléversement.');
                return;
            }

            if (($file['size'] ?? 0) <= 0) {
                $this->badRequest('EMPTY_FILE', 'Fichier vide.');
                return;
            }

            if ($file['size'] > (int)UPLOAD_MAX_SIZE) {
                $this->json(['error' => ['code' => 'FILE_TOO_LARGE', 'max' => (int)UPLOAD_MAX_SIZE]], 413);
                return;
            }

            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                $this->json(['error' => ['code' => 'INVALID_EXTENSION', 'message' => 'Extension .csv requise']], 415);
                return;
            }

            // Validation MIME
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string)($finfo->file($file['tmp_name']) ?: '');
            $allowed = ['text/plain', 'text/csv', 'application/vnd.ms-excel'];
            if (!in_array($mime, $allowed, true)) {
                $this->json(['error' => ['code' => 'INVALID_MIME', 'got' => $mime]], 415);
                return;
            }

            // Stockage temporaire hors webroot
            $tempPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                        'upload_' . bin2hex(random_bytes(6)) . '.csv';

            if (!@move_uploaded_file($file['tmp_name'], $tempPath)) {
                $this->serverError('TMP_STORE_FAILED');
                return;
            }

            // --- Parsing CSV
            $tParseStart = microtime(true);
            $parser = new CsvParser($tempPath);
            $rows = $parser->parse(); // tableau normalisé
            $tParseMs = (int)round((microtime(true) - $tParseStart) * 1000);

            if (!is_array($rows) || count($rows) === 0) {
                $this->json(['error' => ['code' => 'NO_ROWS', 'message' => 'Aucune ligne lisible']], 422);
                return;
            }

            // Extrait les paires (id, origineinter) et déduplique
            $actesToFetch = array_map(
                static fn($r) => [
                    'id' => $r['acte_id'] ?? null,
                    'origineinter' => $r['origineinter'] ?? null
                ],
                $rows
            );
            // Filtre les entrées invalides et supprime les doublons
            $actesToFetch = array_values(array_unique(array_filter($actesToFetch, static fn($a) => 
                !empty($a['id']) && !empty($a['origineinter'])
            ), SORT_REGULAR));

            if (count($actesToFetch) === 0) {
                $this->json(['error' => ['code' => 'NO_IDS', 'message' => 'Aucune paire (acte_id, origineinter) valide détectée.']], 422);
                return;
            }

            // Garde-fou volumétrie
            $maxIds = 20000;
            if (count($actesToFetch) > $maxIds) {
                $this->json(['error' => ['code' => 'TOO_MANY_IDS', 'limit' => $maxIds]], 413);
                return;
            }

            // --- Requête DB
            $oracle = new OracleService(); // backend DB réel, MySQL dans ta stack actuelle
            $tSqlStart = microtime(true);
            $oracleRows = $oracle->fetchActsByIdsChunked($actesToFetch, (int)CHUNK_SIZE);
            $tSqlMs = (int)round((microtime(true) - $tSqlStart) * 1000);

            // --- Comparaison métier
            $cmp = new ComparisonService((int)DATE_TOLERANCE_MINUTES);
            $result = $cmp->compare($rows, $oracleRows);

            // --- Logs de synthèse
            Logger::info('Upload traité', [
                'total_csv'   => $result['total_csv'] ?? count($rows),
                'found'       => $result['total_found'] ?? null,
                'missing'     => $result['total_missing'] ?? null,
                't_parse_ms'  => $tParseMs,
                't_sql_ms'    => $tSqlMs,
                'mime'        => $mime,
                'size'        => (int)$file['size'],
            ]);

            // Purge tout output parasite puis réponse JSON propre
            @ob_end_clean();
            $this->json([
                'ok' => true,
                'stats' => [
                    't_parse_ms' => $tParseMs,
                    't_sql_ms'   => $tSqlMs,
                ],
                'result' => $result,
            ], 200);
        } catch (PDOException $e) {
            @ob_end_clean();
            Logger::error('DB error', ['msg' => $e->getMessage()]);
            $this->json(['error' => ['code' => 'DB_UNAVAILABLE', 'message' => 'Base indisponible']], 502);
        } catch (\Throwable $e) {
            @ob_end_clean();
            Logger::error('API upload error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erreur interne']], 500);
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
