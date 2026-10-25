<?php
/**
 * Veille « On parle de nous » — L'Institut Agro Montpellier
 * Version 3.0 — refonte 2026
 *
 * La page s'affiche immédiatement avec la dernière version connue (cache ou instantané),
 * puis assets/app.js rafraîchit la rubrique via api.php. Sans JavaScript, la page reste lisible.
 */

define('VEILLE_ROOT', __DIR__);
require __DIR__ . '/config.php';
require __DIR__ . '/lib/feed.php';
require __DIR__ . '/lib/render.php';

ini_set('display_errors', '0');

// Rubrique demandée : ?cat=vigne (nouveau) ou ?id=2 (anciens permaliens).
$default = veille_find_category($VEILLE_CATEGORIES, $VEILLE_CONFIG['default_cat']);
if (!$default) { $default = $VEILLE_CATEGORIES[0]; }
$active = $default;
if (isset($_GET['cat'])) {
    $c = veille_find_category($VEILLE_CATEGORIES, (string) $_GET['cat']);
    if ($c) { $active = $c; }
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $c = veille_find_category($VEILLE_CATEGORIES, (int) $_GET['id']);
    if ($c) { $active = $c; }
}

$initial = veille_peek_category($VEILLE_CONFIG, $active);

$boot = array(
    'api'        => 'api.php',
    'remote'     => $VEILLE_CONFIG['remote_snapshot'],
    'snapshot'   => 'data/feeds.json',
    'active'     => $active['key'],
    'default'    => $default['key'],
    'categories' => array(),
    'initial'    => array('cat' => $active['key'], 'items' => $initial['items'], 'fetched' => $initial['fetched']),
);
foreach ($VEILLE_CATEGORIES as $c) {
    $boot['categories'][] = array('id' => $c['id'], 'key' => $c['key'], 'label' => $c['label'], 'group' => $c['group'], 'color' => $c['color'], 'feed' => $c['feed']);
}

$pageTitle = $active['key'] === $default['key']
    ? "On parle de nous — Veille presse et web · L'Institut Agro Montpellier"
    : $active['label'] . " — On parle de nous · L'Institut Agro Montpellier";
$canonical = $VEILLE_CONFIG['site_url'] . ($active['key'] === $default['key'] ? '' : '?cat=' . rawurlencode($active['key']));
$desc = "Ce que la presse et le web disent de l'Institut Agro et de l'Institut Agro Montpellier : articles classés par grands thèmes et axes stratégiques, mis à jour en continu.";
$e = 'veille_e';
$v = '3.0.0';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($pageTitle); ?></title>
<meta name="description" content="<?php echo $e($desc); ?>">
<link rel="canonical" href="<?php echo $e($canonical); ?>">
<meta name="theme-color" content="#282929">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_FR">
<meta property="og:site_name" content="L'Institut Agro Montpellier">
<meta property="og:title" content="<?php echo $e($pageTitle); ?>">
<meta property="og:description" content="<?php echo $e($desc); ?>">
<meta property="og:url" content="<?php echo $e($canonical); ?>">
<meta property="og:image" content="<?php echo $e($VEILLE_CONFIG['site_url']); ?>assets/img/og-image.jpg">
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="favicon.png" type="image/png">
<?php if ($VEILLE_CONFIG['remote_snapshot'] !== ''): ?><link rel="preconnect" href="<?php echo $e(preg_replace('#^(https://[^/]+).*$#', '$1', $VEILLE_CONFIG['remote_snapshot'])); ?>" crossorigin>
<?php endif; ?>
<link rel="preload" href="assets/fonts/CocoSharp-Bold.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=<?php echo $v; ?>">
<?php foreach ($VEILLE_CATEGORIES as $c): ?>
<link rel="alternate" type="application/rss+xml" title="<?php echo $e('Veille — ' . $c['label']); ?>" href="<?php echo $e($c['feed']); ?>">
<?php endforeach; ?>
</head>
<body data-cat="<?php echo $e($active['key']); ?>">
<a class="skip" href="#contenu">Aller aux articles</a>

<header class="masthead">
  <span class="forme forme--anneau" aria-hidden="true"></span>
  <span class="forme forme--disque" aria-hidden="true"></span>
  <span class="forme forme--arche" aria-hidden="true"></span>
  <div class="wrap masthead__bar">
    <a class="masthead__logo" href="https://www.institut-agro-montpellier.fr/">
      <img src="assets/img/logo-institut-agro-montpellier-blanc.svg" alt="L'Institut Agro Montpellier" width="220" height="63">
    </a>
    <span class="masthead__tag">Veille · Communication</span>
  </div>
  <div class="wrap masthead__hero">
    <p class="kicker">On parle de nous</p>
    <h1>L'Institut Agro et l'Institut Agro Montpellier <em>dans la presse et sur le web</em></h1>
    <p class="masthead__intro">Les articles repérés par notre veille, classés selon les grands thèmes et axes stratégiques de l'établissement. Mise à jour en continu.</p>
  </div>
