#!/usr/bin/env bash
#
# Construit le client web FlowLINE (projet voisin `web-client/`) et copie le
# bundle dans `webclient/` — le bundle est ensuite committé : il est embarqué
# tel quel dans le tarball de release (le dépôt web-client n'est pas cloné par
# la CI de cette app).
#
# Usage : scripts/build-webclient.sh
#   WEB_CLIENT_DIR=/chemin/vers/web-client scripts/build-webclient.sh
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_CLIENT_DIR="${WEB_CLIENT_DIR:-$APP_DIR/../web-client}"

if [[ ! -f "$WEB_CLIENT_DIR/package.json" ]]; then
	echo "web-client introuvable : $WEB_CLIENT_DIR" >&2
	echo "Définir WEB_CLIENT_DIR=/chemin/vers/web-client" >&2
	exit 1
fi

(
	cd "$WEB_CLIENT_DIR"
	# Base relative : la page (servie par l'app Nextcloud) réécrit les URLs
	# d'assets vers sa propre route (indépendant du chemin d'installation).
	npm run build -- --base=./
)

rm -rf "$APP_DIR/webclient"
cp -r "$WEB_CLIENT_DIR/dist" "$APP_DIR/webclient"

echo "OK — bundle webclient/ : $(du -sh "$APP_DIR/webclient" | cut -f1)"
echo "Penser à : bump appinfo/info.xml (cache-buster) + commit du bundle."