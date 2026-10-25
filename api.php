<?php
/**
 * API JSON de la veille.
 *   api.php?cat=vigne        → une rubrique
 *   api.php?cat=all          → toutes les rubriques (utilisé pour les compteurs)
 *   api.php?status=1         → diagnostic (méthode HTTP disponible, cache inscriptible…)
 */

define('VEILLE_ROOT', __DIR__);
require __DIR__ . '/config.php';
require __DIR__ . '/lib/feed.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function veille_json($data, $code = 200)
{
    if (function_exists('http_response_code')) {
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Les erreurs PHP ne doivent jamais casser le JSON.
ini_set('display_errors', '0');
set_exception_handler(function ($e) {
    veille_json(array('ok' => false, 'error' => 'erreur interne'), 500);
});

if (isset($_GET['status']) && !empty($VEILLE_CONFIG['diagnostic'])) {
    $dir = $VEILLE_CONFIG['cache_dir'];
    $test = null;
    if (isset($_GET['test'])) {
        // Essai réel de connexion au premier flux, avec le détail de l'erreur.
        $url = $VEILLE_CATEGORIES[0]['feed'];
        $host = parse_url($url, PHP_URL_HOST);
        $ip = gethostbyname($host);
        $t0 = microtime(true);
        $r = veille_http_get($url, $VEILLE_CONFIG['timeout'], $VEILLE_CONFIG['user_agent'], 3, $VEILLE_CONFIG['proxy']);
        $test = array(
            'host'     => $host,
            'dns'      => $ip !== $host ? 'ok' : 'échec (nom non résolu)',
            'proxy'    => $VEILLE_CONFIG['proxy'] !== '' ? 'oui' : 'non',
            'ok'       => $r['ok'],
            'http'     => $r['status'],
            'method'   => $r['method'],
            'ms'       => (int) round((microtime(true) - $t0) * 1000),
            'error'    => $r['error'],
        );
    }
    veille_json(array(
        'ok'              => true,
        'php'             => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'allow_url_fopen' => veille_ini_on('allow_url_fopen'),
        'openssl'         => extension_loaded('openssl'),
        'curl'            => function_exists('curl_init'),
        'simplexml'       => function_exists('simplexml_load_string'),
        'cache_writable'  => (is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir),
        'snapshot'        => is_file($VEILLE_CONFIG['snapshot_file']),
        'test'            => $test,
    ));
}

$want = isset($_GET['cat']) && is_string($_GET['cat']) ? substr($_GET['cat'], 0, 40) : '';
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

$cat = veille_find_category($VEILLE_CATEGORIES, $want);
if (!$cat) {
    veille_json(array('ok' => false, 'error' => 'rubrique inconnue'), 404);
}

$r = veille_get_category($VEILLE_CONFIG, $cat, $force);
veille_json(array(
    'ok'      => $r['status'] !== 'error',
    'cat'     => $cat['key'],
    'status'  => $r['status'],
    'fetched' => $r['fetched'],
    // Le détail technique n'est pas exposé publiquement.
    'error'   => $r['status'] === 'error' ? 'flux indisponible' : ($r['error'] !== '' ? 'flux momentanément indisponible' : ''),
    'items'   => $r['items'],
), $r['status'] === 'error' ? 503 : 200);
