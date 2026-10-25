<?php
// Construit data/feeds.json à partir de la capture du 25/10/2026 (format compact du navigateur).
define('VEILLE_ROOT', dirname(__DIR__));
require VEILLE_ROOT.'/config.php';
require VEILLE_ROOT.'/lib/feed.php';
$s = json_decode(file_get_contents(__DIR__.'/sample-compact.json'), true);
$out = array('generated' => '2026-10-25T17:20:00+00:00', 'categories' => array());
foreach ($s as $key => $list) {
  $items = array(); $seen = array();
  foreach ($list as $r) {
    $title = veille_text($r['t']);
    $src = veille_source_name($title, $r['l'], veille_text($r['s']));
    if ($src['from_title']) $title = $src['clean_title'];
    $img = $r['i'];
    if ($img && strpos($img,'//')===0) $img='https:'.$img;
    if ($img && preg_match('#\.(svg|gif)(\?|$)|cpt_global|supportduweb|guillemet|/(icons?|logos?)[/._-]#i',$img)) $img='';
    $img = preg_replace('#^http://#i','https://',$img);
    $ex = veille_excerpt(htmlspecialchars($r['x'], ENT_QUOTES, 'UTF-8'), 260);
    if ($ex !== '' && stripos($ex, $title) === 0) $ex = '';
    $ts = strtotime($r['d']);
    $it = array('title'=>$title,'link'=>$r['l'],'date'=>$ts?gmdate('c',$ts):null,'ts'=>$ts?:0,'excerpt'=>$ex,'image'=>$img,'source'=>$src['name'],'domain'=>$src['domain']);
    $k = veille_dedupe_key($it); if (isset($seen[$k])) continue; $seen[$k]=1; $items[]=$it;
  }
  $out['categories'][$key] = array('fetched'=>$out['generated'],'items'=>$items);
  echo "$key ", count($items), " (", count(array_filter($items, function($i){return $i['image'];})), " img)\n";
}
@mkdir(VEILLE_ROOT.'/data');
file_put_contents(VEILLE_ROOT.'/data/feeds.json', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
