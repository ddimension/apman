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
class IpskFeatureService extends DefaultFeatureService
{
    public $name = 'ipsk';

    public function getConfig(array $config)
    {
        $config = parent::getConfig($config);

        // the secret is per AP and lives on the AP (/etc/config/apman), not
        // in the SSID config which every AP of the network shares. The
        // preview instantiates without a device — nothing to override there.
        if ($this->device && $this->device->getRadio() && $this->device->getRadio()->getAccessPoint()) {
            $config['auth_secret'] = $this->device->getRadio()->getAccessPoint()->getRadiusSecret() ?: '';
        }
        // ppsk is the OpenWrt-native switch (ap.uc turns it into
        // wpa_psk_radius=2 + macaddr_acl=2); the raw lines repeat both so the
        // generated conf carries them even if the uci rendering ever changes
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
