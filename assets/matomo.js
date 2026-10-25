/* Mesure d'audience Matomo de l'Institut Agro (site 18) */
(function () {
  var el = document.getElementById('veille-boot');
  var id = 18;
  try { id = parseInt(JSON.parse(el.textContent).matomo, 10) || 18; } catch (e) {}
  var _paq = window._paq = window._paq || [];
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  var u = 'https://analytics-sc.institut-agro.fr/';
  _paq.push(['setTrackerUrl', u + 'matomo.php']);
  _paq.push(['setSiteId', String(id)]);
  var g = document.createElement('script');
  g.async = true; g.src = u + 'matomo.js';
  document.head.appendChild(g);
})();
