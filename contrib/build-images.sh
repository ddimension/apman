#!/bin/bash
# Sysupgrade-Images fuer die Flotte aus einem Inventory bauen — mit Docker und
# dem offiziellen OpenWrt-ImageBuilder, ohne Toolchain-Build.
#
# Das Inventory kommt aus dem Symfony-Job `apman:image-inventory`, der die
# Geraete ueber MQTT einsammelt (Board, Target, Profil, laufender Stand). Fehlt
# die Datei, wird der Job automatisch aufgerufen.
#
# Gebaut wird PLAIN OpenWrt von downloads.openwrt.org, nicht VPN-to-go. Das
# Inventory sagt nur, WELCHE Geraete es gibt und welches Profil sie brauchen —
# der Release kommt aus --release.
#
#   contrib/build-images.sh -o ~/images
#   contrib/build-images.sh -o ~/images -r 24.10.8 -d ap-av-attic -d ap-outdoor
#   contrib/build-images.sh -o ~/images --type all --with-apman-config
#   contrib/build-images.sh -o ~/images --feed https://ddimension.github.io/openwrt-repo/... \
#                                       --key contrib/ddimension.pem -p apman
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"

usage() {
	cat <<'EOF'
Usage: build-images.sh -o OUTDIR [optionen]

Pflicht:
  -o, --out DIR           Zielverzeichnis fuer die Images

Optionen:
  -i, --inventory FILE    Inventory-JSON        [default: contrib/inventory.json]
      --refresh           Inventory neu einsammeln, auch wenn es existiert
      --inventory-cmd CMD Befehl, der das Inventory nach STDOUT schreibt
                          [default: php <root>/bin/console apman:image-inventory --with-config]
                          Laeuft der Controller woanders, hier den ssh-Aufruf
                          angeben, z.B.
                            --inventory-cmd "ssh root@app1 'cd /usr/local/share/apman &&
                              sudo -u www-data php8.2 bin/console --env prod
                              apman:image-inventory --with-config'"
  -r, --release REL       OpenWrt-Release, z.B. 24.10.8 / 25.12.5 / SNAPSHOT
                          [default: auto = neuestes stable]
  -d, --device NAME       nur dieses Geraet, mehrfach angebbar
  -t, --type TYPE         sysupgrade|factory|all   [default: sysupgrade]
  -p, --packages "P1 P2"  zusaetzliche Pakete, mehrfach angebbar
      --no-default-pkgs   Standard-Paketset weglassen (nur -p zaehlt)
      --profile P         Profil aus dem Inventory ueberschreiben (nur mit
                          genau einem -d sinnvoll)
      --use-inventory-pkgs  Paketliste aus dem Inventory mitbauen. ACHTUNG: die
                          kommt von VPN-to-go-Geraeten und enthaelt Pakete, die
                          es in plain OpenWrt nicht gibt — der Build bricht dann
                          ab. Nur mit --release passend zum Ursprung sinnvoll.
      --no-apman          den apman-Feed NICHT einbinden und apman nicht
                          installieren (default: ist drin)
      --no-luci           LuCI weglassen (default: ist drin)
      --with-collectd     die ueblichen collectd-Module dazu (cpu, memory,
                          load, interface, iwinfo, uptime, network)
      --apman-pkgs "..."  was aus dem Feed installiert wird [default: apman]
      --feed-root URL     Wurzel des ddimension-Feeds
                          [default: https://ddimension.github.io/openwrt-repo]
      --with-apman-config /etc/config/apman aus dem Inventory ins Image legen
                          (braucht ein Inventory mit --with-config)
      --files DIR         zusaetzliches Overlay fuer ALLE Images (files/-Baum)
      --feed URL          WEITERER Paket-Feed (apk: URL auf packages.adb,
                          opkg: "name URL"), mehrfach angebbar
      --key FILE          Public-Key fuer die Feeds (apk, nach keys/).
                          Ohne Angabe: keys/ddimension.pem aus dem
                          openwrt-repo daneben, sonst vom Feed geladen.
      --engine E          docker|podman            [default: auto]
      --image-repo R      Registry/Repo des ImageBuilders
                          [default: openwrt/imagebuilder]
      --pull              Container-Image vorher aktualisieren
  -n, --dry-run           nur zeigen, was gebaut wuerde
  -h, --help              diese Hilfe

Standard-Paketset (--no-default-pkgs schaltet es ab):
  wpad-openssl statt der wpad-basic-Variante des Profils (voller 802.11r/k/v-
  und EAP-Umfang, gegen OpenSSL gelinkt), ip-full und ip-bridge statt ip-tiny,
  lldpd, tcpdump und das uebliche Debug-Werkzeug.

apman kommt aus dem signierten ddimension-Feed (siehe README.md des
openwrt-repo). Der Release-Zweig wird aus --release abgeleitet
(25.12.x -> openwrt-25.12, SNAPSHOT -> snapshot), die Architektur liest der
Container aus der .config des ImageBuilders — es gibt also keine
Target-nach-Arch-Tabelle, die veralten koennte.

Ergebnis:
  OUTDIR/<geraet>/  die Images plus die manifest/sha256sums des Builds
EOF
	exit "${1:-0}"
}

# ---- Defaults ---------------------------------------------------------------
OUT=""
INVENTORY="$ROOT/contrib/inventory.json"
INVENTORY_CMD=""
REFRESH=0
RELEASE=auto
DEVICES=()
TYPE=sysupgrade
EXTRA_PKGS=""
NO_DEFAULT_PKGS=0
PROFILE_OVERRIDE=""
USE_INV_PKGS=0
WITH_APMAN_CONFIG=0
FILES_DIR=""
FEEDS=()
KEYFILE=""
WITH_APMAN=1
WITH_LUCI=1
WITH_COLLECTD=0
APMAN_PKGS="apman"
FEED_ROOT="https://ddimension.github.io/openwrt-repo"
ENGINE=""
IMAGE_REPO="openwrt/imagebuilder"
PULL=0
DRYRUN=0

# wpad-basic* fliegt raus, egal welche Variante das Profil mitbringt — sonst
# kollidiert es mit wpad-openssl und der Build bricht ab.
#
# rpcd ist keine Bequemlichkeit, sondern Voraussetzung: der Agent findet seine
# Interfaces ueber "iwinfo devices" und legt die RPC-Session ueber
# "session create" an. Ohne rpcd/rpcd-mod-iwinfo gibt es weder assoclist noch
# info in den Statusmeldungen — apman laeuft auf so einem Image nicht.
#
# hostapd-utils bringt hostapd_cli (WPS-PIN und Fehlersuche am laufenden BSS),
# mosquitto-client und openssl-util haengen an libopenssl, das wegen
# wpad-openssl ohnehin im Image liegt — kosten also fast nichts.
DEFAULT_PKGS="wpad-openssl \
-wpad-basic -wpad-basic-mbedtls -wpad-basic-openssl -wpad-basic-wolfssl \
-wpad-mbedtls -wpad-wolfssl -wpad-mini -wpad \
rpcd rpcd-mod-file rpcd-mod-iwinfo \
hostapd-utils \
ip-full ip-bridge \
lldpd \
tcpdump \
ethtool iw-full iwinfo \
mosquitto-client-ssl openssl-util \
lsof htop strace socat curl ss"

# Wenn der Controller weg ist, ist LuCI der einzige Weg auf die Kiste ausser
# ssh — deshalb standardmaessig drin. --no-luci wirft es raus.
LUCI_PKGS="luci luci-mod-network luci-mod-status luci-mod-system"

# apman bringt collectd und das Lua-Modul als Abhaengigkeit schon mit; das hier
# sind die Kollektoren, die die Flotte heute auch faehrt.
COLLECTD_PKGS="collectd-mod-cpu collectd-mod-memory collectd-mod-load \
collectd-mod-interface collectd-mod-iwinfo collectd-mod-uptime \
collectd-mod-network"

while [ $# -gt 0 ]; do
	case "$1" in
	-o|--out) OUT="$2"; shift 2 ;;
	-i|--inventory) INVENTORY="$2"; shift 2 ;;
	--refresh) REFRESH=1; shift ;;
	--inventory-cmd) INVENTORY_CMD="$2"; shift 2 ;;
	-r|--release) RELEASE="$2"; shift 2 ;;
	-d|--device) DEVICES+=("$2"); shift 2 ;;
	-t|--type) TYPE="$2"; shift 2 ;;
	-p|--packages) EXTRA_PKGS="$EXTRA_PKGS $2"; shift 2 ;;
	--no-default-pkgs) NO_DEFAULT_PKGS=1; shift ;;
	--profile) PROFILE_OVERRIDE="$2"; shift 2 ;;
	--use-inventory-pkgs) USE_INV_PKGS=1; shift ;;
	--with-apman-config) WITH_APMAN_CONFIG=1; shift ;;
	--files) FILES_DIR="$2"; shift 2 ;;
	--no-apman) WITH_APMAN=0; shift ;;
	--no-luci) WITH_LUCI=0; shift ;;
	--with-collectd) WITH_COLLECTD=1; shift ;;
	--apman-pkgs) APMAN_PKGS="$2"; shift 2 ;;
	--feed-root) FEED_ROOT="$2"; shift 2 ;;
	--feed) FEEDS+=("$2"); shift 2 ;;
	--key) KEYFILE="$2"; shift 2 ;;
	--engine) ENGINE="$2"; shift 2 ;;
	--image-repo) IMAGE_REPO="$2"; shift 2 ;;
	--pull) PULL=1; shift ;;
	-n|--dry-run) DRYRUN=1; shift ;;
	-h|--help) usage 0 ;;
	*) echo "unbekannte Option: $1" >&2; usage 1 ;;
	esac
