<?php

namespace ApManBundle\Service;

/**
 * iPSK: per-station keys answered by the AP's own RADIUS server.
 *
 * The catalog config carries the loopback marker (ppsk, auth_server,
 * auth_server_port); this implementation adds the per-AP secret. The raw
 * lines repeat wpa_psk_radius=2 + macaddr_acl=2 so the generated conf
 * carries them even if the uci rendering ever changes.
 *
 * No wifi-station sections are written for these networks (see
 * AccessPointService::publishConfig). wifi-scripts still renders
 * wpa_psk_file and sae_password_file, but it truncates both files on every
 * generation, so they stay empty and every key comes from the Access-Accept
 * — WPA2 and SAE alike. Keys reach the access point as a versioned set
 * through the agent's keystore (PpskService::distributeKeystore).
 */
class IpskFeatureService extends AbstractFeatureService
{
    public function getName(): string
    {
        return 'ipsk';
    }

    public function getConfig(array $config, \ApManBundle\Library\FeatureContext $ctx): array
    {
        $config = parent::getConfig($config, $ctx);

        // the secret is per AP and lives on the AP (/etc/config/apman), not
        // in the SSID config which every AP of the network shares. The
        // preview has no device and therefore no access point — nothing to
        // override there, which the context says outright instead of leaving
        // it to a guard on an undeclared property.
        $ap = $ctx->accessPoint();
        if ($ap) {
            $config['auth_secret'] = $ap->getRadiusSecret() ?: '';
        }
        // ppsk is the OpenWrt-native switch: ap.uc turns it into
        // wpa_psk_radius=2 + macaddr_acl=2 and, in the same branch, skips the
        // one that would render the network passphrase. Setting it makes an
        // iPSK network say the same thing whether the reader is ap.uc or
        // hostapd, and it is what kalclients has carried all along.
        //
        // The old worry that ppsk brings the psk file back does not hold:
        // ap.uc creates wpa_psk_file for every psk network, outside this
        // branch (ap.uc:157). What it holds is nothing — there are no
        // wifi-station sections for an iPSK network to render from.
        $config['ppsk'] = '1';

        // …and the raw lines repeat both, so the generated conf carries them
        // even if the uci rendering ever changes
        if (!isset($config['hostapd_bss_options']) || !is_array($config['hostapd_bss_options'])) {
            $config['hostapd_bss_options'] = [];
        }
        foreach (['wpa_psk_radius=2', 'macaddr_acl=2'] as $raw) {
            $config['hostapd_bss_options'][] = $raw;
        }

        // The network passphrase has no business on the access point. Every key
        // of an iPSK network comes from the Access-Accept, including the one an
        // unknown station gets — the agent answers that from `network_key` in
        // its key store, which the controller fills from this same ssid option.
        // Sending it on as `key` only means ap.uc renders it as
        // wpa_passphrase, in a file that is 0644 while the key store holding
        // the same secret is 0600.
        //
        // For SAE it would be worse than untidy: sae_get_password() prefers
        // wpa_passphrase over anything RADIUS delivered, so the passphrase
        // would shadow every per device key. Measured on the test bed
        // 2026-08-22 — the station with its own key was refused, the one with
        // the passphrase got in.
        //
        // kalclients never showed this because it carries ppsk=1 from another
        // feature, and ap.uc's first branch skips the passphrase. Dropping the
        // key here gets the same result without depending on that.
        unset($config['key']);

        // Do NOT set sae_pwe here. ap.uc skips its own default as soon as
        // `ppsk` is set, and that is deliberate: a password delivered over
        // RADIUS has no SAE PT (`use_sta_psk` is only set from the ucode
        // sta_auth hook, which the RADIUS ACL path never reaches). With H2E
        // advertised, a client that commits with hash-to-element gets
        // rejected — measured 2026-08-21 on kalclients: the station sent
        // `status=126 (SAE_HASH_TO_ELEMENT)` and hostapd answered status 1.
        // Leaving sae_pwe unset means hunting-and-pecking only, which is what
        // works with RADIUS-delivered SAE passwords. The corollary is that
        // iPSK plus SAE cannot work on 6 GHz, where H2E is mandatory.

        $config['hostapd_bss_options'] = array_values(array_unique($config['hostapd_bss_options']));

        return $config;
    }
}
