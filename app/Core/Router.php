<?php
declare(strict_types=1);

/*
 * Copyright (c) 2024-2025 Harbi Noureddine
 * Cette classe est publiée sous licence MIT. Consultez le fichier LICENSE pour vos droits d'utilisation.
 */

namespace App\Core;

/**
 * Router minimal HTTP.
 */
class Router
{
    /** @var array<string, callable> */
    private $get = [];
    /** @var array<string, callable> */
    private $post = [];

    public function get(string $path, callable $handler)
    {
        $this->get[$path] = $handler;
    }

    public function post(string $path, callable $handler)
    {
        $this->post[$path] = $handler;
    }

    public function dispatch(string $method, string $path)
    {
        $map = $method === 'POST' ? $this->post : $this->get;
        if (isset($map[$path])) {
            $map[$path]();
            return;
        }
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Route inconnue']], JSON_UNESCAPED_UNICODE);
    }
}