done

[ -n "$OUT" ] || { echo "FEHLER: -o/--out fehlt" >&2; usage 1; }
case "$TYPE" in sysupgrade|factory|all) ;; *) echo "FEHLER: --type $TYPE" >&2; exit 1 ;; esac
command -v python3 >/dev/null || { echo "FEHLER: python3 wird zum Lesen des Inventorys gebraucht" >&2; exit 1; }

if [ -z "$ENGINE" ]; then
	if command -v docker >/dev/null && docker info >/dev/null 2>&1; then ENGINE=docker
	elif command -v podman >/dev/null; then ENGINE=podman
	else echo "FEHLER: weder docker noch podman nutzbar" >&2; exit 1; fi
fi
command -v "$ENGINE" >/dev/null || { echo "FEHLER: $ENGINE nicht gefunden" >&2; exit 1; }

# ---- Inventory --------------------------------------------------------------
if [ ! -s "$INVENTORY" ] || [ "$REFRESH" = 1 ]; then
	[ -n "$INVENTORY_CMD" ] || INVENTORY_CMD="php $ROOT/bin/console apman:image-inventory --with-config"
	echo ">> Inventory fehlt oder --refresh: $INVENTORY_CMD"
	mkdir -p "$(dirname "$INVENTORY")"
	# Der Job schreibt das JSON nach stdout und alles Menschenlesbare nach
	# stderr, deshalb reicht eine Umleitung — und ein "ssh host '...'" als
	# Befehl funktioniert genauso wie ein lokaler Aufruf.
	if ! eval "$INVENTORY_CMD" > "$INVENTORY.tmp"; then
		rm -f "$INVENTORY.tmp"
		echo "FEHLER: Inventory konnte nicht erzeugt werden" >&2; exit 1
	fi
	python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$INVENTORY.tmp" || {
		rm -f "$INVENTORY.tmp"
		echo "FEHLER: der Inventory-Befehl hat kein JSON geliefert" >&2; exit 1; }
	mv "$INVENTORY.tmp" "$INVENTORY"
