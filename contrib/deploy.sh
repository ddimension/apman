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

# The subscriber is stopped for the duration of the sync, and whatever happens
# after that it has to be started again. Without this, a step in between that
# fails takes the script out under `set -e` with the subscriber still stopped —
# and a stopped subscriber is a controller that hears nothing from any access
# point, answers every ubus call with a timeout, and says nothing about why.
# That happened on 23.08.2026, when `rm -rf var/cache/prod` failed on a
# directory that was not empty: the fleet looked dead for two minutes and the
# cause was three lines further down.
restart_service() {
	status=$?
	ssh "$HOST" "systemctl start $SERVICE" || true
	if [ "$status" -ne 0 ]; then
		printf '\n!! deploy failed (exit %s) — %s was started again anyway\n' "$status" "$SERVICE" >&2
	fi
}

say "stopping $SERVICE"
ssh "$HOST" "systemctl stop $SERVICE"
trap restart_service EXIT

say "syncing"
rsync -a --delete "$HERE/vendor/"    "$HOST:$DIR/vendor/"
rsync -a --delete "$HERE/src/"       "$HOST:$DIR/src/"
rsync -a           "$HERE/config/"   "$HOST:$DIR/config/"
rsync -a           "$HERE/templates/" "$HOST:$DIR/templates/"
rsync -a           "$HERE/bin/"      "$HOST:$DIR/bin/"
rsync -a           "$HERE/public/assets/" "$HOST:$DIR/public/assets/"
rsync -a           "$HERE/composer.json" "$HERE/composer.lock" "$HERE/symfony.lock" "$HOST:$DIR/"

# The cache is moved aside and then deleted rather than deleted where it
# stands. Apache keeps serving through the deploy and writes cache files while
# the delete walks the tree, so `rm -rf` can arrive at a directory that was
# empty when it looked and is not any more — which fails, and under `set -e`
# takes the whole deploy with it. A rename cannot race that way.
say "ownership, cache, assets"
ssh "$HOST" "chown -R www-data:www-data $DIR \
  && cd $DIR \
  && rm -rf var/cache/prod.old \
  && { mv var/cache/prod var/cache/prod.old 2>/dev/null || true; } \
  && rm -rf var/cache/prod.old \
  && sudo -u www-data $PHP bin/console cache:clear --env=prod \
  && sudo -u www-data $PHP bin/console assets:install public --env=prod"

# The code is on the machine and the subscriber is still down, which is the one
# moment where a missing column costs nothing. Deploying an entity with a column
# the database does not have makes every message from every access point throw a
# PDOException, close the entity manager and be lost — twice today, until the
# ALTER caught up. Better to be told now, while the only thing that has happened
# is that some files were copied.
say "schema"
PENDING="$(ssh "$HOST" "cd $DIR && sudo -u www-data $PHP bin/console doctrine:schema:update --dump-sql 2>/dev/null | grep -E '^(ALTER|CREATE|DROP)' || true")"
if [ -n "$PENDING" ]; then
	printf '\n!! the database is behind the code. Apply this first:\n\n%s\n\n' "$PENDING" >&2
	printf '   ssh %s "cd %s && sudo -u www-data %s bin/console dbal:run-sql \x27<statement>\x27"\n\n' \
		"$HOST" "$DIR" "$PHP" >&2
else
	echo "  up to date"
fi

say "reloading the web server and starting $SERVICE"
ssh "$HOST" "systemctl reload apache2 && systemctl start $SERVICE"
trap - EXIT
sleep 6

# The RADIUS server this used to count a socket for is gone; the access points
# answer their own hostapd now. The check is inverted rather than deleted: a
# listener on 1812 after a deploy means an old daemon outlived the restart and
# is still holding the port — which used to be invisible and is worth a line.
say "checking"
ssh "$HOST" "systemctl is-active apache2 $SERVICE | tr '\n' ' '; echo; \
  echo -n 'udp/1812: '; \
  if ss -uln 2>/dev/null | grep -q ':1812'; then \
    echo 'STILL LISTENING - an old process survived the restart'; \
  else echo 'silent, as it should be'; fi"

for path in / /aps /ssids /radius /admin/dashboard; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 \
        "http://${HOST#*@}.kalnet.hooya.de/apman$path" || echo '---')
    printf '  %-18s %s\n' "$path" "$code"
done
