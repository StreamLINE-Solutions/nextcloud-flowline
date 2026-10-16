# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Liaison du compte FlowLINE : jeton API personnel chiffré (`ICrypto`) dans la table `flowline_accounts`, cache `/me`, curseur d'événements
- Client de l'API FlowLINE v1, côté serveur uniquement (le jeton ne quitte jamais le serveur)
- Réglages personnels (lier/délier le compte) et admin (URL de l'API, restriction par groupes, activation)
- Endpoint OCS `GET /ocs/v2.php/apps/flowline/api/v1/account`
- Machines du compte lié dans la console (badge en ligne/hors ligne, OS, dernière activité, source support/parc partagé)
- Sessions du compte lié dans la console (actives et récentes, durée) avec bouton d'actualisation
- Endpoints OCS `GET …/api/v1/machines` et `…/api/v1/sessions` (proxys serveur paginés, filtre `active`)
- Réponses d'erreur OCS stables (`error` : `invalid_token`, `not_linked`, `rate_limited`, …) pour des messages adaptés côté console
- Section « FlowLINE » dans les réglages + console (état lié/non-lié)
- Tests unitaires (AccountService, FlowlineClient)

- Initial project skeleton based on the official Nextcloud app template
- CI workflows: lint (PHP, info.xml, ESLint, Stylelint), Psalm matrix, Node build
- AGPL-3.0-or-later license, security policy