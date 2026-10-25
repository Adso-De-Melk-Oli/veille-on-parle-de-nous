<?php
/**
 * Génère data/feeds.json : un instantané de toutes les rubriques, utilisé en secours
 * quand un flux est en panne et qu'aucun cache n'existe encore.
 *
 * Usage (ligne de commande) :   php scripts/snapshot.php
 * Lancé chaque jour par l'action GitHub .github/workflows/snapshot.yml,
 * ou par une tâche cron sur n'importe quel serveur qui a accès à Internet.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('CLI uniquement');
}

define('VEILLE_ROOT', dirname(__DIR__));
require VEILLE_ROOT . '/config.php';
require VEILLE_ROOT . '/lib/feed.php';

$previous = array();
if (is_file($VEILLE_CONFIG['snapshot_file'])) {
    $p = json_decode(file_get_contents($VEILLE_CONFIG['snapshot_file']), true);
    if (isset($p['categories'])) {
        $previous = $p['categories'];
    }
}

$out = array('generated' => gmdate('c'), 'categories' => array());
$failures = 0;

foreach ($VEILLE_CATEGORIES as $c) {
    $res = veille_http_get($c['feed'], 20, $VEILLE_CONFIG['user_agent']);
    $items = null;
    $err = '';
    if ($res['ok']) {
        try {
            $items = veille_parse_feed($res['body'], $VEILLE_CONFIG['limit']);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    } else {
        $err = $res['error'];
    }

    if ($items !== null && count($items) > 0) {
        $out['categories'][$c['key']] = array('fetched' => gmdate('c'), 'items' => $items);
        fwrite(STDOUT, sprintf("✔ %-14s %2d articles\n", $c['key'], count($items)));
    } elseif (isset($previous[$c['key']])) {
        // On garde l'ancienne version plutôt qu'une rubrique vide.
        $out['categories'][$c['key']] = $previous[$c['key']];
        $failures++;
        fwrite(STDERR, sprintf("✘ %-14s conservé (%s)\n", $c['key'], $err ? $err : 'vide'));
    } else {
        $failures++;
        fwrite(STDERR, sprintf("✘ %-14s indisponible (%s)\n", $c['key'], $err ? $err : 'vide'));
    }
}

$dir = dirname($VEILLE_CONFIG['snapshot_file']);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
file_put_contents($VEILLE_CONFIG['snapshot_file'], json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDOUT, "Instantané écrit : " . $VEILLE_CONFIG['snapshot_file'] . "\n");

// Échec global seulement si tout est tombé.
exit($failures === count($VEILLE_CATEGORIES) ? 1 : 0);
