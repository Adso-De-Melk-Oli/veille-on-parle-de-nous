<?php
/**
 * Lecture, normalisation et mise en cache des flux RSS / Atom.
 * Compatible PHP 5.6 → 8.x. Ne dépend que de SimpleXML (et de DOM si présent).
 */

require_once __DIR__ . '/http.php';

/* ------------------------------------------------------------------ *
 *  Parsing
 * ------------------------------------------------------------------ */

/**
 * Transforme un XML RSS 2.0 / Atom en tableau d'articles normalisés.
 * Lève une Exception si le XML est illisible.
 */
function veille_parse_feed($xml, $limit)
{
    $xml = trim((string) $xml);
    if ($xml === '') {
        throw new Exception('flux vide');
    }
    // Supprime BOM et caractères de contrôle interdits en XML 1.0.
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $xml = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $xml);
    if ($xml === null) {
        throw new Exception('encodage invalide');
    }
    if (stripos(substr($xml, 0, 500), '<html') !== false) {
        throw new Exception('le flux a renvoyé une page HTML');
    }

    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        throw new Exception('XML illisible');
    }

    $nodes = array();
    if (isset($doc->channel->item)) {
        foreach ($doc->channel->item as $it) { $nodes[] = $it; }          // RSS 2.0
    } elseif (isset($doc->item)) {
        foreach ($doc->item as $it) { $nodes[] = $it; }                   // RSS 1.0
    } elseif (isset($doc->entry)) {
        foreach ($doc->entry as $it) { $nodes[] = $it; }                  // Atom
    }

    $items = array();
    $seen = array();
    foreach ($nodes as $n) {
        $item = veille_normalize_item($n);
        if (!$item) {
            continue;
        }
        $k = veille_dedupe_key($item);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $items[] = $item;
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function veille_normalize_item(SimpleXMLElement $n)
{
    $ns = $n->getNamespaces(true);

    $title = veille_text((string) $n->title);

    // Lien : RSS <link>text</link> ou Atom <link href="" rel="alternate">
    $link = trim((string) $n->link);
    if ($link === '' && isset($n->link)) {
        foreach ($n->link as $l) {
            $rel = (string) $l['rel'];
            if ($rel === '' || $rel === 'alternate') {
                $link = (string) $l['href'];
                break;
            }
        }
    }
    if ($link === '' && isset($n->guid) && preg_match('#^https?://#', (string) $n->guid)) {
        $link = (string) $n->guid;
    }
    if ($link === '' || !preg_match('#^https?://#i', $link)) {
        return null;
    }
    if ($title === '') {
        $title = $link;
    }

    // Date
    $dateRaw = '';
    foreach (array('pubDate', 'published', 'updated') as $f) {
        if (isset($n->$f) && (string) $n->$f !== '') {
            $dateRaw = (string) $n->$f;
            break;
        }
    }
    if ($dateRaw === '' && isset($ns['dc'])) {
        $dc = $n->children($ns['dc']);
        $dateRaw = (string) $dc->date;
    }
    $ts = $dateRaw !== '' ? strtotime($dateRaw) : false;

    // Contenu HTML (description, content:encoded, Atom content/summary)
    $html = (string) $n->description;
    if (isset($ns['content'])) {
        $c = $n->children($ns['content']);
        if ((string) $c->encoded !== '') {
            $html = (string) $c->encoded;
        }
    }
    if ($html === '' && isset($n->content)) {
        $html = (string) $n->content;
    }
    if ($html === '' && isset($n->summary)) {
        $html = (string) $n->summary;
    }

    // Image
    $image = veille_find_image($n, $ns, $html);

    // Source
    $sourceLabel = isset($n->source) ? veille_text((string) $n->source) : '';
    $source = veille_source_name($title, $link, $sourceLabel);

    // Titre : Google Actualités ajoute « - Média » à la fin.
    if ($source['from_title']) {
        $title = $source['clean_title'];
    }

    // Extrait texte
    $excerpt = veille_excerpt($html, 260);
    // Google Actualités : la description répète le titre.
    if ($excerpt !== '' && (stripos($excerpt, $title) === 0 || stripos($title, rtrim($excerpt, '. …')) === 0)) {
        $excerpt = '';
    }

    return array(
        'title'   => $title,
        'link'    => $link,
        'date'    => $ts ? gmdate('c', $ts) : null,
        'ts'      => $ts ? (int) $ts : 0,
        'excerpt' => $excerpt,
        'image'   => $image,
        'source'  => $source['name'],
        'domain'  => $source['domain'],
    );
}

/** Clé de dédoublonnage : lien sans paramètres de suivi, sinon titre. */
function veille_dedupe_key($item)
{
    $l = preg_replace('#[?&](utm_[^=&]+|oc|xtor|at_medium|at_campaign)=[^&]*#i', '', $item['link']);
    $l = preg_replace('#^https?://(www\.)?#i', '', rtrim($l, '/?&'));
    if (strpos($l, 'news.google.com') === 0) {
        return 't:' . veille_lower($item['title']);
    }
    return 'l:' . strtolower($l);
}

function veille_lower($s)
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function veille_text($s)
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // double encodage fréquent (Inoreader)
    $s = strip_tags($s);
    $s = preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $s);
    return trim($s);
}

