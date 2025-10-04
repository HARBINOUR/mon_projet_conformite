<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1'); // Mettre '0' en production

$root = dirname(__DIR__);

// Autoloader Composer
require_once $root . '/vendor/autoload.php';

// Charger la configuration
$configFile = $root . '/config/config.php';
if (!file_exists($configFile)) {
    $isApiRequest = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApiRequest) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'CONFIG_MISSING', 'message' => 'Fichier de configuration manquant. Veuillez copier config.sample.php en config.php.']], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Erreur de configuration</title><style>body{font-family:sans-serif;padding:2em;text-align:center;background:#f8f9fa;} .container{max-width:600px;margin:auto;padding:2em;background:white;border:1px solid #dee2e6;border-radius:8px;} code{background:#e9ecef;padding:2px 6px;border-radius:4px;}</style></head>
<body>
  <div class="container">
    <h1>Erreur de configuration</h1>
    <p>Le fichier de configuration <code>config/config.php</code> est manquant.</p>
    <p>Veuillez copier <code>config/config.sample.php</code> vers <code>config/config.php</code> et y renseigner vos informations de connexion à la base de données.</p>
  </div>
</body>
</html>
HTML;
        exit;
    }
}
require_once $configFile;

use App\Core\Router;
use App\Core\UrlHelper;
use App\Controllers\ApiController;
use App\Logger;

$basePath = UrlHelper::getBasePath();
$router = new Router();

// Routes API
$router->post('/api/upload', function () {
    $controller = new ApiController();
    $controller->upload();
});

// Route pour la page des mentions légales
$router->get('/legal.html', function () use ($root, $basePath) {
    // Passer basePath à la vue si nécessaire, ou utiliser des chemins relatifs/absolus
    require $root . '/views/legal.html';
});

// Route racine qui affiche l'application principale
$router->get('/', function () use ($root, $basePath) {
    require $root . '/views/upload/index.php';
});

// Route pour ignorer le favicon et éviter les erreurs 404 dans les logs
$router->get('/favicon.ico', function () {
    http_response_code(204); // No Content
    // Aucune sortie n'est nécessaire
});

// Dispatch
try {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

    if ($basePath !== '' && ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/'))) {
        $requestPath = substr($requestPath, strlen($basePath));
    }

    if ($requestPath === '' || $requestPath === false) {
        $requestPath = '/';
    }

    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath);
} catch (Throwable $e) {
    Logger::error('Fatal router error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Erreur interne']], JSON_UNESCAPED_UNICODE);
}