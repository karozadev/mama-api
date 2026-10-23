# Déploiement en production

Guide pour mettre Mama en ligne sur **un seul serveur** (Docker Compose). La pile : nginx (HTTPS) → PHP-FPM,
PostgreSQL, Redis, Mercure (temps réel), un worker de files de messages. Tout est dans `compose.prod.yaml`.

> Ce que ce guide ne couvre pas : l'hébergement lui-même (choix du fournisseur), les mentions légales,
> la politique de confidentialité et les conditions d'utilisation (obligatoires pour les stores, à faire rédiger).

## 1. Prérequis

- Un serveur Linux avec **Docker** et le plugin **Compose v2**. Pour démarrer : 2 vCPU, 2 Go de RAM, 20 Go de disque
  (la pile consomme environ 150 Mo au repos ; la mémoire PHP est réglée dans `docker/php/fpm-prod.conf`).
- Un **nom de domaine** dont l'enregistrement DNS (A/AAAA) pointe vers le serveur.
- Un **reverse proxy** sur le serveur (nginx, Traefik, Caddy…) qui gère le HTTPS du domaine et redirige vers la pile (voir §2). La pile elle-même n'écoute qu'en HTTP sur `127.0.0.1:8090`. PostgreSQL, Redis et Mercure ne sont **pas** exposés.

## 2. Déploiement automatique (GitHub Actions)

Le dépôt n'est **pas cloné** sur le serveur. À chaque push sur `main` dont la CI est verte, le workflow
`.github/workflows/deploy.yml` :

1. construit les images `ghcr.io/karozadev/mama-api` (PHP-FPM) et `ghcr.io/karozadev/mama-api-nginx`, étiquetées avec le SHA du commit et `latest` ;
2. envoie sur le serveur (dans `~/mama`) uniquement `compose.prod.yaml`, le `Makefile` et `scripts/` ;
3. se connecte en SSH, tire les images, applique les migrations, redémarre la pile (`scripts/deploy.sh`) et revient au tag précédent si elle ne devient pas saine ;
4. teste `https://mama.karoza.dev/api/stats`.

### Secrets à créer dans GitHub (Settings → Secrets and variables → Actions, secrets du dépôt)

| Secret | Contenu |
|---|---|
| `VPS_HOST` | adresse IP ou nom du serveur |
| `VPS_USER` | utilisateur SSH (membre du groupe `docker`) |
| `VPS_SSH_KEY` | clé privée SSH **dédiée au déploiement** (jamais votre clé personnelle), encodée en base64 (`base64 -w0 deploy_mama`) ; une clé en clair est aussi acceptée |
| `VPS_KNOWN_HOSTS` | optionnel : sortie de `ssh-keyscan -t ed25519 <serveur>`. Sans lui, la clé du serveur est récupérée à chaque déploiement (pratique, mais une attaque de type « homme du milieu » ne serait pas détectée) |
| `VPS_PORT` | optionnel, 22 par défaut |

Les images GHCR restent privées : le serveur s'y connecte pendant le déploiement avec le jeton temporaire du workflow, rien n'est stocké.

### Première installation du serveur

Rien à faire à la main : si `~/mama/.env.prod` n'existe pas, `scripts/deploy.sh` le crée (`make secrets`, secrets aléatoires générés
sur le serveur, `DOMAIN` et `CORS_ALLOW_ORIGIN` renseignés). Prérequis : Docker + Compose v2 sur le serveur, l'utilisateur dans le groupe `docker`,
et le port local `HTTP_PORT` (8090 par défaut, modifiable dans `.env.prod`) libre.

### Reverse proxy et HTTPS

La pile sert du HTTP sur `127.0.0.1:8090` et fait confiance aux en-têtes `X-Forwarded-*` du proxy (le port n'est pas joignable de l'extérieur).
Le proxy du serveur doit donc gérer le certificat de `mama.karoza.dev` et transmettre, **sans mettre en tampon Mercure** :

```nginx
server {
    listen 443 ssl;
    server_name mama.karoza.dev;
    # ssl_certificate / ssl_certificate_key …
    client_max_body_size 8m;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
    location /.well-known/mercure {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_buffering off;
        proxy_read_timeout 1h;
    }
}
```

