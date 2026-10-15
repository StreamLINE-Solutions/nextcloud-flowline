# Développement de l'app FlowLINE (Nextcloud)

## Instance de dev

    docker compose -f dev/docker-compose.yml up -d
    docker compose -f dev/docker-compose.yml exec -u www-data nextcloud \
      php occ app:enable flowline

→ http://localhost:18080 (admin / flowline-dev)

L'app est montée en lecture seule depuis le dépôt : les modifications du code
sont visibles immédiatement (rebuild du front : `npm ci && npm run build`).

> Si l'app répond 404 juste après l'activation : redémarrer le service
> (`docker compose -f dev/docker-compose.yml restart nextcloud`) — l'instance
> web garde en cache la liste des apps chargée avant l'activation.

## Release (App Store Nextcloud)

Le workflow `.github/workflows/release.yml` se déclenche à la **publication
d'une release GitHub** : il construit le front, crée le tarball, l'attache à la
release, puis le pousse sur l'App Store (signature incluse via la clé privée).

Secrets à configurer (Settings → Environments → `release`) :

| Secret | Contenu |
| --- | --- |
| `APP_PRIVATE_KEY` | clé privée de signature de l'app (`flowline.key`, hors dépôt) |
| `APPSTORE_TOKEN` | jeton API du compte développeur (apps.nextcloud.com → *My account* → *API-Token*) |

La signature des releases est vérifiée contre le certificat enregistré sur
l'App Store.