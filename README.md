# FlowLINE for Nextcloud

Bring your remote support workspace into Nextcloud: link your FlowLINE account,
see your machines and sessions, invite a computer and receive notifications —
without leaving your Nextcloud.

> Status: **early development** — the skeleton is in place, the app is not usable yet.

## Requirements

- Nextcloud 33 – 36
- PHP 8.2+
- A FlowLINE account (https://flowline.support)

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

### Testing against a Nextcloud instance

Install the repository into a Nextcloud dev instance as `apps/flowline`
(e.g. with [nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev)),
then enable the app. The app targets Nextcloud 33 to 36 (PHP 8.2+).

## Releases

Releases are signed and published to the
[Nextcloud app store](https://apps.nextcloud.com). Built frontend assets
(`js/`, `css/`) are generated at package time and are not committed.

## Links

- Website: https://flowline.support
- Issues: https://github.com/StreamLINE-Solutions/nextcloud-flowline/issues

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).