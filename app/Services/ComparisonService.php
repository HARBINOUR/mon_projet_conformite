<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Services;

/**
 * Applique les contraintes d'appariement clés.
 * - Indexation en mémoire par acte_id.
 * - Teste CCAM puis NGAP et retient le premier type qui matche toutes les contraintes.
 * - Si >1 match pour un même type, considéré "found" (doublon à tracer côté logs en amont).
 */
class ComparisonService
{
    /** @var \App\Repositories\ActeRepository|null */
    private $acteRepository = null;
    /** @var int */
    private $dateToleranceMinutes = 0;

    /**
     * @param int $dateToleranceMinutes
     */
    public function __construct($dateToleranceMinutes = 0)
    {
        $this->dateToleranceMinutes = max(0, (int) $dateToleranceMinutes);
    }

    /**
     * @param array<int, array<string,string>> $csvRows   Lignes CSV normalisées (trim, code_acte upper)
     * @param array<int, array<string,mixed>>  $oracleRows Résultats Oracle fusionnés NGAP/CCAM
     * @return array{
     *   total_csv:int,
     *   total_found:int,
     *   total_missing:int,
     *   missing_acts_list:array<int,array{id:string, type:string, reason:string}>,
     *   by_type:array{NGAP:array{found:int,missing:int},CCAM:array{found:int,missing:int}}
     * }
     */
    public function compare(array $csvRows, array $oracleRows): array
    {
        // Index Oracle par acte_id
        $byId = [];
        foreach ($oracleRows as $r) {
            $byId[(string)$r['acte_id']][] = $r;
        }

        $totalCsv = count($csvRows);
        $found = 0;
        $missing = 0;
        $missingActs = [];

        $byType = [
            'NGAP' => ['found' => 0, 'missing' => 0],
            'CCAM' => ['found' => 0, 'missing' => 0],
        ];

        foreach ($csvRows as $row) {
            $acteId = (string)$row['acte_id'];
            $candidates = $byId[$acteId] ?? [];

            // Essayer CCAM puis NGAP
            $matchResult = $this->findBestMatch($row, $candidates);

            if ($matchResult['matched']) {
                $found++;
                $byType[$matchResult['type']]['found']++;
            } else {
                $missing++;

                // Le type de l'acte manquant est lu depuis la colonne 'type_acte' du CSV.
                // Fallback sur 'CCAM' si la colonne est absente ou la valeur invalide.
                $missingType = strtoupper($row['type_acte'] ?? 'CCAM');
                if ($missingType !== 'NGAP' && $missingType !== 'CCAM') {
                    $missingType = 'CCAM';
                }
                if (isset($byType[$missingType])) { // Toujours vrai avec le fallback
                    $byType[$missingType]['missing']++;
                }

                // Nouvelle vérification : le dossier est-il en C9 ?
                if ($this->isC9($row['num_intervention'], $row['num_venue'])) {
                    $matchResult['reason'] = 'dossier_en_C9';
                }

                $missingActs[] = ['id' => $acteId, 'type' => $missingType, 'reason' => $matchResult['reason']];
            }
        }

        return [
            'total_csv' => $totalCsv,
            'total_found' => $found,
            'total_missing' => $missing,
            'missing_acts_list' => $missingActs,
            'by_type' => $byType,
        ];
    }