function veille_excerpt($html, $max)
{
    // Retire les blocs qui polluent l'extrait (menus, boutons de partage, scripts).
    $html = preg_replace('#<(script|style|noscript|figure|figcaption|nav|button|form)[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', ' ', $html);
    $t = veille_text($html);
    // Bruits récurrents dans les flux de la veille.
    $t = preg_replace('/^(\.\.\.|…)\s*/u', '', $t);
    // Textes de paywall / connexion : aucun intérêt comme résumé.
    if (preg_match('/(Connectez-vous|abonnement découverte|Déjà abonné|Abonné\.?e \?|Subscribe to continue|Réservé aux abonnés)/iu', $t)) {
        return '';
    }
    $t = preg_replace('/\b(Modifié le|Publié le|Mis à jour le|Publié)\s+(le\s+)?(\d{1,2}[\/\.]\d{1,2}[\/\.]\d{2,4}|\d{1,2}\s+\p{L}+\s+\d{4})( à [\dh:]+)?\s*\|?\s*/iu', '', $t);
    $t = preg_replace('/^(Home|Accueil)\s*\|.*?\|\s*(Dépêche n°\s*\d+)?\s*/iu', '', $t);
    $t = preg_replace('/^.{0,80}\|\s*(Dépêche n°\s*\d+)\s*/iu', '', $t);
    $t = preg_replace('/\b\d+\s+min de lecture\b/iu', '', $t);
    $t = preg_replace('/Mon panier\s*\d*/iu', '', $t);
    $t = trim(preg_replace('/\s+/u', ' ', $t));

    $len = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
    if ($len <= $max) {
        return $t;
    }
    $cut = function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
    $sp = strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) {
        $cut = substr($cut, 0, $sp);
    }
    return rtrim($cut, " ,;:.-–") . '…';
}

/**
 * Cherche une vraie photo : media:content / media:thumbnail / enclosure / première <img> du contenu.
 * Écarte pictos, logos, boutons de partage, pixels de suivi et compteurs.
 */
function veille_find_image(SimpleXMLElement $n, $ns, $html)
{
    $cands = array();

    if (isset($ns['media'])) {
        $m = $n->children($ns['media']);
        foreach (array('content', 'thumbnail') as $tag) {
            if (isset($m->$tag)) {
                foreach ($m->$tag as $mc) {
                    $a = $mc->attributes();
                    $type = isset($a['type']) ? (string) $a['type'] : '';
                    $medium = isset($a['medium']) ? (string) $a['medium'] : '';
                    if ($type === '' || strpos($type, 'image/') === 0 || $medium === 'image') {
                        $cands[] = (string) $a['url'];
                    }
                }
            }
        }
        if (isset($m->group)) {
            foreach ($m->group->children($ns['media']) as $mc) {
                $a = $mc->attributes();
                if (isset($a['url'])) { $cands[] = (string) $a['url']; }
            }
        }
    }
    if (isset($n->enclosure)) {
        foreach ($n->enclosure as $e) {
            $type = (string) $e['type'];
            if ($type === '' || strpos($type, 'image/') === 0) {
                $cands[] = (string) $e['url'];
            }
        }
    }
    if ($html !== '' && preg_match_all('#<img\b[^>]*>#i', $html, $imgs)) {
        foreach ($imgs[0] as $tag) {
            $w = preg_match('#\bwidth=["\']?(\d+)#i', $tag, $mw) ? (int) $mw[1] : 0;
            $h = preg_match('#\bheight=["\']?(\d+)#i', $tag, $mh) ? (int) $mh[1] : 0;
            if (($w && $w < 120) || ($h && $h < 80)) {
                continue;
            }
            if (preg_match('#\b(?:data-src|src)=["\']([^"\']+)["\']#i', $tag, $ms)) {
                $cands[] = html_entity_decode($ms[1], ENT_QUOTES, 'UTF-8');
            }
        }
    }

    foreach ($cands as $u) {
        $u = trim($u);
        if ($u === '' || strpos($u, 'data:') === 0) {
            continue;
        }
        if (strpos($u, '//') === 0) {
            $u = 'https:' . $u;
        }
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        if (preg_match('#\.(svg|gif)(\?|$)|/(icons?|logos?|emoji|avatars?|badges?|pixel|spacer|blank|share|social|sprites?)[/._-]|(facebook|twitter|x-twitter|linkedin|whatsapp|email|pinterest|feedburner|gravatar|doubleclick|counter|cpt_global|supportduweb|guillemet)#i', $u)) {
            continue;
        }
        // Le site est en HTTPS : une image HTTP serait bloquée par le navigateur.
        $u = preg_replace('#^http://#i', 'https://', $u);
        return $u;
    }
    return '';
}

