# FlowLINE for Nextcloud

Bring your remote support workspace into Nextcloud: link your FlowLINE account,
see your machines and sessions, invite a computer and receive notifications —
without leaving your Nextcloud.

> Status: **published on the [Nextcloud app store](https://apps.nextcloud.com/apps/flowline)**
> (0.5.0) — this repository tracks 0.6.0, which adds Nextcloud notifications.

## Features

- **Account linking** — paste a personal FlowLINE API token (`flapi_…`) in your
  personal settings; it is stored encrypted (instance key) on the server and
  never sent back to the browser.
- **Machines** — your fleet and support machines (online/offline, OS, last
  activity, shared fleet) in the FlowLINE console.
- **Sessions** — active and recent support sessions, with duration.
- **Support windows** — invite a computer by its module id, copy the module
  link, open/close windows, and see the module status (online/offline).
- **Browser support** — start the support session directly in the browser (no
  software to install on the technician side), with a strict CSP; the API
  token never reaches the browser.
- **Notifications** — a Nextcloud notification when a support session starts or
  ends on one of your machines (polled by a background job, ≤ 5 minutes).
- **Translations** — English (source) and French (`l10n/`).

## Requirements

- Nextcloud 33 – 36
- PHP 8.2+
- A FlowLINE account (https://flowline.support)
- **Background jobs** (cron) enabled for the notifications to be polled —
  see the [Nextcloud documentation](https://docs.nextcloud.com/server/stable/admin_manual/configuration_server/background_jobs_configuration.html)

## Development

This app is built on the official
[Nextcloud app template](https://github.com/nextcloud/app-template).

### PHP

```sh
composer install          # dev dependencies (psalm, phpunit, php-cs-fixer, …)
composer run lint         # php -l on all files
composer run cs:check     # coding style (php-cs-fixer)
composer run psalm        # static analysis
composer run test:unit    # unit tests
```

### Frontend

```sh
npm ci
npm run build             # production build (js/ + css/)
npm run watch             # development build, watches for changes
```

### Browser support (web client)

The FlowLINE web client (support session in the browser — nothing to install
on the technician side) is served by the app at `/apps/flowline/webclient`,
with a **Browser support** button in the console (machines and support
windows). The bundle lives in `webclient/` and is **committed**: it is built
from the sibling `web-client` project, which this repository's CI does not
have access to.

```sh
# from a workspace with both checkouts (web-client/ + nextcloud-flowline/):
bash scripts/build-webclient.sh
```

The page is served with a strict CSP: Nextcloud script nonce, no
`unsafe-eval` (`'wasm-unsafe-eval'` only, for libsodium), `connect-src`
limited to `self` + the configured WebSocket endpoints. The `flapi_` API
token never reaches the browser — the support window is opened server-side.
The WebSocket endpoints are configurable in the admin settings (defaults:
`wss://falcon.my-vth.ch/ws/id` and `wss://falcon.my-vth.ch/ws/relay`).

### Testing against a Nextcloud instance

A ready-to-use dev instance is included:

```sh
docker compose -f dev/docker-compose.yml up -d
docker compose -f dev/docker-compose.yml exec -u www-data nextcloud \
  php occ app:enable flowline
```

→ http://localhost:18080 — see [`dev/README.md`](dev/README.md) for details.
The app targets Nextcloud 33 to 36 (PHP 8.2+); for multi-version testing see
[nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev).

### CI

GitHub Actions workflows come from the app template, with one local
adaptation: the template uses the runner label `ubuntu-latest-low`, which only
exists on Nextcloud's internal runners. It is replaced here by the standard
`ubuntu-latest` label — otherwise those jobs stay queued forever outside the
Nextcloud organisation (observed 2026-10-15: 56 runs stuck).

## Releases

Releases are signed and published to the
[Nextcloud app store](https://apps.nextcloud.com). Built frontend assets
(`js/`, `css/`) are generated at package time and are not committed; the web
client bundle (`webclient/`) is committed instead (see above) and included in
the release tarball.

Publishing is prepared by the release workflow: publishing a GitHub release
builds the app, packages `flowline.tar.gz` and attaches it to the release. The
store release is then submitted with the tarball URL and its SHA-512 signature
(the workflow pushes it automatically instead when the `release` environment
provides the `APPSTORE_TOKEN` and `APP_PRIVATE_KEY` secrets) — see
[`dev/README.md`](dev/README.md) for the workflow and the required secrets.

## Links

- Website: https://flowline.support
- Issues: https://github.com/StreamLINE-Solutions/nextcloud-flowline/issues

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).