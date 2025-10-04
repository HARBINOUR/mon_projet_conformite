<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Repositories;

use PDO;

/**
 * Requêtes paramétrées Oracle pour NGAP et CCAM.
 */
class ActeRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<int,array{id:string, origineinter:string}> $actes
     * @return array<int,array<string,mixed>>
     */
    public function fetchByActeIds(array $actes): array
    {
        if (!$actes) return [];

        // Construction de la clause WHERE pour (noacte, origineinter)
        $conditions = [];
        $bind = [];
        $i = 0;
        foreach ($actes as $acte) {
            $idParam = ":id{$i}";
            $origineParam = ":origine{$i}";
            
            $conditions[] = "(a.noacte = {$idParam} AND a.origineinter = {$origineParam})";
            $bind[$idParam] = (string)$acte['id'];
            $bind[$origineParam] = (string)$acte['origineinter'];
            $i++;
        }
        $whereClause = implode(' OR ', $conditions);

        // NGAP
        $sqlNgap = "SELECT
                        a.noacte        AS acte_id,
                        a.lettrecle     AS code_acte,
                        a.coefficient   AS activite_ou_coeff,
                        a.internum      AS acte_internum,
                        i.nodossier     AS num_intervention,
                        v.vennum        AS venum,
                        v.novenue      AS novenu,
                    DATE_FORMAT(a.dateexec, '%d/%m/%Y %H:%i') AS date_acte,
                    'NGAP'          AS typefrom
                    FROM oc_actengap a
                    LEFT JOIN oc_intervention i ON i.internum = a.internum
                    LEFT JOIN o_venue v         ON v.vennum   = i.vennum
                    WHERE {$whereClause}
        ";

        // CCAM
        $sqlCcam = "SELECT 
                        a.noacte AS acte_id,
                        a.codeacte AS code_acte,
                        a.activite AS activite_ou_coeff,
                        a.internum AS acte_internum,
                        i.nodossier AS num_intervention,
                        v.vennum AS venum,
                        v.novenue      AS novenu,
                        DATE_FORMAT(a.dateexec, '%d/%m/%Y %H:%i') AS date_acte,
                        'CCAM' AS typefrom
                    FROM oc_acteccam a
                    LEFT JOIN oc_intervention i ON i.internum = a.internum
                    LEFT JOIN o_venue v         ON v.vennum   = i.vennum
                    WHERE {$whereClause}
        ";

        $rows = [];

        $stmtN = $this->pdo->prepare($sqlNgap);
        foreach ($bind as $k => $v) $stmtN->bindValue($k, $v, PDO::PARAM_STR);
        $stmtN->execute();
        $rows = array_merge($rows, $stmtN->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $stmtC = $this->pdo->prepare($sqlCcam);
        foreach ($bind as $k => $v) $stmtC->bindValue($k, $v, PDO::PARAM_STR);
        $stmtC->execute();
        $rows = array_merge($rows, $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return array_map(static function (array $row): array {
            return array_change_key_case($row, CASE_LOWER);
        }, $rows);
    }

    /**
     * Vérifie si un acte avec le code 'C9' existe pour une intervention et une venue données.
     *
     * @param string $numIntervention Le numéro de dossier (nodossier).
     * @param string $numVenue Le numéro de venue (novenue).
     * @return bool Retourne true si un acte 'C9' est trouvé, false sinon.
     */
    public function checkC9Exists(string $numIntervention, string $numVenue): bool
    {
        $sql = "SELECT 1 
                FROM W_SERVEURACTE 
                WHERE cocode = 'C9' 
                  AND nodossier = :nodossier 
                  AND novenue = :novenue";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nodossier', $numIntervention, PDO::PARAM_STR);
        $stmt->bindValue(':novenue', $numVenue, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }
}