</header>

<div class="wrap layout">
  <aside class="filters" aria-label="Rubriques">
    <div class="search">
      <label for="q" class="search__label">Rechercher</label>
      <input id="q" type="search" placeholder="Un mot, un média…" autocomplete="off">
      <label class="search__scope"><input type="checkbox" id="q-all"> dans toutes les rubriques</label>
    </div>
    <nav>
      <?php echo veille_render_filters($VEILLE_CATEGORIES, $active['key']); ?>
    </nav>
    <details class="about">
      <summary>La veille en bref</summary>
      <p>Un système de veille semi-automatique qui interroge le web en continu. Les articles jugés d'intérêt remontent sur ce portail et sont classés par thème. Seuls les <?php echo (int) $VEILLE_CONFIG['limit']; ?> derniers articles de chaque rubrique sont affichés.</p>
      <p>Conçu, développé et animé par le service communication de l'Institut Agro Montpellier.</p>
    </details>
  </aside>

  <main id="contenu" class="feed c-<?php echo $e($active['color']); ?>" tabindex="-1" aria-live="polite" aria-busy="false">
    <div class="feed__head">
      <div>
        <p class="feed__group" data-slot="group"><?php echo $e($active['group']); ?></p>
        <h2 class="feed__title" data-slot="title"><?php echo $e($active['label']); ?></h2>
      </div>
      <div class="feed__tools">
        <span class="feed__meta" data-slot="meta"><?php echo veille_render_meta($initial); ?></span>
        <a class="btn-icon" data-slot="rss" href="<?php echo $e($active['feed']); ?>" target="_blank" rel="noopener" title="S'abonner au flux RSS de cette rubrique">
          <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M5 17a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm-2-7a11 11 0 0 1 11 11h-3a8 8 0 0 0-8-8v-3Zm0-7a18 18 0 0 1 18 18h-3A15 15 0 0 0 3 6V3Z"/></svg>
          <span class="sr">Flux RSS</span>
        </a>
        <button class="btn-icon" type="button" data-action="copy-permalink" title="Copier le lien de cette rubrique">
          <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M10.6 13.4a1 1 0 0 1 0-1.4l3.5-3.5a3 3 0 1 1 4.2 4.2l-2 2-1.4-1.4 2-2a1 1 0 1 0-1.4-1.4L12 13.4a1 1 0 0 1-1.4 0ZM13.4 10.6a1 1 0 0 1 0 1.4l-3.5 3.5a3 3 0 1 1-4.2-4.2l2-2 1.4 1.4-2 2a1 1 0 1 0 1.4 1.4L12 10.6a1 1 0 0 1 1.4 0Z"/></svg>
          <span class="sr">Copier le lien</span>
        </button>
      </div>
    </div>
    <div class="notice" data-slot="notice" hidden></div>
    <div class="cards" data-slot="cards">
      <?php echo veille_render_cards($initial['items']); ?>
    </div>
  </main>
</div>

<footer class="footer">
  <span class="forme forme--footer" aria-hidden="true"></span>
  <div class="wrap footer__inner">
    <img src="assets/img/logo-institut-agro-montpellier-blanc.svg" alt="L'Institut Agro Montpellier" width="180" height="52">
    <div class="footer__contacts">
      <p>Un outil développé et animé par le service communication de l'Institut Agro Montpellier.</p>
      <ul>
        <li><strong>Communication et relations presse</strong> <a href="mailto:ghyslaine.besancon@supagro.fr">ghyslaine.besancon@supagro.fr</a></li>
        <li><strong>Accueil des colloques et événementiel</strong> <a href="mailto:laurent.cachard@supagro.fr">laurent.cachard@supagro.fr</a></li>
        <li><strong>Conception, veille et service web</strong> <a href="mailto:olivier.piau@supagro.fr">olivier.piau@supagro.fr</a></li>
      </ul>
      <p class="footer__legal">Version 3.0 · © <?php echo date('Y'); ?> L'Institut Agro Montpellier · Les articles restent la propriété de leurs éditeurs.</p>
    </div>
  </div>
</footer>

<div class="toast" role="status" aria-live="polite" hidden></div>

<script type="application/json" id="veille-boot"><?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="assets/app.js?v=<?php echo $v; ?>" defer></script>

<!-- Matomo -->
<script>
  var _paq = window._paq = window._paq || [];
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u = "https://analytics-sc.institut-agro.fr/";
    _paq.push(['setTrackerUrl', u + 'matomo.php']);
    _paq.push(['setSiteId', '<?php echo (int) $VEILLE_CONFIG['matomo_site_id']; ?>']);
    var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
    g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
  })();
</script>
</body>
</html>
