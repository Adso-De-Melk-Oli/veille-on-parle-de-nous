<?php
/**
 * Gabarits HTML rendus côté serveur (premier affichage et visiteurs sans JavaScript).
 * assets/app.js produit exactement le même balisage.
 */

function veille_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$GLOBALS['VEILLE_MONTHS'] = array('', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');

function veille_date_fr($iso)
{
    $ts = $iso ? strtotime($iso) : false;
    if (!$ts) {
        return '';
    }
    $m = $GLOBALS['VEILLE_MONTHS'];
    return (int) gmdate('j', $ts) . ' ' . $m[(int) gmdate('n', $ts)] . ' ' . gmdate('Y', $ts);
}

function veille_render_filters($cats, $activeKey)
{
    $html = '';
    $group = null;
    foreach ($cats as $c) {
        if ($c['group'] !== $group) {
            if ($group !== null) {
                $html .= '</ul>';
            }
            $group = $c['group'];
            $html .= '<p class="filters__group">' . veille_e($group) . '</p><ul class="filters__list" role="list">';
        }
        $on = $c['key'] === $activeKey;
        $html .= '<li><a class="filter c-' . veille_e($c['color']) . ($on ? ' is-active' : '') . '"'
               . ' href="?cat=' . rawurlencode($c['key']) . '" data-cat="' . veille_e($c['key']) . '"'
               . ($on ? ' aria-current="page"' : '') . '>'
               . '<span class="filter__dot" aria-hidden="true"></span>'
               . '<span class="filter__label">' . veille_e($c['label']) . '</span>'
               . '<span class="filter__count" data-count="' . veille_e($c['key']) . '"></span>'
               . '</a></li>';
    }
    return $html . '</ul>';
}

function veille_render_meta($r)
{
    if (empty($r['items'])) {
        return '';
    }
    return count($r['items']) . ' article' . (count($r['items']) > 1 ? 's' : '');
}

function veille_render_cards($items)
{
    if (!$items) {
        return '<div class="empty"><p class="empty__title">Chargement des articles…</p>'
             . '<p>Si rien ne s\'affiche, le flux de cette rubrique est momentanément indisponible.</p></div>';
    }
    $html = '';
    foreach ($items as $i => $it) {
        $html .= veille_render_card($it, $i === 0);
    }
    return $html;
}

function veille_render_card($it, $featured)
{
    $source = $it['source'] !== '' ? $it['source'] : $it['domain'];
    $img = $it['image'] !== ''
        ? '<img src="' . veille_e($it['image']) . '" alt="" loading="' . ($featured ? 'eager' : 'lazy') . '" decoding="async" referrerpolicy="no-referrer">'
        : '';
    $initial = function_exists('mb_substr') ? mb_substr($source, 0, 1, 'UTF-8') : substr($source, 0, 1);

    $h = 0;
    $len = strlen($source);
    for ($k = 0; $k < $len; $k++) {
        $h = ($h * 31 + ord($source[$k])) % 9973;
    }
    return '<article class="card ph-' . ($h % 6) . ($featured ? ' card--featured' : '') . ($img === '' ? ' card--noimg' : '') . '">'
         . '<a class="card__media" href="' . veille_e($it['link']) . '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">'
         . $img . '<span class="card__ph"><span>' . veille_e(strtoupper($initial)) . '</span></span></a>'
         . '<div class="card__body">'
         . '<p class="card__meta"><span class="card__source">' . veille_e($source) . '</span>'
         . ($it['date'] ? '<time datetime="' . veille_e($it['date']) . '">' . veille_e(veille_date_fr($it['date'])) . '</time>' : '')
         . '</p>'
         . '<h3 class="card__title"><a href="' . veille_e($it['link']) . '" target="_blank" rel="noopener">' . veille_e($it['title']) . '</a></h3>'
         . ($it['excerpt'] !== '' ? '<p class="card__excerpt">' . veille_e($it['excerpt']) . '</p>' : '')
         . '</div></article>';
}
