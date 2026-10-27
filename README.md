# On parle de nous — veille presse et web · v3.0

Refonte de https://www.supagro.fr/web/services/on-parle-de-nous/ aux couleurs de l'Institut Agro Montpellier
(Coco Sharp, orange #f8ac00, noir #282929, formes du logo).

Les 12 rubriques de gauche et leurs flux Inoreader sont repris à l'identique.
Les anciens permaliens `index.php?id=0` … `?id=11` fonctionnent toujours ; les nouveaux sont `?cat=vigne`, `?cat=fondation`…

## Installation (FTP)

1. Sauvegarder le dossier actuel `on-parle-de-nous/`.
2. Déposer **tout le contenu** de ce dossier à la place (garder `favicon.png` existant).
3. Vérifier que `cache/` est **inscriptible** par PHP (chmod 755 ou 775 selon l'hébergement).
4. Ouvrir `api.php?status=1` : il indique la version de PHP, si `allow_url_fopen` et `openssl` sont actifs,
   et si le cache est inscriptible. Il faut au moins l'un des deux premiers à `true`.

Les anciens fichiers (`css/`, `js/`, `images/`, `fonts/` Lato) ne servent plus : ils peuvent être supprimés après vérification.

## Comment ça marche

| Fichier | Rôle |
| --- | --- |
| `config.php` | Rubriques (libellé, couleur, flux), durée du cache, nombre d'articles |
| `index.php` | La page ; affiche tout de suite la dernière version connue, même sans JavaScript |
| `api.php` | JSON d'une rubrique : `api.php?cat=vigne` |
| `lib/http.php` | Lecture des flux **sans cURL** : `file_get_contents`, puis socket TLS (`stream_socket_client` / `fsockopen`) |
| `lib/feed.php` | Lecture RSS/Atom, photos, nom du média, dédoublonnage, cache |
| `assets/app.js` | Filtres, recherche, chargement, états d'erreur (JS natif, sans jQuery) |
| `data/feeds.json` | Instantané de secours |

### Pannes et erreurs de flux (côté serveur, si `api.php` est utilisé)

Pour chaque rubrique, dans cet ordre :

1. **Cache frais** (moins de 30 min) → affiché directement, sans appeler Inoreader.
2. Sinon, **lecture du flux**. Réponse vide, page HTML, XML cassé, délai dépassé : tout est intercepté.
3. Flux en panne → **dernière version en cache** (jusqu'à 30 jours), avec un bandeau « flux momentanément
   indisponible » et un bouton Réessayer. Pendant une panne, le flux n'est réinterrogé qu'une fois toutes les 5 min.
4. Pas de cache → **instantané** `data/feeds.json`.
5. Rien du tout → message clair, bouton Réessayer et lien vers le flux source. Les autres rubriques restent utilisables ;
   la rubrique en panne est marquée d'un « ! » dans les filtres.

Si PHP ne répond pas en JSON (PHP désactivé, erreur 500), le navigateur bascule seul sur `data/feeds.json`.

### Photos

Prises dans le flux : `media:content`, `media:thumbnail`, `enclosure`, puis première image de l'article.
Les pictos, logos, boutons de partage et pixels de suivi sont écartés ; les images `http://` passent en `https://`.
Une photo absente ou cassée est remplacée par un visuel aux couleurs de la charte (initiale du média sur une forme du logo).

## Lecture des flux par GitHub (source principale)

Le serveur SupAgro ne peut pas sortir sur Internet. C'est donc GitHub qui lit les flux :

1. `.github/workflows/snapshot.yml` tourne **toutes les 30 minutes** : il lit les 12 flux Inoreader
   et publie le résultat dans la branche `flux` du dépôt (un seul fichier `feeds.json`, sans historique).
2. La page charge ce fichier **depuis le navigateur du visiteur** (adresse `remote_snapshot` dans `config.php`).
   Un seul téléchargement donne les 12 rubriques.
3. Si GitHub ne répond pas, la page se rabat sur `api.php`, puis sur l'instantané local `data/feeds.json`.
4. La date de la dernière lecture s'affiche à côté du nombre d'articles. Le bandeau « mise à jour interrompue » n'apparaît
   que si la dernière lecture réussie date de plus de 24 heures.

GitHub espace en pratique les tâches planifiées des petits dépôts (souvent toutes les 4 à 6 heures).

Le dépôt doit être **public** (le navigateur lit le fichier sans identifiant).
Lancer une lecture à la main : onglet *Actions* → *Lecture des flux* → *Run workflow*.

GitHub suspend les tâches planifiées d'un dépôt sans activité pendant 60 jours : il envoie alors un e-mail,
et un clic sur *Enable workflow* suffit à les relancer.

## Ajouter une rubrique

Ajouter une ligne dans `$VEILLE_CATEGORIES` (`config.php`) : `key` unique, `group`, `label`,
`color` (`orange`, `yellow`, `green`, `blue`, `purple`, `pink`) et l'adresse du flux.