/** Domaine sans www. */
function veille_domain($url)
{
    $h = parse_url($url, PHP_URL_HOST);
    return $h ? preg_replace('#^(www\d?|m|amp)\.#i', '', strtolower($h)) : '';
}

/**
 * Nom du média lisible. Les libellés « Alerte Google », « Talkwalker », « [Global] supagro »… sont
 * des noms de flux de veille, pas des médias : on prend alors le domaine de l'article.
 */
function veille_source_name($title, $link, $label)
{
    $domain = veille_domain($link);
    $out = array('name' => '', 'domain' => $domain, 'from_title' => false, 'clean_title' => $title);

    // Google Actualités : « Titre de l'article - Nom du média »
    if ($domain === 'news.google.com' && preg_match('/^(.*\S)\s+[-–—]\s+([^-–—]{2,60})$/u', $title, $m)) {
        $out['name'] = trim($m[2]);
        $out['from_title'] = true;
        $out['clean_title'] = trim($m[1]);
        $out['domain'] = '';
        return $out;
    }

    $known = veille_known_media();
    if (isset($known[$domain])) {
        $out['name'] = $known[$domain];
        return $out;
    }

    $isWatchLabel = $label === ''
        || preg_match('/alerte google|google alert|talkwalker|inoreader|\[global\]|keyword|monitoring|institut agro\d|supagro|veille|saved web pages|^0 results/i', $label);
    if (!$isWatchLabel && (function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label)) <= 40 && strpos($label, '.') === false) {
        $out['name'] = $label;
        return $out;
    }
    $out['name'] = $domain;
    return $out;
}

function veille_known_media()
{
    return array(
        'midilibre.fr' => 'Midi Libre', 'lemonde.fr' => 'Le Monde', 'lefigaro.fr' => 'Le Figaro',
        'liberation.fr' => 'Libération', 'leparisien.fr' => 'Le Parisien', 'lesechos.fr' => 'Les Échos',
        'weekend.lesechos.fr' => 'Les Échos Week-end', 'francebleu.fr' => 'France Bleu', 'ici.fr' => 'ici',
        'france3-regions.francetvinfo.fr' => 'France 3 Régions', 'francetvinfo.fr' => 'Franceinfo',
        'actu.fr' => 'actu.fr', 'lagazettedemontpellier.fr' => 'La Gazette de Montpellier',
        'e-metropolitain.fr' => 'Métropolitain', 'metropolitain.fr' => 'Métropolitain', 'lalettrem.fr' => 'La Lettre M',
        'letudiant.fr' => "L'Étudiant", 'studyrama.com' => 'Studyrama', 'grandes-ecoles.studyrama.com' => 'Studyrama',
        'aefinfo.fr' => 'AEF info', 'agro.newstank.fr' => 'News Tank Agro', 'newstank.fr' => 'News Tank',
        'vitisphere.com' => 'Vitisphere', 'larvf.com' => 'La Revue du vin de France', 'reussir.fr' => 'Réussir',
        'lafranceagricole.fr' => 'La France Agricole', 'terre-net.fr' => 'Terre-net', 'mon-viti.com' => 'Mon-Viti',
        'agriculture.gouv.fr' => "Ministère de l'Agriculture", 'cirad.fr' => 'Cirad', 'inrae.fr' => 'INRAE',
        'mondedesgrandesecoles.fr' => 'Monde des grandes écoles', 'lejdc.fr' => 'Le Journal du Centre',
        'lejournaldesentreprises.com' => 'Le Journal des entreprises', 'entreprendre-montpellier.com' => 'Entreprendre Montpellier',
        'lindependant.fr' => "L'Indépendant", 'terredevins.com' => 'Terre de Vins', 'mybettanedesseauve.fr' => 'En Magnum',
        'nytimes.com' => 'The New York Times', 'thedrinksbusiness.com' => 'The Drinks Business', 'harpers.co.uk' => 'Harpers',
        'lastampa.it' => 'La Stampa', 'theconversation.com' => 'The Conversation', 'ouest-france.fr' => 'Ouest-France',
        'sudouest.fr' => 'Sud Ouest', 'ladepeche.fr' => 'La Dépêche', 'toulemploi.fr' => 'Toulemploi',
        'irstea.fr' => 'Irstea', 'imazpress.com' => 'Imaz Press', 'worldcrunch.com' => 'Worldcrunch',
        'aps.sn' => 'APS Sénégal', 'qds.it' => 'QdS', 'linkedin.com' => 'LinkedIn', 'youtube.com' => 'YouTube',
    );
}

