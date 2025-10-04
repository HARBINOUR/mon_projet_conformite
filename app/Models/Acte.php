<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Models;

/**
 * Modèle simple d'acte (optionnel).
 */
class Acte
{
    /** @var string */
    public $acte_id;
    /** @var string */
    public $code_acte;
    /** @var string */
    public $activite_ou_coeff;
    /** @var string */
    public $type;

    public function __construct($acte_id, $code_acte, $activite_ou_coeff, $type)
    {
        $this->acte_id = (string) $acte_id;
        $this->code_acte = (string) $code_acte;
        $this->activite_ou_coeff = (string) $activite_ou_coeff;
        $this->type = (string) $type;
    }
}
