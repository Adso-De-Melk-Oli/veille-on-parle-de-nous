<?php
/**
 * Veille « On parle de nous » — configuration
 * L'Institut Agro Montpellier · Service communication
 *
 * Pour ajouter / retirer une rubrique : modifier le tableau $VEILLE_CATEGORIES.
 * L'ordre du tableau = l'ordre des filtres à gauche.
 * L'« id » numérique conserve les anciens permaliens (index.php?id=0 … ?id=11).
 */

if (!defined('VEILLE_ROOT')) {
    define('VEILLE_ROOT', __DIR__);
}

$VEILLE_CONFIG = array(
    // Durée de vie du cache d'un flux (secondes). 1800 = 30 min.
    'cache_ttl'      => 1800,
    // Au-delà de cet âge, une donnée en cache n'est plus affichée même si le flux est en panne (secondes). 30 jours.
    'stale_max'      => 2592000,
    // Délai maximal pour joindre un flux (secondes).
    'timeout'        => 8,
    // Nombre d'articles par rubrique.
    'limit'          => 20,
    'cache_dir'      => VEILLE_ROOT . '/cache',
    // Instantané de secours (généré par scripts/snapshot.php ou par l'action GitHub).
    'snapshot_file'  => VEILLE_ROOT . '/data/feeds.json',
    'user_agent'     => 'VeilleInstitutAgroMontpellier/3.0 (+https://www.supagro.fr/web/services/on-parle-de-nous/)',
    'site_url'       => 'https://www.supagro.fr/web/services/on-parle-de-nous/',
    'matomo_site_id' => 18,
);

$VEILLE_CATEGORIES = array(
    array('id' => 0,  'key' => 'institut',      'group' => 'Établissement',     'label' => "L'Institut Agro",                'color' => 'orange', 'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/Institut%20Agro'),
    array('id' => 1,  'key' => 'general',       'group' => 'Établissement',     'label' => 'Général',                        'color' => 'orange', 'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/general'),
    array('id' => 2,  'key' => 'vigne',         'group' => 'Pôles',             'label' => 'Vigne et vin',                   'color' => 'purple', 'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/IHEV'),
    array('id' => 3,  'key' => 'sud',           'group' => 'Pôles',             'label' => 'Développement durable au sud',   'color' => 'green',  'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/IRC'),
    array('id' => 4,  'key' => 'enseignement',  'group' => 'Pôles',             'label' => "Appui à l'enseignement agricole", 'color' => 'blue',   'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/FLORAC'),
    array('id' => 5,  'key' => 'alimentation',  'group' => 'Axes stratégiques', 'label' => 'Alimentation durable',           'color' => 'pink',   'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/alimentation-durable'),
    array('id' => 6,  'key' => 'numerique',     'group' => 'Axes stratégiques', 'label' => 'Agriculture numérique',          'color' => 'blue',   'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/Agriculture%20Num%C3%A9rique'),
    array('id' => 7,  'key' => 'agroecologie',  'group' => 'Axes stratégiques', 'label' => 'Agro-écologie',                  'color' => 'green',  'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/Agro-%C3%A9cologie'),
    array('id' => 8,  'key' => 'innovation',    'group' => 'Axes stratégiques', 'label' => 'Innovation & entrepreneuriat',   'color' => 'yellow', 'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/innovation'),
    array('id' => 9,  'key' => 'fondation',     'group' => 'Réseau',            'label' => 'Fondation',                      'color' => 'yellow', 'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/FONDATION'),
    array('id' => 10, 'key' => 'international', 'group' => 'Réseau',            'label' => "Vu de l'international",          'color' => 'purple', 'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/ENGLISH'),
    array('id' => 11, 'key' => 'accueil',       'group' => 'Réseau',            'label' => 'On accueille',                   'color' => 'pink',   'feed' => 'https://www.inoreader.com/stream/user/1005705226/tag/on%20accueille'),
);

// Réglages propres au serveur (non versionnés) : copier config.local.example.php en config.local.php.
if (is_file(VEILLE_ROOT . '/config.local.php')) {
    include VEILLE_ROOT . '/config.local.php';
}