/* ------------------------------------------------------------------ *
 *  Cache
 * ------------------------------------------------------------------ */

function veille_cache_path($cfg, $key)
{
    return rtrim($cfg['cache_dir'], '/') . '/' . preg_replace('/[^a-z0-9_-]/i', '', $key) . '.json';
}

function veille_cache_read($cfg, $key)
{
    $f = veille_cache_path($cfg, $key);
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) && isset($d['items']) ? $d : null;
}

function veille_cache_write($cfg, $key, $data)
{
    $dir = rtrim($cfg['cache_dir'], '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        return false;
    }
    $f = veille_cache_path($cfg, $key);
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    return @rename($tmp, $f);
}

function veille_snapshot_read($cfg, $key)
{
    static $snap = null;
    if ($snap === null) {
        $snap = array();
        if (is_file($cfg['snapshot_file'])) {
            $d = json_decode((string) @file_get_contents($cfg['snapshot_file']), true);
            if (is_array($d) && isset($d['categories'])) {
                $snap = $d;
            }
        }
    }
    if (isset($snap['categories'][$key]['items'])) {
        return array(
            'items'   => $snap['categories'][$key]['items'],
            'fetched' => isset($snap['generated']) ? $snap['generated'] : null,
        );
    }
    return null;
}

/**
 * Retourne les articles d'une rubrique en gérant panne et cache.
 *
 * array(
 *   'items'   => [...],
 *   'status'  => 'fresh' | 'cache' | 'stale' | 'snapshot' | 'error',
 *   'fetched' => ISO 8601 de la dernière récupération réussie, ou null,
 *   'error'   => message technique (vide si tout va bien),
 * )
 */
