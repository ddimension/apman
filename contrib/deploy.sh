#!/bin/bash
#
# Put this working copy onto the controller.
#
# The order is the point. Every step here exists because leaving it out broke
# something:
#
#   - The daemon is stopped first. It is a long running PHP process holding the
#     vendor tree it started with; changing that tree underneath it produces a
#     run of "class not found" errors and, if the container cache is cleared
#     too, kills it outright. Twice in one day is enough.
#   - assets:install runs after the vendor sync. Bundle assets are copied into
#     public/bundles, which is not in the repository and is not part of the
#     rsync, so a vendor upgrade leaves the old copies behind. That is how the
#     admin pages ended up serving Sonata 3 assets to Sonata 4 templates and
#     answering 404 for their stylesheet and their javascript.
#   - The cache is cleared as www-data. Files here must belong to www-data, and
#     a cache directory owned by anyone else is a 500 on the next request.
#
# Usage: contrib/deploy.sh [host] [path]
set -eu

HOST="${1:-root@app1}"
DIR="${2:-/usr/local/share/apman}"
PHP="${PHP:-php8.4}"
SERVICE="${SERVICE:-apman-subscriber}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"

say() { printf '\n== %s\n' "$1"; }

say "stopping $SERVICE"
ssh "$HOST" "systemctl stop $SERVICE"

say "syncing"
rsync -a --delete "$HERE/vendor/"    "$HOST:$DIR/vendor/"
rsync -a --delete "$HERE/src/"       "$HOST:$DIR/src/"
rsync -a           "$HERE/config/"   "$HOST:$DIR/config/"
rsync -a           "$HERE/templates/" "$HOST:$DIR/templates/"
rsync -a           "$HERE/bin/"      "$HOST:$DIR/bin/"
rsync -a           "$HERE/public/assets/" "$HOST:$DIR/public/assets/"
rsync -a           "$HERE/composer.json" "$HERE/composer.lock" "$HERE/symfony.lock" "$HOST:$DIR/"

say "ownership, cache, assets"
ssh "$HOST" "chown -R www-data:www-data $DIR \
  && cd $DIR \
  && rm -rf var/cache/prod \
  && sudo -u www-data $PHP bin/console cache:clear --env=prod \
  && sudo -u www-data $PHP bin/console assets:install public --env=prod"

say "reloading the web server and starting $SERVICE"
ssh "$HOST" "systemctl reload apache2 && systemctl start $SERVICE"
sleep 6

say "checking"
ssh "$HOST" "systemctl is-active apache2 $SERVICE | tr '\n' ' '; echo; \
  echo -n 'radius socket: '; ss -ulnp | grep -c ':1812' || true"

for path in / /aps /ssids /radius /admin/dashboard; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 \
        "http://${HOST#*@}.kalnet.hooya.de/apman$path" || echo '---')
    printf '  %-18s %s\n' "$path" "$code"
done
