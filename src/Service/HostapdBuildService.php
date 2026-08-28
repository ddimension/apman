<?php

namespace ApManBundle\Service;

use ApManBundle\Entity\AccessPoint;

/**
 * Which hostapd an access point runs, because two of them now answer
 * differently to the same configuration.
 *
 * Until 2026-08-28 there was one hostapd in the fleet and every rule about it
 * could be absolute. `docs/ipsk.md` says "**Do not put `sae_pwe` back**" and
 * `docs/ipsk-test.md` says it "takes the whole network down, immediately" —
 * both earned on 2026-08-21, when `sae_pwe=2` locked every SAE station out of
 * kalclients with status 126.
 *
 * `wpad-saeradh2e` from our own feed makes those statements false. It carries
 * six patches (810-815) that give a RADIUS-delivered password an SAE PT, so
 * hash-to-element works and the whole prohibition inverts: on that build
 * `sae_pwe` is safe, and on 6 GHz — which permits nothing but H2E — it is the
 * missing piece that makes iPSK possible at all. See `docs/hostapd-sae-radius.md`.
 *
 * A rule that cannot tell the two apart must therefore either keep warning
 * about a correct configuration or stop warning about a fatal one. This service
 * is what lets it tell them apart.
 *
 * ## Why the package name is the answer
 *
 * The patched build is a separate package, not a variant of the base one, and
 * that was deliberate: `PROVIDES:=hostapd wpa-supplicant` with
 * `CONFLICTS:=$(BASE_PROVIDERS)`, so it replaces `wpad-openssl` rather than
 * sitting beside it. Exactly one wpad is installed on a device, and its name
 * says which. Measured on the fleet 2026-08-28:
 *
 *     wpad-openssl-2025.08.26~ca266cc2-r2 aarch64_cortex-a53 {feeds/base/...}
 *
 * There is no version string to interpret and no feature probe to invent.
 *
 * ## The answer is deliberately pessimistic
 *
 * An access point that cannot be reached, or that answers something
 * unparseable, comes back as *not* patched. Being wrong in that direction
 * leaves a warning standing that did not need to stand; being wrong in the
 * other direction would silence the warning that describes an outage.
 */
class HostapdBuildService
{
    /** the feed package carrying the SAE-over-RADIUS patches */
    public const PATCHED_PACKAGE = 'wpad-saeradh2e';

    /**
     * An hour. A build changes when somebody installs one, which is an act, not
     * a drift — and the consistency page asks this once per access point per
     * run, so a short ttl would put a shell on every access point for a fact
     * that is the same as it was yesterday.
     */
    private const TTL = 3600;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \ApManBundle\Factory\CacheFactory $cacheFactory,
        private readonly ApUbusService $ubus,
    ) {
    }

    /**
     * What this access point runs.
     *
     * Always a full answer, never null: `known` says whether anybody actually
     * asked the device, and the rest is only meaningful when it is true.
     */
    public function of(AccessPoint $ap): array
    {
        $key = 'hostapd.build.'.$ap->getId();
        $hit = $this->cacheFactory->getCacheItemValue($key);
        if (is_array($hit)) {
            return $hit;
        }

        $answer = $this->ask($ap);
        $this->cacheFactory->addCacheItem($key, $answer, self::TTL);

        return $answer;
    }

    /**
     * Does this access point's hostapd understand SAE with a RADIUS password.
     *
     * The one question the rules ask, so it has one name.
     */
    public function saeOverRadius(AccessPoint $ap): bool
    {
        return (bool) ($this->of($ap)['patched'] ?? false);
    }

    /**
     * The names of the access points that run the patched build.
     *
     * For rules that work on parsed configuration blocks and have a name rather
     * than an entity to go by.
     *
     * @param AccessPoint[] $aps
     *
     * @return array<string,bool> name => patched
     */
    public function fleet(array $aps): array
    {
        $out = [];
        foreach ($aps as $ap) {
            if (!$ap instanceof AccessPoint) {
                continue;
            }
            $out[$ap->getName()] = $this->saeOverRadius($ap);
        }

        return $out;
    }

    /**
     * Throw away what we know about one access point, for right after an
     * install changed it.
     */
    public function forget(AccessPoint $ap): void
    {
        $this->cacheFactory->deleteCacheItem('hostapd.build.'.$ap->getId());
    }

    /**
     * Ask the device.
     *
     * apk first because that is what the fleet runs; opkg after it so this
     * keeps working on an access point that has not been migrated. Both are
     * asked in one shell so an access point costs one round trip either way.
     */
    private function ask(AccessPoint $ap): array
    {
        $unknown = ['known' => false, 'patched' => false, 'package' => null,
            'version' => null, 'why' => null];

        $opts = new \stdClass();
        $opts->command = '/bin/sh';
        $opts->params = ['-c',
            "apk list -I 2>/dev/null | grep -E '^wpad' ; "
            ."opkg list-installed 2>/dev/null | grep -E '^wpad' ; true"];

        $res = $this->ubus->call($ap, 'file', 'exec', $opts, 15);
        if (!$res->isOk()) {
            $this->logger->debug('HostapdBuildService: '.$ap->getName()
                .' did not say which hostapd it runs: '.$res->why(), ['ap' => $ap->getName()]);
            $unknown['why'] = $res->why();

            return $unknown;
        }

        $data = $res->data;
        $stdout = is_object($data) ? (string) ($data->stdout ?? '') : '';
        $found = self::parse($stdout);
        if (null === $found) {
            $this->logger->info('HostapdBuildService: '.$ap->getName()
                .' listed no wpad package at all', ['ap' => $ap->getName()]);
            $unknown['why'] = 'no wpad package listed';

            return $unknown;
        }

        return ['known' => true, 'patched' => self::PATCHED_PACKAGE === $found['package'],
            'package' => $found['package'], 'version' => $found['version'], 'why' => null];
    }

    /**
     * Pull the package name and version out of what apk or opkg printed.
     *
     * Static and pure so it can be tested without an access point.
     *
     *   apk:  wpad-openssl-2025.08.26~ca266cc2-r2 aarch64_cortex-a53 {feeds/...}
     *   opkg: wpad-openssl - 2025.08.26~ca266cc2-r2
     *
     * The name cannot simply be cut at the first dash — every one of these
     * names contains dashes. What separates name from version is a dash
     * followed by a digit, and no wpad package name has a digit after a dash.
     */
    public static function parse(string $stdout): ?array
    {
        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            $line = trim($line);
            if ('' === $line || !str_starts_with($line, 'wpad')) {
                continue;
            }
            // opkg puts " - " between the two; apk puts them in one token
            if (preg_match('/^(wpad[A-Za-z0-9_-]*?)\s+-\s+(\S+)/', $line, $m)) {
                return ['package' => $m[1], 'version' => $m[2]];
            }
            if (preg_match('/^(wpad[A-Za-z0-9_-]*?)-([0-9]\S*)/', $line, $m)) {
                return ['package' => $m[1], 'version' => $m[2]];
            }
            // a name with no version at all is still an answer
            if (preg_match('/^(wpad[A-Za-z0-9_-]*)/', $line, $m)) {
                return ['package' => $m[1], 'version' => null];
            }
        }

        return null;
    }
}
