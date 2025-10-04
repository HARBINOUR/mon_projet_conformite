<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Services;

use App\Logger;

/**
 * Lecture CSV en flux avec validation d'en-têtes.
 */
class CsvParser
{
    /** @var string */
    private $path;

    /** En-têtes exactes attendues (normalisées en minuscule) */
    const HEADERS = [
        'date_acte',
        'num_intervention',
        'num_venue',
        'acte_id',
        'code_acte',
        'activite_ou_coeff',
        'type_acte'
    ];

    private const HEADER_LINE_DISPLAY = 'DATE_ACTE;NUM_INTERVENTION;NUM_VENUE;ACTE_ID;CODE_ACTE;ACTIVITE_OU_COEFF;TYPE_ACTE';

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @return array<int, array<string, string>>
     * @throws \RuntimeException
     */
    public function parse(): array
    {
        $file = new \SplFileObject($this->path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(';');

        // Lire et valider l'entête
        $header = $file->fgetcsv();
        if ($header === false || count($header) < count(self::HEADERS)) {
            throw new \RuntimeException('En-têtes CSV invalides ou manquants.');
        }
        $header = array_map(function ($h) {
            return strtolower(trim((string)$h));
        }, $header);
        if ($header !== self::HEADERS) {
            throw new \RuntimeException('En-têtes CSV incorrectes. Attendues: ' . self::HEADER_LINE_DISPLAY);
        }

        $rows = [];
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === [null] || $row === null) {
                continue;
            }
            if (count($row) < count(self::HEADERS)) {
                // ignorer lignes incomplètes
                Logger::warn('Ligne CSV incomplète ignorée.');
                continue;
            }
            $assoc = array_combine(self::HEADERS, array_map(function ($v) {
                return trim((string)$v);
            }, $row));
            if ($assoc === false) {
                continue;
            }
            // Normalisations
            $assoc['code_acte'] = strtoupper($assoc['code_acte']);
            $rows[] = $assoc;
        }

        return $rows;
    }
}
