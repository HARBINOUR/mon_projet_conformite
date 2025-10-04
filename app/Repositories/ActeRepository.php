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
     * @param array<int,string> $ids
     * @return array<int,array<string,mixed>>
     */
    public function fetchByActeIds(array $ids): array
    {
        if (!$ids) return [];

        // Placeholders nommés :id0, :id1, ...
        $ph = [];
        $bind = [];
        foreach ($ids as $i => $id) {
            $name = ":id{$i}";
            $ph[] = $name;
            $bind[$name] = (string)$id;
        }
        $in = implode(',', $ph);

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
                    JOIN oc_intervention i ON i.internum = a.internum
                    JOIN o_venue v         ON v.vennum   = i.vennum
                    WHERE a.noacte IN ($in)
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
                    JOIN oc_intervention i
                        ON i.internum = a.internum
                    JOIN o_venue v
                        ON v.vennum = i.vennum
                    WHERE a.noacte IN  ($in)
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
}
