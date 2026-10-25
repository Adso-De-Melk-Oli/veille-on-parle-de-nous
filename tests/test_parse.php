<?php
require __DIR__.'/../lib/feed.php';
$x = file_get_contents(__DIR__.'/sample-innovation.xml');
$items = veille_parse_feed($x, 20);
echo count($items)," items\n";
foreach ($items as $i) { echo "- [{$i['source']}] {$i['title']}\n  ", substr($i['date'],0,10), " img=", $i['image'] ? 'Y '.substr($i['image'],0,70) : '-', "\n  ", substr($i['excerpt'],0,120), "\n"; }
// error cases
foreach (array('', '<html><body>404</body></html>', '<rss><channel><item><title>x', "\xEF\xBB\xBF<?xml version=\"1.0\"?><rss><channel></channel></rss>") as $bad) {
  try { $r = veille_parse_feed($bad, 5); echo "ok ", count($r), "\n"; } catch (Exception $e) { echo "ERR ", $e->getMessage(), "\n"; }
}
// google news title
print_r(veille_source_name("Classement THE 2026 : l'Institut Agro progresse - AEF info", 'https://news.google.com/rss/articles/x', ''));