Si le proxy tourne dans un conteneur, mettez `HTTP_BIND=0.0.0.0` dans `.env.prod` **et** protégez le port par le pare-feu, ou placez le proxy sur le même réseau Docker.
Pour que la pile gère elle-même son TLS (ports 80/443 libres), la configuration d'origine est conservée dans `docker/nginx/prod-tls.conf.template`
(à remettre sous le nom `prod.conf.template`, avec `make cert` et les ports 80/443 dans `compose.prod.yaml`).
Vérifiez ensuite : `curl https://VOTRE_DOMAINE/api/stats` doit répondre en JSON.

**Gardez une copie de `.env.prod` dans un gestionnaire de mots de passe.** Perdre `JWT_PASSPHRASE` ou le volume `jwt`
déconnecte tous les utilisateurs ; perdre `DB_PASSWORD` verrouille la base.

Sans CI, `make prod-check` puis `make prod-deploy` construisent et lancent les images localement (il faut alors le dépôt sur le serveur).

### Créer le premier compte modérateur

Inscrivez-vous depuis l'application, puis :

```bash
make prod-console CMD="app:user:role vous@example.com --add=ROLE_ADMIN"
```

`ROLE_CHEF` (badge « Cheffe ») s'attribue de la même façon.

## 3. Certificats : renouvellement

Let's Encrypt expire au bout de 90 jours. Le premier certificat et le renouvellement (`scripts/certbot.sh`) n'ont pas pu être
testés sans nom de domaine réel : après le premier déploiement, lancez un essai à blanc
(`docker run --rm -v /etc/letsencrypt:/etc/letsencrypt -v rasoherina-prod_certbot_www:/var/www/certbot certbot/certbot renew --dry-run --webroot -w /var/www/certbot`),
puis ajoutez au `crontab` du serveur :

```cron
17 3 * * * cd /chemin/vers/mama && make cert-renew >> /var/log/mama-cert.log 2>&1
```

## 4. Mises à jour et retour arrière

Les mises à jour partent toutes seules à chaque push sur `main` (voir §2). Les migrations s'exécutent **avant** le redémarrage :
faites une sauvegarde avant toute migration importante (`make backup`).

Retour arrière du code : Actions → Deploy → Run workflow, avec `tag` = le SHA d'une version déjà construite (les images ne sont pas reconstruites).
Si le redémarrage échoue, `scripts/deploy.sh` revient seul au tag précédent. Si la nouvelle version a modifié le schéma de façon
incompatible, restaurez aussi la base (voir §5) : les migrations ne sont jamais annulées automatiquement.

## 5. Sauvegardes

```bash
make backup                                   # base (pg_dump) + photos, dans ./backups, rotation 14 jours
make verify-backup FILE=backups/db-….dump     # restaure dans une base temporaire et compte les lignes
make restore FILE=backups/db-….dump           # DESTRUCTIF : remplace la base (demande confirmation)
make restore FILE=backups/uploads-….tar.gz    # remplace les photos
```