fi
[ -s "$INVENTORY" ] || { echo "FEHLER: $INVENTORY ist leer" >&2; exit 1; }

# ---- Release aufloesen ------------------------------------------------------
if [ "$RELEASE" = auto ]; then
	RELEASE="$(wget -qO- https://downloads.openwrt.org/releases/ 2>/dev/null \
		| grep -oE '2[0-9]\.[0-9]+\.[0-9]+/' | tr -d / | sort -V | tail -1 || true)"
	[ -n "$RELEASE" ] || { echo "FEHLER: neuestes Release nicht ermittelbar, --release angeben" >&2; exit 1; }
	echo ">> Release: $RELEASE (automatisch)"
fi

# ---- apman-Feed -------------------------------------------------------------
# Der Feed ist nach OpenWrt-Zweig sortiert, nicht nach Punkt-Release:
# 25.12.5 -> openwrt-25.12, alles ohne Versionsnummer -> snapshot.
FEED_BRANCH=""
if [ "$WITH_APMAN" = 1 ]; then
	case "$RELEASE" in
	[0-9]*.[0-9]*) FEED_BRANCH="openwrt-${RELEASE%.*}" ;;
	*) FEED_BRANCH="snapshot" ;;
	esac
	echo ">> apman aus $FEED_ROOT/$FEED_BRANCH/<arch>/ ($APMAN_PKGS)"

	if [ -z "$KEYFILE" ]; then
		# Signaturpruefung bleibt an, also muss der oeffentliche Schluessel her.
		# Erst der Klon daneben, dann die veroeffentlichte Kopie.
		for cand in \
			"$ROOT/../ddimension-openwrt-repo/keys/ddimension.pem" \
			"$ROOT/../openwrt-repo/keys/ddimension.pem" \
			"$HERE/ddimension.pem"; do
			if [ -f "$cand" ]; then KEYFILE="$cand"; break; fi
		done
	fi
	if [ -z "$KEYFILE" ]; then
		KEYFILE="$(mktemp)"
		echo "   Schluessel wird von $FEED_ROOT/keys/ddimension.pem geladen"
		wget -qO "$KEYFILE" "$FEED_ROOT/keys/ddimension.pem" || {
			echo "FEHLER: Signaturschluessel nicht ladbar, --key angeben" >&2; exit 1; }
	fi
	[ -s "$KEYFILE" ] || { echo "FEHLER: Schluesseldatei $KEYFILE ist leer" >&2; exit 1; }
	echo "   Schluessel: $KEYFILE"
