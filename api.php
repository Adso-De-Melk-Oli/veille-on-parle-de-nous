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

if (isset($_GET['status'])) {
    $dir = $VEILLE_CONFIG['cache_dir'];
    veille_json(array(
        'ok'              => true,
        'php'             => PHP_VERSION,
        'allow_url_fopen' => veille_ini_on('allow_url_fopen'),
        'openssl'         => extension_loaded('openssl'),
        'curl'            => function_exists('curl_init'),
        'simplexml'       => function_exists('simplexml_load_string'),
        'cache_writable'  => (is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir),
        'snapshot'        => is_file($VEILLE_CONFIG['snapshot_file']),
    ));
}

$want = isset($_GET['cat']) ? (string) $_GET['cat'] : '';
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if ($want === 'all') {
    $out = array('ok' => true, 'categories' => array());
    foreach ($VEILLE_CATEGORIES as $c) {
        $r = veille_get_category($VEILLE_CONFIG, $c, false);
        $out['categories'][$c['key']] = array(
            'status'  => $r['status'],
            'fetched' => $r['fetched'],
            'count'   => count($r['items']),
            'items'   => $r['items'],
        );
    }
    veille_json($out);
}

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
