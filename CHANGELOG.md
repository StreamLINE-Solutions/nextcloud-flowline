# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!-- ⚠ App Store Nextcloud : les sections de version doivent s'écrire « ## X.Y.Z »
     (sans crochets) — la plateforme parse les lignes `^## (\d+\.\d+\.\d+)`. -->

## [Unreleased]

## 0.6.4 — 2026-10-20

### Changed

- Réglages admin : champs d'URL plus larges et non préremplis avec les valeurs par défaut.
  Les URLs par défaut ne sont plus affichées dans le formulaire ; laisser vide utilise le
  service FlowLINE par défaut. La page personnelle continue d'afficher l'URL effective.

## 0.6.3 — 2026-10-20

### Security

- Réglage de l'URL de l'API : refus des cibles loopback, link-local et réservées
  (métadonnées cloud `169.254.169.254`, `localhost`, CGNAT) ; les plages privées LAN
  restent autorisées pour une API auto-hébergée. Les redirections HTTP sont désactivées
  côté client d'API (contournement par `30x`).

### Changed

- `axios` 1.20.0 et `follow-redirects` 1.16.1 : plus aucun avis de sécurité en
  production (`npm audit --omit=dev` = 0).

## 0.6.2 — 2026-10-17

### Changed

- Fenêtre de support (client web) : ouverture en **popup dimensionnée** au lieu d'un onglet — auto-ajustée à la taille de l'écran distant dès la connexion (bornée à l'écran local), rendu 1:1 quand c'est possible ; interface épurée (barre d'état, bouton plein écran — plus de formulaire de développement) ; connexion automatique à l'ouverture et fenêtre réutilisée par poste (re-clic = focus)

## 0.6.1 — 2026-10-17

### Changed

- Fiche App Store : ajout de six captures d'écran dans `appinfo/info.xml` (le store n'a pas d'icône dédiée — la carte de l'app affiche sa première capture ; sans capture, un picto générique est affiché) et dossier `screenshots/` ajouté au paquet

## 0.6.0 — 2026-10-17

### Added

- Notifications Nextcloud : une session de support démarrée ou terminée sur une machine du compte lié produit une notification (sonde au tick du cron Nextcloud, ≤ 5 minutes ; l'historique antérieur à la liaison n'est pas notifié)
- Le compte lié (jeton chiffré et curseur d'événements) est supprimé quand l'utilisateur Nextcloud est supprimé
- Bouton « Ouvrir la page du module » dans la section Support (page de téléchargement du module support, fournie par le compte FlowLINE)
- Traductions françaises de l'interface (console, réglages personnels et admin, notifications) — fichiers `l10n/fr.json` et `l10n/fr.js`

## 0.5.0 — 2026-10-17

### Added

- Liaison du compte FlowLINE : jeton API personnel chiffré (`ICrypto`) dans la table `flowline_accounts`, cache `/me`, curseur d'événements
- Client de l'API FlowLINE v1, côté serveur uniquement (le jeton ne quitte jamais le serveur)
- Réglages personnels (lier/délier le compte) et admin (URL de l'API, restriction par groupes, activation)
- Endpoint OCS `GET /ocs/v2.php/apps/flowline/api/v1/account`
- Machines du compte lié dans la console (badge en ligne/hors ligne, OS, dernière activité, source support/parc partagé)
- Sessions du compte lié dans la console (actives et récentes, durée) avec bouton d'actualisation
- Endpoints OCS `GET …/api/v1/machines` et `…/api/v1/sessions` (proxys serveur paginés, filtre `active`)
- Fenêtres d'autorisation support dans la console (ouvrir avec l'id du module, lister, terminer) et copie du lien du module support
- Endpoints OCS `GET`/`POST …/api/v1/invitations` et `DELETE …/api/v1/invitations/{deviceId}`
- Réponses d'erreur OCS stables (`error` : `invalid_token`, `not_linked`, `rate_limited`, …) pour des messages adaptés côté console
- Section « FlowLINE » dans les réglages + console (état lié/non-lié)
- Console en onglets horizontaux : **Support** (machines + fenêtres support), **Connexions récentes** (sessions), **Compte lié**
- Assistance navigateur : page `/apps/flowline/webclient` servie par l'app (client web FlowLINE, endpoints WS injectés, CSP restreinte) et bouton **Browser support** (machines + fenêtres support) — aucun jeton transmis au navigateur
- Réglages admin : endpoints WebSocket du client web (rendez-vous, relais) en plus de l'URL de l'API
- Tests unitaires (AccountService, FlowlineClient)

### Fixed

- Console : rechargement des machines et sessions à l'ouverture/fin d'une fenêtre support + auto-rafraîchissement (30 s) tant qu'une fenêtre est active (le poste apparaît dès que son module se connecte)
- Fenêtres support : état explicite (module en ligne/hors ligne, session active ou non) au lieu de « Module not connected yet », et aide « démarrer la session depuis votre client FlowLINE »

## 0.1.0 — squelette initial

- Initial project skeleton based on the official Nextcloud app template
- CI workflows: lint (PHP, info.xml, ESLint, Stylelint), Psalm matrix, Node build
- AGPL-3.0-or-later license, security policy