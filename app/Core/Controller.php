<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Core;

/**
 * Base Controller utilitaire.
 */
class Controller
{
    /**
     * @param mixed $data
     */
    protected function json($data, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function badRequest(string $code, string $message)
    {
        $this->json(['error' => ['code' => $code, 'message' => $message]], 400);
    }

    protected function serverError(string $message = 'Erreur interne')
    {
        $this->json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => $message]], 500);
    }
}
