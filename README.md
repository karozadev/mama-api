# Mama — le met du jour

API de Mama, le réseau social culinaire malgache : les mamans partagent le « met du jour ».
Symfony 7.4 · API Platform 4.4 · PostgreSQL · Redis · Mercure · JWT.

## Démarrage

```bash
make start      # build, containers, migrations
make fixtures   # données de démo (mot de passe de tous les comptes : "password")
```

- API : http://localhost:8080/api (docs : `/api/docs`)
- Mercure : http://localhost:8081/.well-known/mercure
- Comptes de démo : `rasoa@example.com` (ROLE_CHEF), `tiana@example.com` (abonnée), `admin@example.com`

Mise en production, sauvegardes, certificats et modération : voir [docs/DEPLOY.md](docs/DEPLOY.md).

`make help` liste toutes les commandes (`test`, `cs`, `stan`, `reset-db`, `logs`, `sh`…).

## Endpoints principaux

| Méthode | URL | Description |
|---|---|---|
| POST | `/api/users` | Inscription |
| POST | `/api/login_check` | Connexion → JWT |
| GET | `/api/me`, `/api/me/mercure-token` | Profil, token SSE privé |
| POST/DELETE | `/api/users/{id}/follow` | Suivre / ne plus suivre une maman |
| POST/DELETE | `/api/dishes/{id}/like` | Aimer / ne plus aimer un met (♥) |
| GET | `/api/me/likes`, `/api/me/following` | Mets aimés ; mamans suivies (« stories ») |
| GET/POST | `/api/me/notifications?filter=all\|mentions\|market`, `/api/me/notifications/read` | Historique (onglets Toutes / Mentions / Marché), bannière de classement, cœurs regroupés ; tout marquer lu |
| GET | `/api/stats` | Compteurs publics (écran d'accueil) |
| POST/DELETE | `/api/dishes/{id}/bookmark` | Ajouter / retirer des favoris |
| GET | `/api/me/bookmarks`, `/api/me/bookmarks/dishes` | Ids des favoris ; les mets favoris |
| GET | `/api/search?q=` | Recherche : mets (titre, description, ingrédient) et mamans |
| GET | `/api/discover/mothers` | « Nouveaux talents » non suivies |
| GET | `/api/dishes/{id}/rank` | Rang dans le Top 10 du jour (« #1 aujourd'hui »), ou `null` |
| POST | `/api/dishes/{id}/cook` | « Je cuisine ce met » : prévient l'auteure (1 fois par jour et par maman) |
| GET | `/api/discover/trend` | Cœurs des dernières 24 h et variation vs. les 24 h d'avant (« +32 % ») |
| GET | `/api/users/{id}/bookmarks` | Favoris d'une maman (si elle les partage : `favoritesPublic`) |
| GET | `/api/discover/chef-of-the-week` | Cheffe de la semaine (≥ 3 cœurs sur 7 jours, mis en cache 1 h) |
| GET/POST | `/api/me/collections`, `/api/collections` | Mes collections ; en créer une |
| GET/PATCH/DELETE | `/api/collections/{id}` | Voir (mets visibles), renommer / publique-privée, supprimer |
| POST/DELETE | `/api/collections/{id}/dishes/{dishId}` | Ajouter / retirer un met |
| GET | `/api/users/{id}/collections` | Collections publiques d'une maman |
| GET | `/api/me/conversations` | Boîte de messages (dernier message, non lus) |
| GET/POST | `/api/conversations/{userId}/messages` | Historique (`?before=`) ; envoyer |
| POST | `/api/conversations/{userId}/read` | Marquer la conversation lue |
| GET | `/api/dishes?vegetarian=true&festive=true&prepTimeMinutes[lte]=30` | Filtres d'Explorer |
| GET | `/api/dishes?author[]=/api/users/3&createdAt[after]=2026-10-20T00:00:00&price[gte]=2500&price[lte]=4999&order[likeCount]=desc&order[reviewCount]=desc&itemsPerPage=10` | Top 10 du jour : cœurs puis avis, dans le réseau (`author[]`), par budget |
| GET | `/api/dishes?isDailySpecial=true` | Mets du jour |
| POST | `/api/reviews` | Noter un plat (1–5) |

## Visibilité des mets

`visibility` : `public` (tout le monde), `followers` (l'auteure et ses abonnées) ou `private` (l'auteure seule).
Un met non visible répond **404** partout (liste, détail, recherche, cœurs, favoris, avis, collections) et n'est
**jamais publié** sur Mercure ni notifié aux abonnées si `private`. Règle centralisée dans `App\Service\DishAccess`.

## Modération

Blocage (`POST/DELETE /api/users/{id}/block`, `GET /api/me/blocks`) : plus de messages ni de suivi dans les deux sens, plus de
notifications venant de la personne bloquée. Signalement (`POST /api/reports`, types `user|dish|review|message`) ; 3 personnes
différentes en 24 h suspendent la messagerie de la personne signalée 24 h. Traitement : `php bin/console app:reports` (`--dismiss|--suspend|--hide|--ban`),
`app:user:ban`, ou l'API `/api/admin/*` (`ROLE_ADMIN`, donné par `app:user:role`) ; toute action est journalisée (`GET /api/admin/actions`).
Un compte banni ne peut plus se connecter et ses contenus disparaissent. `DELETE /api/me` supprime le compte (mot de passe requis).

## Messages

Un message n'est possible que si l'une des deux mamans suit l'autre, ou si l'autre a déjà écrit.
30 messages par minute et par utilisatrice, 2 000 caractères maximum. Pas de blocage ni de signalement pour l'instant.

## Ingrédients

`ingredients` est une liste d'objets `{name, quantity, price, aisle}` (`aisle` : `viandes`, `legumes`, `epicerie`, `autre`). Les simples chaînes sont acceptées et normalisées.
`price` est le prix en Ariary pour la quantité indiquée ; il alimente la liste de courses « Mon marché ».

## Notifications du met du jour

Un plat créé avec `isDailySpecial=true` déclenche, après commit, un message Messenger (worker) qui
publie une mise à jour Mercure **privée** sur `/users/{id}/notifications` pour chaque abonné,
par lots de 200. L'app mobile récupère `GET /api/me/mercure-token` puis s'abonne en SSE.

## Upload d'images

- `POST /api/dishes/{id}/photo` (auteur du plat) et `POST /api/me/avatar` : `multipart/form-data`, champ `file`.
- JPEG / PNG / WebP, 5 Mo max, 25 Mpx max ; ré-encodé en WebP (1600 px max, avatar 512 px), métadonnées EXIF supprimées.
- Stockage Flysystem (`config/packages/flysystem.yaml`) : disque local servi par nginx sur `/uploads/`.
  Pour passer sur S3, changer l'adapter et `UPLOADS_BASE_URL`.
- 30 envois / heure / utilisateur.