function veille_get_category($cfg, $cat, $forceRefresh = false)
{
    $key = $cat['key'];
    $cache = veille_cache_read($cfg, $key);
    $now = time();

    // « Réessayer » ne peut pas forcer plus d'un appel au flux par minute.
    if ($forceRefresh && $cache && ($now - $cache['fetched_ts']) < 60) {
        $forceRefresh = false;
    }
    if (!$forceRefresh && $cache && isset($cache['fetched_ts']) && ($now - $cache['fetched_ts']) < $cfg['cache_ttl']) {
        return array('items' => $cache['items'], 'status' => 'cache', 'fetched' => gmdate('c', $cache['fetched_ts']), 'error' => '');
    }
    if (!$forceRefresh && $cache && isset($cache['retry_after']) && $cache['retry_after'] > $now) {
        $err = isset($cache['last_error']) ? $cache['last_error'] : '';
        return array('items' => $cache['items'], 'status' => 'stale', 'fetched' => gmdate('c', $cache['fetched_ts']), 'error' => $err);
    }

    // Verrou : un seul processus rafraîchit un flux à la fois ; les autres servent le cache existant.
    $lockFile = veille_cache_path($cfg, $key) . '.lock';
    $lock = @fopen($lockFile, 'c');
    $gotLock = $lock ? @flock($lock, LOCK_EX | LOCK_NB) : true;
    if (!$gotLock && $cache) {
        @fclose($lock);
        return array('items' => $cache['items'], 'status' => 'cache', 'fetched' => gmdate('c', $cache['fetched_ts']), 'error' => '');
    }

    $error = '';
    $items = null;
    $host = parse_url($cat['feed'], PHP_URL_HOST);
    $down = veille_host_down($cfg, $host);
    if ($down !== '' && !$forceRefresh) {
        // Le serveur du flux vient d'échouer pour une autre rubrique : inutile d'attendre à nouveau.
        $res = array('ok' => false, 'status' => 0, 'error' => $down);
    } else {
        $proxy = isset($cfg['proxy']) ? (string) $cfg['proxy'] : '';
        $res = veille_http_get($cat['feed'], $cfg['timeout'], $cfg['user_agent'], 3, $proxy);
        if (!$res['ok'] && $res['status'] === 0) {
            veille_host_mark_down($cfg, $host, $res['error']);
        }
    }
    if ($res['ok']) {
        try {
            $items = veille_parse_feed($res['body'], $cfg['limit']);
        } catch (Exception $e) {
            $error = 'lecture du flux : ' . $e->getMessage();
        }
    } else {
        $error = 'accès au flux : ' . $res['error'];
    }

    if ($items !== null) {
        if (count($items) === 0 && $cache && count($cache['items']) > 0) {
            // Un flux soudain vide est suspect : on garde la dernière version connue.
            $error = 'flux vide (dernière version conservée)';
        } else {
            veille_cache_write($cfg, $key, array('fetched_ts' => $now, 'items' => $items));
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
            return array('items' => $items, 'status' => 'fresh', 'fetched' => gmdate('c', $now), 'error' => '');
        }
    }
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }

    // Panne : dernière version en cache, si elle n'est pas trop ancienne.
    if ($cache && ($now - $cache['fetched_ts']) < $cfg['stale_max']) {
        // Pendant une panne, on ne réessaie pas à chaque visite : 5 minutes de répit.
        $cache['retry_after'] = $now + 300;
        $cache['last_error'] = $error;
        veille_cache_write($cfg, $key, $cache);
        return array('items' => $cache['items'], 'status' => 'stale', 'fetched' => gmdate('c', $cache['fetched_ts']), 'error' => $error);
    }

    // Pas de cache : instantané livré avec le site.
    $snap = veille_snapshot_read($cfg, $key);
    if ($snap && count($snap['items'])) {
        return array('items' => $snap['items'], 'status' => 'snapshot', 'fetched' => $snap['fetched'], 'error' => $error);
    }

    return array('items' => array(), 'status' => 'error', 'fetched' => null, 'error' => $error);
}

/**
 * Disjoncteur par hôte : après un échec réseau (délai, DNS, refus), plus aucun appel vers ce serveur
 * pendant 5 minutes. Évite qu'une page attende 12 × le délai quand Inoreader est injoignable.
 */
function veille_host_down($cfg, $host)
{
    $f = rtrim($cfg['cache_dir'], '/') . '/_hote-' . preg_replace('/[^a-z0-9.-]/i', '', (string) $host) . '.json';
    if (!is_file($f)) {
        return '';
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return (is_array($d) && isset($d['until']) && $d['until'] > time()) ? (string) $d['error'] : '';
}

function veille_host_mark_down($cfg, $host, $error)
{
    $dir = rtrim($cfg['cache_dir'], '/');
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/_hote-' . preg_replace('/[^a-z0-9.-]/i', '', (string) $host) . '.json',
            json_encode(array('until' => time() + 300, 'error' => $error)));
    }
}

/** Lecture sans réseau (cache puis instantané) : sert au premier affichage instantané de la page. */
function veille_peek_category($cfg, $cat)
{
    $cache = veille_cache_read($cfg, $cat['key']);
    if ($cache && (time() - $cache['fetched_ts']) < $cfg['stale_max']) {
        return array('items' => $cache['items'], 'fetched' => gmdate('c', $cache['fetched_ts']));
    }
    $snap = veille_snapshot_read($cfg, $cat['key']);
    return $snap ? $snap : array('items' => array(), 'fetched' => null);
}

function veille_find_category($cats, $keyOrId)
{
    foreach ($cats as $c) {
        if ((string) $c['key'] === (string) $keyOrId || (is_numeric($keyOrId) && (int) $c['id'] === (int) $keyOrId)) {
            return $c;
        }
    }
    return null;
}