Programmez une sauvegarde quotidienne et **copiez-la hors du serveur** (une sauvegarde sur le même disque n'en est pas une) :

```cron
30 2 * * * cd /chemin/vers/mama && make backup >> /var/log/mama-backup.log 2>&1 && rclone copy ./backups remote:mama-backups --max-age 2d
```

Testez une restauration **au moins une fois par mois** avec `make verify-backup`. Les sauvegardes contiennent des données
personnelles : chiffrez la destination distante.

## 6. Surveillance

- `make prod-ps` : état et santé des services ; `make prod-logs` : logs (JSON, rotation 10 Mo × 5 par service).
- Sonde externe (UptimeRobot, Better Stack, …) sur `https://VOTRE_DOMAINE/healthz` (nginx) et `/api/stats` (application + base).
- Les erreurs applicatives sont écrites en JSON sur stderr (`docker compose logs app | grep '"level":"ERROR"'`).
  Pour être alertée par e-mail ou Slack, ajoutez Sentry (`composer require sentry/sentry-symfony`).
- Disque : surveillez l'espace (volumes `db_data`, `uploads`). `df -h` et `docker system df`.

## 7. Modération

Les mamans peuvent **bloquer** et **signaler** (mamans, mets, avis, messages). Règles automatiques :
**3 signalements de personnes différentes en 24 h suspendent la messagerie de la personne signalée pendant 24 h.**

```bash
make prod-console CMD="app:reports"                                        # signalements ouverts
make prod-console CMD="app:reports --resolve=12 --dismiss"                 # classer sans suite
make prod-console CMD="app:reports --resolve=12 --suspend --note='Récidive'"  # messagerie suspendue 7 jours
make prod-console CMD="app:reports --resolve=12 --hide"                    # masquer le met / supprimer l'avis signalé
make prod-console CMD="app:reports --resolve=12 --ban --note='Arnaque'"    # bannir la personne signalée
make prod-console CMD="app:user:ban mama@example.com --reason='Arnaque'"   # bannir directement (--lift pour lever)
```

**Bannir** : le compte ne peut plus se connecter, ses jetons existants sont refusés, son profil, ses mets et ses avis
disparaissent partout (liste, recherche, suivis, messages). Lever le bannissement rétablit tout. Un administrateur ne peut pas être banni.
**Masquer un met** : il disparaît pour tout le monde, y compris son auteure, et n'est plus publié en temps réel.

Les mêmes actions existent en API pour un futur back-office (`ROLE_ADMIN`) :
`GET /api/admin/reports`, `POST /api/admin/reports/{id}/resolve` (`dismiss|suspend|hide|ban`),
`POST|DELETE /api/admin/users/{id}/ban`, `POST|DELETE /api/admin/dishes/{id}/hide`, `DELETE /api/admin/reviews/{id}`.
**Chaque action de modération est enregistrée** (qui, quoi, pourquoi) : `GET /api/admin/actions`.
Les messages signalés sont conservés dans le signalement (contexte de 6 messages), même si la conversation est supprimée ensuite.

Ce qui n'existe pas encore : filtre automatique de mots ou d'images, procédure d'appel pour une personne bannie, information
automatique de la personne bannie par e-mail (elle voit seulement le message au moment de se connecter).

## 8. Montée en charge

- Plus de PHP : `docker compose -f compose.prod.yaml --env-file .env.prod up -d --scale app=3` (nginx répartit via le DNS Docker).
- Plus de worker : `--scale worker=2`. Les notifications d'un met du jour partent par lots de 200 abonnées.
- Au-delà d'un serveur : base PostgreSQL managée, photos sur un stockage objet S3 (changer l'adapter dans
  `config/packages/flysystem.yaml` et `UPLOADS_BASE_URL`), Redis managé, plusieurs serveurs d'application derrière un
  répartiteur de charge (renseigner alors `TRUSTED_PROXIES` pour que la limitation de débit voie la vraie IP).

## 9. Sécurité : liste de contrôle

- [ ] `.env.prod` en mode 600, hors du dépôt, sauvegardé à part.
- [ ] Pare-feu : seuls 22, 80, 443 ouverts ; connexion SSH par clé, pas de mot de passe.
- [ ] Mises à jour de sécurité automatiques du système (`unattended-upgrades`).
- [ ] Sauvegardes quotidiennes, copiées hors serveur, restauration testée.
- [ ] Renouvellement des certificats en `cron`.
- [ ] Mercure : les mises à jour **publiques** (mets publics) sont lisibles sans compte, les canaux **privés**
      (`/users/{id}/notifications`) exigent un jeton émis par l'API. Un met privé n'est jamais publié.
- [ ] Les docs interactives de l'API (`/api/docs`) sont désactivées en production.
- [ ] Rotation des secrets en cas de fuite : changer la valeur dans `.env.prod` puis `make prod-deploy`
      (changer `JWT_PASSPHRASE` impose de supprimer le volume `jwt` : tout le monde est déconnecté).

## 10. Application mobile : publier

L'URL de l'API est fixée à la compilation :

```bash
cd mobile
flutter build appbundle --release --dart-define=API_URL=https://VOTRE_DOMAINE      # Google Play (par défaut en release : https://mama.karoza.dev)
flutter build ipa --release --dart-define=API_URL=https://VOTRE_DOMAINE            # App Store (sur un Mac)
```

**Android – signature.** Créez une fois la clé d'envoi (à sauvegarder précieusement, hors du dépôt) :

```bash
keytool -genkey -v -keystore mobile/android/upload-keystore.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload
cp mobile/android/key.properties.example mobile/android/key.properties   # puis renseignez les mots de passe
```

Sans `key.properties`, le build de release est signé avec la clé de debug : **ne le publiez jamais**.

**À préparer pour les stores** (non fourni ici) : identifiant d'application définitif (aujourd'hui
`mg.rasoherina.rasoherina`), icône et écran de lancement, captures d'écran, URL de la politique de confidentialité,
questionnaire « sécurité des données » (Google) / « confidentialité » (Apple). La **suppression de compte** dans l'application
(`Profil → Supprimer mon compte`) et le **signalement de contenus** sont déjà en place : les deux stores les exigent.
Les notifications quand l'application est fermée ne sont pas encore disponibles (Mercure ne couvre que l'app ouverte).