fi

# ---- Bauplan aus dem Inventory ---------------------------------------------
# eine Zeile je Geraet: name  target  subtarget  profil
PLAN="$(python3 - "$INVENTORY" "$PROFILE_OVERRIDE" "${DEVICES[@]+"${DEVICES[@]}"}" <<'PY'
import json, sys
inv = json.load(open(sys.argv[1]))
override = sys.argv[2]
only = [d.lower() for d in sys.argv[3:]]
rows = []
for dev in inv.get("devices", []):
    name = dev.get("name")
    if only and name.lower() not in only:
        continue
    target, sub = dev.get("target"), dev.get("subtarget")
    profile = override or dev.get("profile")
    if not (name and target and sub and profile):
        print("uebersprungen (unvollstaendig): %s" % name, file=sys.stderr)
        continue
    rows.append("\t".join([name, target, sub, profile]))
if not rows:
    sys.exit("FEHLER: kein passendes Geraet im Inventory")
print("\n".join(rows))
PY
)"

echo ">> Bauplan ($ENGINE, $TYPE, OpenWrt $RELEASE):"
printf '%s\n' "$PLAN" | awk -F'\t' '{printf "   %-14s %s/%s  %s\n", $1, $2, $3, $4}'

if [ "$DRYRUN" = 1 ]; then
	echo ">> --dry-run: nichts gebaut"
	exit 0
fi

mkdir -p "$OUT"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ---- je Geraet den files/-Baum vorbereiten ---------------------------------
prepare_files() {
	local name="$1" dir="$WORK/files/$name"
	mkdir -p "$dir"
	if [ -n "$FILES_DIR" ]; then
		cp -a "$FILES_DIR/." "$dir/"
	fi
	if [ "$WITH_APMAN_CONFIG" = 1 ]; then
		mkdir -p "$dir/etc/config"
		python3 - "$INVENTORY" "$name" > "$dir/etc/config/apman" <<'PY'
import json, sys
inv = json.load(open(sys.argv[1]))
name = sys.argv[2]
cfg = None
for dev in inv.get("devices", []):
    if dev.get("name") == name:
        cfg = dev.get("apman_config")
if not cfg:
    sys.exit("FEHLER: kein apman_config fuer %s im Inventory "
             "(Inventory mit --with-config erzeugen)" % name)
out = []
for section, values in cfg.items():
    stype = values.get(".type", "apman")
    if values.get(".anonymous"):
        out.append("config %s" % stype)
    else:
        out.append("config %s '%s'" % (stype, values.get(".name", section)))
    for key, val in values.items():
        if key.startswith("."):
            continue
        if isinstance(val, list):
            for item in val:
                out.append("\tlist %s '%s'" % (key, item))
        else:
            out.append("\toption %s '%s'" % (key, val))
    out.append("")
print("\n".join(out))
PY
	fi
	# ein leerer Baum wuerde FILES= mit nichts drin bedeuten — dann lieber weg
	find "$dir" -mindepth 1 -print -quit | grep -q . || rmdir "$dir"
}