    /**
     * Cherche le meilleur match possible et retourne la raison de l'échec sinon.
     * @param array<string,string> $csv
     * @param array<int,array<string,mixed>> $candidates
     * @return array{matched:bool, type:string|null, reason:string}
     */
    private function findBestMatch(array $csv, array $candidates): array
    {
        // Si aucun candidat pour cet acte_id dans la BDD
        if (empty($candidates)) {
            return ['matched' => false, 'type' => null, 'reason' => 'acte_introuvable_en_bdd'];
        }

        // Type attendu depuis le fichier CSV
        $expectedType = $this->normalizeString($csv['type_acte'] ?? 'ccam');
        if ($expectedType !== 'ngap' && $expectedType !== 'ccam') {
            $expectedType = 'ccam'; // Fallback
        }

        // Filtrer les candidats de la BDD pour ne garder que ceux du bon type
        $typedCandidates = array_values(array_filter($candidates, function ($c) use ($expectedType) {
            return $this->normalizeString($c['typefrom'] ?? null) === $expectedType;
        }));

        // Si aucun candidat du bon type n'a été trouvé
        if (empty($typedCandidates)) {
            return ['matched' => false, 'type' => null, 'reason' => 'type_acte_mismatch'];
        }

        // Tester chaque candidat du bon type jusqu'à trouver un match
        foreach ($typedCandidates as $dbRow) {
            $reason = $this->matchConstraints($csv, $dbRow);
            if ($reason === null) return ['matched' => true, 'type' => $expectedType, 'reason' => ''];
        }

        // Si aucun match, retourner la raison du premier échec
        return ['matched' => false, 'type' => null, 'reason' => $this->matchConstraints($csv, $typedCandidates[0]) ?? 'raison_inconnue'];
    }

    /**
     * Vérifie l'ensemble des contraintes d'appariement.
     * @param array<string,string> $csv
     * @param array<string,mixed>  $db
     */
    private function matchConstraints(array $csv, array $db): ?string
    {
        // 1. acte_id
        if ($this->normalizeString($csv['acte_id']) !== $this->normalizeString($db['acte_id'])) return 'acte_id_mismatch';

        // 2. code_acte
        if ($this->normalizeString($csv['code_acte']) !== $this->normalizeString($db['code_acte'])) return 'code_acte_mismatch';

        // 3. activite_ou_coeff
        $csvCoeff = trim((string)$csv['activite_ou_coeff']);
        $dbCoeff = trim((string)$db['activite_ou_coeff']);

        // Normalise le séparateur décimal (virgule -> point) pour la valeur CSV
        $csvCoeffNormalized = str_replace(',', '.', $csvCoeff);

        if (is_numeric($csvCoeffNormalized) && is_numeric($dbCoeff)) {
            // Comparaison numérique pour les coefficients/décimaux (NGAP)
            if ((float)$csvCoeffNormalized !== (float)$dbCoeff) return 'activite_ou_coeff_mismatch';
        } else {
            // Comparaison textuelle pour les activités (CCAM)
            if (strtolower($csvCoeff) !== strtolower($dbCoeff)) return 'activite_ou_coeff_mismatch';
        }

        // 5. num_intervention
        if ($this->normalizeString($csv['num_intervention']) !== $this->normalizeString($db['num_intervention'])) return 'num_intervention_mismatch';

        // 6. num_venue = venum ou novenu
        $csvVenue = $this->normalizeString($csv['num_venue']);
        if ($csvVenue !== $this->normalizeString($db['novenu'])) return 'num_venue_mismatch';

        // 7. date_acte avec tolérance minutes
        $csvDt = \DateTimeImmutable::createFromFormat('d/m/Y H:i', trim((string)$csv['date_acte'])) ?: null;
        $dbDt  = \DateTimeImmutable::createFromFormat('d/m/Y H:i', trim((string)$db['date_acte'])) ?: null;
        if (!$csvDt || !$dbDt) return 'invalid_date_format';
        $diffMin = abs($csvDt->getTimestamp() - $dbDt->getTimestamp()) / 60;
        if ($diffMin > $this->dateToleranceMinutes) return 'date_mismatch';

        return null; // Toutes les contraintes sont respectées
    }

    /**
     * Normalise une chaîne pour la comparaison : supprime les espaces et met en minuscules.
     * @param mixed $value
     */
    private function normalizeString($value): string
    {
        return strtolower(trim((string)$value));
    }

    /**
     * Vérifie si un acte est en 'C9' dans W_SERVEURACTE.
     *
     * @param string|null $numIntervention
     * @param string|null $numVenue
     * @return bool
     */
    private function isC9(?string $numIntervention, ?string $numVenue): bool
    {
        if (empty($numIntervention) || empty($numVenue)) {
            return false;
        }
        if ($this->acteRepository === null) {
            $this->acteRepository = new \App\Repositories\ActeRepository(\App\Infra\OracleConnection::getPdo());
        }
        return $this->acteRepository->checkC9Exists($numIntervention, $numVenue);
    }
}
