/*!
 * Veille « On parle de nous » — L'Institut Agro Montpellier · v3.0
 * Vanilla JS, sans dépendance. Fonctionne avec api.php (PHP) ou, à défaut, avec data/feeds.json.
 */
(function () {
  'use strict';

  var bootEl = document.getElementById('veille-boot');
  if (!bootEl) return;
  var BOOT;
  try { BOOT = JSON.parse(bootEl.textContent); } catch (e) { return; }

  var CATS = BOOT.categories;
  var byKey = {};
  CATS.forEach(function (c) { byKey[c.key] = c; });
  var DEFAULT = byKey[BOOT.default] ? BOOT.default : CATS[0].key;

  var PAGE = 12;                 // cartes affichées avant « Voir plus »
  var NEW_DAYS = 7;              // pastille « nouveau » dans les filtres
  var state = {
    active: BOOT.active,
    data: {},                    // key → { items, status, fetched, error }
    pending: {},                 // key → Promise
    mode: 'api',                 // 'api' puis 'static' si api.php ne répond pas en JSON
    query: '',
    queryAll: false,
    shown: PAGE
  };

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var main = $('#contenu');
  var slot = function (n) { return $('[data-slot="' + n + '"]', main); };

  if (BOOT.initial && BOOT.initial.items && BOOT.initial.items.length) {
    state.data[BOOT.initial.cat] = { items: BOOT.initial.items, status: 'boot', fetched: BOOT.initial.fetched, error: '' };
  }

  /* ---------------------------------------------------------------- Données */

  function fetchJSON(url, timeoutMs) {
    return new Promise(function (resolve, reject) {
      var done = false;
      var timer = setTimeout(function () { if (!done) { done = true; reject(new Error('timeout')); } }, timeoutMs || 20000);
      fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) {
          var ct = r.headers.get('content-type') || '';
          if (ct.indexOf('json') === -1) throw new Error('not-json'); // PHP non exécuté, page d'erreur…
          return r.json().then(function (j) { return { status: r.status, json: j }; });
        })
        .then(function (res) { if (!done) { done = true; clearTimeout(timer); resolve(res); } })
        .catch(function (err) { if (!done) { done = true; clearTimeout(timer); reject(err); } });
    });
  }

  var snapshotPromise = null;
  function loadSnapshot() {
    if (!snapshotPromise) {
      snapshotPromise = fetchJSON(BOOT.snapshot + '?t=' + Math.floor(Date.now() / 600000), 15000)
        .then(function (res) { return res.json && res.json.categories ? res.json : { categories: {} }; })
        .catch(function () { return { categories: {} }; });
    }
    return snapshotPromise;
  }

  function fromSnapshot(key) {
    return loadSnapshot().then(function (snap) {
      var c = snap.categories[key];
      if (c && c.items && c.items.length) {
        return { items: c.items, status: 'snapshot', fetched: c.fetched || snap.generated || null, error: '' };
      }
      return { items: [], status: 'error', fetched: null, error: 'unavailable' };
    });
  }

  /* Source principale : le fichier publié par GitHub toutes les 30 minutes (toutes les rubriques d'un coup).
     Le serveur SupAgro n'a pas besoin de sortir sur Internet : c'est le navigateur qui le lit. */
  var STALE_HOURS = 3;
  var remotePromise = null, remoteAt = 0;
  function loadRemote(force) {
    if (!BOOT.remote) return Promise.resolve(null);
    if (!remotePromise || force || Date.now() - remoteAt > 600000) {
      remoteAt = Date.now();
      var bust = force ? Date.now() : Math.floor(Date.now() / 300000);
      var url = BOOT.remote + (BOOT.remote.indexOf('?') === -1 ? '?' : '&') + 't=' + bust;
      remotePromise = new Promise(function (resolve) {
        var timer = setTimeout(function () { resolve(null); }, 10000);
        fetch(url, { credentials: 'omit', cache: 'no-cache' })
          .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })  // GitHub sert text/plain : pas de contrôle du type
          .then(function (j) { clearTimeout(timer); resolve(j && j.categories ? j : null); })
          .catch(function () { clearTimeout(timer); resolve(null); });
      });
    }
    return remotePromise;
  }

  function load(key, force, refresh) {
    if (!force && state.data[key] && state.data[key].status !== 'boot') return Promise.resolve(state.data[key]);
    if (!force && state.pending[key]) return state.pending[key];

    var p = loadRemote(refresh).then(function (remote) {
      var c = remote && remote.categories[key];
      if (c && c.items && c.items.length) {
        var fetched = c.fetched || remote.generated || null;
        var age = fetched ? (Date.now() - new Date(fetched).getTime()) / 3600000 : 0;
        return { items: c.items, status: age > STALE_HOURS ? 'stale' : 'fresh', fetched: fetched, error: '' };
      }
      return fromServer(key, refresh);
    });
    return finish(key, p);
  }

  /* Repli : api.php (si le serveur peut lire les flux), puis l'instantané local data/feeds.json. */
  function fromServer(key, refresh) {
    var p;
    if (state.mode === 'api') {
      p = fetchJSON(BOOT.api + '?cat=' + encodeURIComponent(key) + (refresh ? '&refresh=1' : ''), 25000)
        .then(function (res) {
          var j = res.json || {};
          if (j.items && j.items.length) {
            return { items: j.items, status: j.status || 'fresh', fetched: j.fetched || null, error: j.error || '' };
          }
          return fromSnapshot(key);
        })
        .catch(function (err) {
          // api.php inutilisable (pas de PHP, 500…) : on bascule en mode statique pour la suite.
          if (err && err.message === 'not-json') state.mode = 'static';
          return fromSnapshot(key);
        });
    } else {
      p = fromSnapshot(key);
    }
    return p;
  }

  function finish(key, p) {
    p = p.then(function (d) {
      // Ne jamais remplacer des articles affichés par une liste vide.
      var prev = state.data[key];
      if ((!d.items || !d.items.length) && prev && prev.items && prev.items.length) {
        d = { items: prev.items, status: 'stale', fetched: prev.fetched, error: 'unavailable' };
      }
      state.data[key] = d;
      delete state.pending[key];
      updateCount(key);
      return d;
    });
    state.pending[key] = p;
    return p;
  }

  /* Charge les autres rubriques en arrière-plan (compteurs + recherche globale), 2 par 2. */
  function preloadOthers() {
    var queue = CATS.map(function (c) { return c.key; }).filter(function (k) { return k !== state.active; });
    function next() {
      var k = queue.shift();
      if (!k) return;
      load(k).then(next, next);
    }
    var start = function () { next(); next(); };
    if ('requestIdleCallback' in window) requestIdleCallback(start, { timeout: 3000 }); else setTimeout(start, 1200);
  }

  /* ---------------------------------------------------------------- Rendu */

  var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  function dateFr(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d)) return '';
    return d.getUTCDate() + ' ' + MONTHS[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
  }
  function relative(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return '';
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days < 1) return "aujourd'hui";
    if (days < 2) return 'hier';
    if (days < 7) return 'il y a ' + days + ' jours';
    return '';
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function norm(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }
  function highlight(text, q) {
    var safe = esc(text);
    if (!q) return safe;
    var words = q.split(/\s+/).filter(function (w) { return w.length > 1; });
    if (!words.length) return safe;
    // Surligne sur la version sans accents en conservant le texte d'origine.
    var plain = norm(text);
    var marks = [];
    words.forEach(function (w) {
      var nw = norm(w), from = 0, i;
      while ((i = plain.indexOf(nw, from)) !== -1) { marks.push([i, i + nw.length]); from = i + nw.length; }
    });
    if (!marks.length) return safe;
    marks.sort(function (a, b) { return a[0] - b[0]; });
    var out = '', pos = 0;
    marks.forEach(function (m) {
      if (m[0] < pos) return;
      out += esc(text.slice(pos, m[0])) + '<mark>' + esc(text.slice(m[0], m[1])) + '</mark>';
      pos = m[1];
    });
    return out + esc(text.slice(pos));
  }

  function card(it, i, opts) {
    var featured = opts.featured;
    var source = it.source || it.domain || '';
    var rel = it.date ? relative(it.date) : '';
    var img = it.image
      ? '<img src="' + esc(it.image) + '" alt="" loading="' + (i < 3 ? 'eager' : 'lazy') + '" decoding="async" referrerpolicy="no-referrer">'
      : '';
    var cat = opts.cat ? byKey[opts.cat] : null;
    return '<article class="card ph-' + phIndex(source) + (featured ? ' card--featured' : '') + (img ? '' : ' card--noimg') + (cat ? ' c-' + cat.color : '') + '" style="--i:' + Math.min(i, 12) + '">' +
      '<a class="card__media" href="' + esc(it.link) + '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">' +
        img + '<span class="card__ph"><span>' + esc((source.charAt(0) || '•').toUpperCase()) + '</span></span></a>' +
      '<div class="card__body">' +
        '<p class="card__meta">' +
          (cat ? '<span class="card__cat">' + esc(cat.label) + '</span>' : '') +
          (source ? '<span class="card__source">' + highlight(source, opts.q) + '</span>' : '') +
          (it.date ? '<time datetime="' + esc(it.date) + '" title="' + esc(dateFr(it.date)) + '">' + esc(rel || dateFr(it.date)) + '</time>' : '') +
        '</p>' +
        '<h3 class="card__title"><a href="' + esc(it.link) + '" target="_blank" rel="noopener">' + highlight(it.title, opts.q) + '</a></h3>' +
        (it.excerpt ? '<p class="card__excerpt">' + highlight(it.excerpt, opts.q) + '</p>' : '') +
      '</div></article>';
  }

  function phIndex(s) {
    // Même calcul que lib/render.php (octets UTF-8) pour garder la même couleur au rafraîchissement.
    var b = unescape(encodeURIComponent(s)), h = 0;
    for (var i = 0; i < b.length; i++) h = (h * 31 + b.charCodeAt(i)) % 9973;
    return h % 6;
  }

  function skeleton(n) {
    var h = '';
    for (var i = 0; i < n; i++) {
      h += '<article class="card card--skeleton' + (i === 0 ? ' card--featured' : '') + '" aria-hidden="true"><div class="card__media"></div>' +
        '<div class="card__body"><div class="sk sk--s"></div><div class="sk sk--t"></div><div class="sk sk--l"></div><div class="sk"></div></div></article>';
    }
    return h;
  }

  function matches(it, q) {
    if (!q) return true;
    var hay = norm(it.title + ' ' + (it.excerpt || '') + ' ' + (it.source || '') + ' ' + (it.domain || ''));
    return norm(q).split(/\s+/).every(function (w) { return !w || hay.indexOf(w) !== -1; });
  }

  function setHead(cat, label, group) {
    slot('title').textContent = label;
    slot('group').textContent = group;
    var rss = slot('rss');
    if (cat) { rss.href = cat.feed; rss.hidden = false; } else { rss.hidden = true; }
    main.className = 'feed' + (cat ? ' c-' + cat.color : '');
  }

  function notice(html) {
    var n = slot('notice');
    if (!html) { n.hidden = true; n.innerHTML = ''; return; }
    n.innerHTML = html;
    n.hidden = false;
  }

  function fetchedLabel(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d)) return '';
    return dateFr(iso) + ' à ' + ('0' + d.getHours()).slice(-2) + 'h' + ('0' + d.getMinutes()).slice(-2);
  }

  function render() {
    var q = state.query.trim();
    var cardsEl = slot('cards');

    // Recherche dans toutes les rubriques
    if (q && state.queryAll) {
      setHead(null, 'Résultats pour « ' + q + ' »', 'Toutes les rubriques');
      notice('');
      var seen = {}, results = [];
      CATS.forEach(function (c) {
        var d = state.data[c.key];
        if (!d || !d.items) return;
        d.items.forEach(function (it) {
          if (seen[it.link] || !matches(it, q)) return;
          seen[it.link] = 1;
          results.push({ it: it, cat: c.key });
        });
      });
      results.sort(function (a, b) { return (b.it.ts || 0) - (a.it.ts || 0); });
      var loading = CATS.some(function (c) { return state.pending[c.key]; });
      slot('meta').textContent = results.length + ' résultat' + (results.length > 1 ? 's' : '') + (loading ? ' (recherche en cours…)' : '');
      cardsEl.innerHTML = results.length
        ? results.slice(0, state.shown).map(function (r, i) { return card(r.it, i, { q: q, cat: r.cat }); }).join('') + moreButton(results.length)
        : emptyState('Aucun article ne correspond', 'Essayez un autre mot-clé, ou le nom d\'un média.', false);
      main.setAttribute('aria-busy', loading ? 'true' : 'false');
      return;
    }

    var cat = byKey[state.active];
    setHead(cat, cat.label, cat.group);
    var d = state.data[state.active];

    if (!d || (d.status === 'boot' && !d.items.length)) {
      slot('meta').textContent = '';
      notice('');
      cardsEl.innerHTML = skeleton(5);
      main.setAttribute('aria-busy', 'true');
      return;
    }
    main.setAttribute('aria-busy', state.pending[state.active] ? 'true' : 'false');

    // Bandeau d'état
    if (d.status === 'stale' || d.status === 'snapshot') {
      notice('<p>Le flux de cette rubrique est momentanément indisponible. Vous consultez la dernière version enregistrée' +
        (d.fetched ? ', du ' + esc(fetchedLabel(d.fetched)) : '') + '.</p><button type="button" data-action="retry">Réessayer</button>');
    } else {
      notice('');
    }

    if (!d.items.length) {
      slot('meta').textContent = '';
      cardsEl.innerHTML = emptyState('Ce flux ne répond pas pour le moment',
        'La source de cette rubrique est indisponible ou en cours de maintenance. Réessayez dans quelques minutes, ou consultez une autre rubrique.', true);
      return;
    }

    var list = d.items.filter(function (it) { return matches(it, q); });
    slot('meta').textContent = q
      ? list.length + ' sur ' + d.items.length + ' article' + (d.items.length > 1 ? 's' : '')
      : d.items.length + ' article' + (d.items.length > 1 ? 's' : '');

    if (!list.length) {
      cardsEl.innerHTML = emptyState('Aucun article ne correspond', 'Aucun article de cette rubrique ne contient « ' + esc(q) + ' ». Cochez « dans toutes les rubriques » pour élargir la recherche.', false);
      return;
    }

    // À la une : le premier article avec photo parmi les trois plus récents.
    if (!q) {
      var f = -1;
      for (var i = 0; i < Math.min(3, list.length); i++) { if (list[i].image) { f = i; break; } }
      if (f > 0) list = [list[f]].concat(list.slice(0, f), list.slice(f + 1));
    }
    cardsEl.innerHTML = list.slice(0, state.shown).map(function (it, i) {
      return card(it, i, { q: q, featured: i === 0 && !q });
    }).join('') + moreButton(list.length);
  }

  function moreButton(total) {
    return total > state.shown
      ? '<div class="more"><button class="btn btn--ghost" type="button" data-action="more">Voir les ' + (total - state.shown) + ' autres articles</button></div>'
      : '';
  }

  function emptyState(title, text, retry) {
    return '<div class="empty"><p class="empty__title">' + esc(title) + '</p><p>' + text + '</p>' +
      (retry ? '<button class="btn" type="button" data-action="retry">Réessayer</button>' : '') +
      (retry ? '<a class="btn btn--ghost" href="' + esc(byKey[state.active].feed) + '" target="_blank" rel="noopener">Ouvrir le flux source</a>' : '') +
      '</div>';
  }

  function updateCount(key) {
    var el = $('[data-count="' + key + '"]');
    if (!el) return;
    var d = state.data[key];
    var link = el.closest('.filter');
    if (!d) return;
    if (!d.items.length) {
      el.textContent = '';
      link.classList.add('is-error');
      link.title = 'Flux momentanément indisponible';
      return;
    }
    link.classList.remove('is-error');
    link.removeAttribute('title');
    el.textContent = d.items.length;
    var newest = d.items.reduce(function (m, it) { return Math.max(m, it.ts || 0); }, 0);
    var isNew = newest && (Date.now() / 1000 - newest) < NEW_DAYS * 86400;
    el.classList.toggle('is-new', !!isNew);
    if (isNew) el.setAttribute('aria-label', d.items.length + ' articles, dont des nouveautés');
  }

  /* ---------------------------------------------------------------- Navigation */

  function select(key, push) {
    if (!byKey[key]) return;
    state.active = key;
    state.shown = PAGE;
    $$('.filter').forEach(function (a) {
      var on = a.getAttribute('data-cat') === key;
      a.classList.toggle('is-active', on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    document.body.setAttribute('data-cat', key);
    document.title = (key === DEFAULT ? 'On parle de nous — Veille presse et web' : byKey[key].label + ' — On parle de nous') + " · L'Institut Agro Montpellier";
    if (push) {
      var url = key === DEFAULT ? location.pathname : location.pathname + '?cat=' + encodeURIComponent(key);
      history.pushState({ cat: key }, '', url);
      if (window._paq) { window._paq.push(['setCustomUrl', location.href]); window._paq.push(['trackPageView', byKey[key].label]); }
    }
    render();
    load(key).then(function () { if (state.active === key) render(); });
  }

  document.addEventListener('click', function (e) {
    var f = e.target.closest('.filter');
    if (f && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
      e.preventDefault();
      if (state.queryAll && state.query) { state.query = ''; $('#q').value = ''; }
      select(f.getAttribute('data-cat'), true);
      if (window.matchMedia('(max-width: 960px)').matches) {
        f.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
        main.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
      return;
    }
    var a = e.target.closest('[data-action]');
    if (!a) return;
    var act = a.getAttribute('data-action');
    if (act === 'retry') {
      a.disabled = true;
      a.textContent = 'Nouvelle tentative…';
      var k = state.active;
      state.pending[k] = null;
      load(k, true, true).then(function () { if (state.active === k) render(); });
    } else if (act === 'more') {
      state.shown += PAGE;
      render();
    } else if (act === 'copy-permalink') {
      copy(location.href);
    }
  });

  // Flèches haut/bas dans la liste des rubriques
  $('.filters nav').addEventListener('keydown', function (e) {
    if (['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) === -1) return;
    var links = $$('.filter');
    var i = links.indexOf(document.activeElement);
    if (i === -1) return;
    e.preventDefault();
    var n = e.key === 'Home' ? 0 : e.key === 'End' ? links.length - 1
      : (i + ((e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : -1) + links.length) % links.length;
    links[n].focus();
  });

  window.addEventListener('popstate', function () {
    var m = /[?&]cat=([^&]+)/.exec(location.search);
    select(m ? decodeURIComponent(m[1]) : DEFAULT, false);
  });

  // Recherche
  var qTimer;
  $('#q').addEventListener('input', function (e) {
    clearTimeout(qTimer);
    var v = e.target.value;
    qTimer = setTimeout(function () { state.query = v; state.shown = PAGE; render(); }, 160);
  });
  $('#q').addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.target.value = ''; state.query = ''; render(); } });
  $('#q-all').addEventListener('change', function (e) {
    state.queryAll = e.target.checked;
    state.shown = PAGE;
    if (state.queryAll) CATS.forEach(function (c) { load(c.key).then(function () { if (state.queryAll && state.query) render(); }); });
    render();
  });

  // Photos cassées → visuel de remplacement aux couleurs de la charte
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && t.closest('.card__media')) {
      t.closest('.card').classList.add('card--noimg');
      t.remove();
    }
  }, true);
  // Images minuscules (pixels de suivi, logos) → remplacées aussi
  document.addEventListener('load', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && t.closest('.card__media') && t.naturalWidth && t.naturalWidth < 160) {
      t.closest('.card').classList.add('card--noimg');
      t.remove();
    }
  }, true);

  function copy(text) {
    var ok = function () { toast('Lien copié'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(ok, function () { prompt('Copiez ce lien :', text); });
    } else {
      prompt('Copiez ce lien :', text);
    }
  }
  var toastTimer;
  function toast(msg) {
    var t = $('.toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, 2200);
  }

  /* ---------------------------------------------------------------- Démarrage */
  if (state.data[state.active]) updateCount(state.active);
  // Mobile : la pastille active est visible dans la barre défilante.
  var act = $('.filter.is-active');
  if (act && window.matchMedia('(max-width: 960px)').matches) {
    var nav = act.closest('nav');
    nav.scrollLeft = act.offsetLeft - nav.clientWidth / 2 + act.clientWidth / 2;
  }
  render();
  load(state.active, true, false).then(function () { render(); preloadOthers(); });
})();