# ---- Bauen, gruppiert nach target/subtarget --------------------------------
BUILD_GROUPS="$(printf '%s\n' "$PLAN" | awk -F'\t' '{print $2"/"$3}' | sort -u)"
FAILED=()
BUILT=0

for group in $BUILD_GROUPS; do
	target="${group%%/*}"; subtarget="${group##*/}"
	tag="${target}-${subtarget}-${RELEASE}"
	image="${IMAGE_REPO}:${tag}"
	echo
	echo "=== $group  ($image)"

	if [ "$PULL" = 1 ]; then
		"$ENGINE" pull "$image"
	fi

	# alle Geraete dieser Gruppe in EINEM Container: der ImageBuilder laedt die
	# Pakete nur einmal herunter statt je Profil
	members="$(printf '%s\n' "$PLAN" | awk -F'\t' -v g="$group" '$2"/"$3==g {print $1"\t"$4}')"

	script="set -e"$'\n'
	# Feeds und Key eintragen, apk (repositories) und opkg (repositories.conf)
	if [ -n "$KEYFILE" ]; then
		script+="mkdir -p keys && cp -f /inject/key.pem keys/ddimension.pem"$'\n'
	fi
	if [ "$WITH_APMAN" = 1 ]; then
		# Die Paket-Architektur steht im ImageBuilder selbst — verlaesslicher
		# als jede Tabelle target->arch, die hier gepflegt werden muesste.
		script+='ARCH="$(sed -n '"'"'s/^CONFIG_TARGET_ARCH_PACKAGES="\(.*\)"$/\1/p'"'"' .config)"'$'\n'
		script+='[ -n "$ARCH" ] || { echo "FEHLER: CONFIG_TARGET_ARCH_PACKAGES nicht gefunden"; exit 1; }'$'\n'
		script+='echo "apman-Feed: '"$FEED_ROOT/$FEED_BRANCH"'/$ARCH"'$'\n'
		script+='if [ -f repositories ]; then'$'\n'
		script+='	echo "'"$FEED_ROOT/$FEED_BRANCH"'/$ARCH/packages.adb" >> repositories'$'\n'
		script+='else'$'\n'
		script+='	echo "src/gz ddimension '"$FEED_ROOT/$FEED_BRANCH"'/$ARCH" >> repositories.conf'$'\n'
		script+='fi'$'\n'
	fi
	for feed in ${FEEDS[@]+"${FEEDS[@]}"}; do
		script+="if [ -f repositories ]; then echo '$feed' >> repositories; "
		script+="else echo 'src/gz extra $feed' >> repositories.conf; fi"$'\n'
	done

	while IFS=$'\t' read -r name profile; do
		[ -n "$name" ] || continue
		prepare_files "$name"
		pkgs=""
		[ "$NO_DEFAULT_PKGS" = 1 ] || pkgs="$DEFAULT_PKGS"
		if [ "$WITH_APMAN" = 1 ]; then pkgs="$pkgs $APMAN_PKGS"; fi
		if [ "$WITH_LUCI" = 1 ]; then pkgs="$pkgs $LUCI_PKGS"; fi
		if [ "$WITH_COLLECTD" = 1 ]; then pkgs="$pkgs $COLLECTD_PKGS"; fi
		pkgs="$pkgs $EXTRA_PKGS"
		if [ "$USE_INV_PKGS" = 1 ]; then
			pkgs="$pkgs $(python3 - "$INVENTORY" "$name" <<'PY'
import json, sys
inv = json.load(open(sys.argv[1]))
for dev in inv.get("devices", []):
    if dev.get("name") == sys.argv[2]:
        print(" ".join(dev.get("packages", [])))
