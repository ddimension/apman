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