PY
)"
		fi
		files_arg=""
		if [ -d "$WORK/files/$name" ]; then
			files_arg="FILES=/inject/files/$name"
		fi
		script+="echo '--- $name ($profile)'"$'\n'
		script+="make image PROFILE='$profile' PACKAGES='$(echo "$pkgs" | tr -s ' ')' $files_arg"$'\n'
		script+="mkdir -p /builder/collect/$name"$'\n'
		# jedes Profil raeumt sein Ergebnis sofort weg, sonst ueberschreiben
		# sich zwei Geraete mit demselben Profil gegenseitig
		script+="cp -a bin/targets/$target/$subtarget/. /builder/collect/$name/"$'\n'
		script+="rm -rf bin/targets/$target/$subtarget"$'\n'
	done <<< "$members"

	mkdir -p "$WORK/inject"
	if [ -d "$WORK/files" ]; then
		cp -a "$WORK/files" "$WORK/inject/files"
	fi
	if [ -n "$KEYFILE" ]; then
		cp -f "$KEYFILE" "$WORK/inject/key.pem"
	fi
	printf '%s' "$script" > "$WORK/inject/build.sh"

	# Kein Bind-Mount: der ImageBuilder laeuft im Container als uid 1000, ein
	# gemountetes Zielverzeichnis waere je nach Host-uid nicht beschreibbar.
	# Also hineinkopieren, bauen, herauskopieren.
	# --ulimit nofile ist Pflicht, nicht Kosmetik: mit Dockers Default laeuft
	# fakeroot/apk beim Paketindex in eine fd-Close-Schleife und brennt pro
	# Paket Minuten bei 100 % CPU (siehe Fallstricke in der README des
	# openwrt-repo). Der Build sieht dabei aus wie ein Haenger.
	cid="$("$ENGINE" create --ulimit nofile=1024:1048576 "$image" sh /inject/build.sh)"
	trap 'rm -rf "$WORK"; "$ENGINE" rm -f "$cid" >/dev/null 2>&1 || true' EXIT
	# das Verzeichnis selbst kopieren, nicht seinen Inhalt: /inject existiert im
	# Container noch nicht, und dann legt cp es an
	"$ENGINE" cp "$WORK/inject" "$cid:/inject" >/dev/null

	if "$ENGINE" start -a "$cid"; then
		while IFS=$'\t' read -r name profile; do
			[ -n "$name" ] || continue
			mkdir -p "$OUT/$name"
			tmp="$WORK/collect/$name"; mkdir -p "$tmp"
			"$ENGINE" cp "$cid:/builder/collect/$name/." "$tmp/" >/dev/null 2>&1 || {
				echo "   !! kein Ergebnis fuer $name"; FAILED+=("$name"); continue; }
			case "$TYPE" in
			sysupgrade) find "$tmp" -maxdepth 1 -type f \( -name '*sysupgrade*' -o -name '*.manifest' -o -name 'sha256sums' \) -exec cp -a {} "$OUT/$name/" \; ;;
			factory)    find "$tmp" -maxdepth 1 -type f \( -name '*factory*' -o -name '*.manifest' -o -name 'sha256sums' \) -exec cp -a {} "$OUT/$name/" \; ;;
			all)        cp -a "$tmp/." "$OUT/$name/" ;;
			esac
			echo "   -> $OUT/$name:"
			find "$OUT/$name" -maxdepth 1 -type f -printf '      %f (%s bytes)\n' | sort
			BUILT=$((BUILT + 1))
		done <<< "$members"
	else
		echo "   !! Build fehlgeschlagen fuer $group" >&2
		while IFS=$'\t' read -r name profile; do
			if [ -n "$name" ]; then FAILED+=("$name"); fi
		done <<< "$members"
	fi
	"$ENGINE" rm -f "$cid" >/dev/null 2>&1 || true
	trap 'rm -rf "$WORK"' EXIT
	rm -rf "$WORK/inject" "$WORK/files"
done

echo
echo "=== fertig: $BUILT Image-Satz/Saetze in $OUT"
if [ ${#FAILED[@]} -gt 0 ]; then
	echo "!! fehlgeschlagen: ${FAILED[*]}" >&2
	exit 1
fi
