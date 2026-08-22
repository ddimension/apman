<?php

namespace ApManBundle\Service;

/**
 * The option catalogue of an OpenWrt wifi-iface, with documentation.
 *
 * OpenWrt ships a JSON schema for its wireless configuration — 277 options for
 * a wifi-iface alone, with a description, a type and often a default for each.
 * That schema is the authority here: instead of maintaining a second, always
 * outdated list of what an SSID can be configured with, the editor is built
 * from it.
 *
 * What the schema does not have is order. Two hundred and seventy seven
 * alphabetically sorted switches are unusable, so the grouping below is ours:
 * every option lands in exactly one section, sections are ordered from "you
 * will need this" to "you will know if you need this", and each carries a
 * sentence on what it is for. Options the schema documents keep its wording;
 * the ones it leaves blank get a description here.
 */
class WirelessSchemaService
{
    /**
     * Sections, in display order. 'match' is tried in order: an explicit name
     * wins over a prefix, so 'ieee80211w' can sit in Security while every other
     * 'ieee80211*' goes elsewhere.
     */
    private const GROUPS = [
        'basics' => [
            'title' => 'Basics',
            'intro' => 'What the network is called, where it is bridged and whether it runs at all.',
            'names' => ['ssid', 'mode', 'network', 'ifname', 'disabled', 'start_disabled',
                'hidden', 'isolate', 'wds', 'macaddr', 'bssid', 'device', 'short_preamble',
                'default_disabled'],
        ],
        'security' => [
            'title' => 'Security',
            'intro' => 'Which encryption the network offers and how keys are handled. '.
                'This is the section that decides whether a client gets in at all.',
            'names' => ['encryption', 'key', 'key1', 'key2', 'key3', 'key4', 'ieee80211w',
                'ieee80211w_max_timeout', 'ieee80211w_retry_timeout', 'wpa_group_rekey',
                'wpa_pair_rekey', 'wpa_master_rekey', 'wpa_strict_rekey', 'wpa_disable_eapol_key_retries',
                'rsn_preauth', 'auth_cache', 'ppsk', 'macfilter', 'maclist', 'sae_require_mfp',
                'sae_pwe', 'sae_password', 'sae_max_retry', 'sae_sync', 'sae_anti_clogging_threshold',
                'owe_transition_ifname', 'owe_transition_ssid', 'owe_transition_bssid',
                'wpa_psk_file', 'wpa_psk_radius', 'transition_disable'],
            'prefixes' => ['sae_', 'owe_'],
        ],
        'enterprise' => [
            'title' => 'Enterprise and RADIUS',
            'intro' => 'Everything for 802.1X: which server authenticates, how sessions are '.
                'accounted for, and which attributes come back. Also the RADIUS route for '.
                'per device keys.',
            'names' => ['auth_server', 'auth_server_addr', 'auth_server_port', 'auth_secret',
                'auth_server_shared_secret', 'acct_server', 'acct_server_addr', 'acct_server_port',
                'acct_secret', 'acct_server_shared_secret', 'acct_interval', 'nasid', 'nas_identifier',
                'eap_reauth_period', 'eap_server', 'dae_client', 'dae_secret', 'dae_port',
                'ownip', 'own_ip_addr', 'dynamic_own_ip_addr', 'radius_client_addr'],
            'prefixes' => ['radius_', 'eap_', 'erp_', 'fils_'],
        ],
        'roaming' => [
            'title' => 'Fast roaming (802.11r)',
            'intro' => 'Fast BSS Transition lets a client change access point without a full '.
                'handshake. All members of a mobility domain need the same domain id and the '.
                'same key holder list, otherwise roaming silently falls back to slow.',
            'names' => ['ieee80211r', 'mobility_domain', 'ft_over_ds', 'ft_psk_generate_local',
                'r0kh', 'r1kh', 'r0_key_lifetime', 'r1_key_holder', 'reassociation_deadline',
                'pmk_r1_push', 'ft_iface', 'nasid'],
            'prefixes' => ['ft_'],
        ],
        'measurement' => [
            'title' => 'Measurement and steering (802.11k/v)',
            'intro' => 'Neighbour reports, beacon measurements and transition requests — what '.
                'the controller needs to move a client deliberately instead of hoping it '.
                'decides well on its own.',
            'names' => ['ieee80211k', 'ieee80211v', 'rrm_neighbor_report', 'rrm_beacon_report',
                'bss_transition', 'wnm_sleep_mode', 'wnm_sleep_mode_no_keys', 'time_advertisement',
                'time_zone', 'roam_rssi_threshold', 'roam_rssi_hysteresis', 'roam_check_interval',
                'proxy_arp', 'stationary_ap', 'bss_load_update_period', 'chan_util_avg_period',
                'mbo', 'mbo_cell_data_conn_pref', 'oce'],
            'prefixes' => ['rrm_', 'mbo_', 'track_sta_'],
        ],
        'clients' => [
            'title' => 'Client handling',
            'intro' => 'How long a silent station is kept, when it is polled and how many may '.
                'be associated at once.',
            'names' => ['max_inactivity', 'skip_inactivity_poll', 'disassoc_low_ack', 'maxassoc',
                'max_listen_int', 'dtim_period', 'beacon_int', 'ap_max_inactivity',
                'no_probe_resp_if_max_sta', 'tdls_prohibit', 'uapsd', 'wmm', 'wmm_enabled',
                'multi_ap', 'per_sta_vif'],
        ],
        'network' => [
            'title' => 'Network, VLAN and multicast',
            'intro' => 'What happens to the traffic once a client is in: bridging, VLAN '.
                'assignment, and the multicast handling that decides how much of the airtime '.
                'is wasted on broadcasts.',
            'names' => ['dynamic_vlan', 'vlan_file', 'vlan_naming', 'vlan_tagged_interface',
                'vlan_bridge', 'vlan_no_bridge', 'na_mcast_to_ucast', 'multicast_to_unicast',
                'multicast_to_unicast_all', 'bridge', 'bridge_empty', 'snooping', 'igmp_snooping',
                'proxy_arp', 'ipv6', 'iapp_interface'],
            'prefixes' => ['vlan_'],
        ],
        'passpoint' => [
            'title' => 'Passpoint and Interworking',
            'intro' => 'Hotspot 2.0: the network advertises who it is and which roaming '.
                'partners it accepts, so a client can join without anybody typing anything. '.
                'Only useful together with a RADIUS federation.',
            'names' => ['interworking', 'hs20', 'access_network_type', 'internet', 'asra', 'esr',
                'uesa', 'venue_group', 'venue_type', 'venue_name', 'venue_url', 'hessid',
                'roaming_consortium', 'domain_name', 'nai_realm', 'anqp_3gpp_cell_net',
                'anqp_domain_id', 'network_auth_type', 'ipaddr_type_availability',
                'iw_enabled', 'gas_address3', 'gas_comeback_delay', 'gas_frag_limit'],
            'prefixes' => ['hs20_', 'osu_', 'operator_', 'anqp_', 'iw_', 'osen'],
        ],
        'onboarding' => [
            'title' => 'Onboarding (WPS and DPP)',
            'intro' => 'Ways to get a device onto the network without typing a passphrase. '.
                'WPS is the old pushbutton mechanism, DPP (Wi-Fi Easy Connect) the standardised '.
                'successor that never puts the secret into the QR code itself.',
            'names' => ['wps_pushbutton', 'wps_label', 'wps_device_name', 'wps_device_type',
                'wps_manufacturer', 'wps_pin', 'wps_config', 'wps_independent', 'wps_ap_setup_locked',
                'ext_registrar', 'upnp_iface', 'friendly_name', 'manufacturer_url', 'model_description'],
            'prefixes' => ['wps_', 'dpp_'],
        ],
        'airtime' => [
            'title' => 'Airtime and rates',
            'intro' => 'Who gets how much of the medium, and which rates are allowed. Useful '.
                'to stop one slow or greedy device from holding up a whole radio.',
            'names' => ['airtime_bss_weight', 'airtime_bss_limit', 'airtime_sta_weight',
                'basic_rate', 'supported_rates', 'mcast_rate', 'rts_threshold', 'frag_threshold',
                'qos_map_set', 'require_mode'],
            'prefixes' => ['airtime_'],
        ],
        'sta' => [
            'title' => 'Client mode (STA)',
            'intro' => 'Only relevant when this interface joins another network instead of '.
                'offering one — the credentials and certificates it presents.',
            'names' => ['identity', 'anonymous_identity', 'password', 'eap_type', 'auth',
                'ca_cert', 'ca_cert2', 'client_cert', 'client_cert2', 'priv_key', 'priv_key2',
                'priv_key_pwd', 'priv_key2_pwd', 'ca_cert_usesystem', 'ca_cert2_usesystem',
                'subject_match', 'subject_match2', 'altsubject_match', 'altsubject_match2',
                'domain_match', 'domain_match2', 'domain_suffix_match', 'domain_suffix_match2',
                'bssid_blacklist', 'bssid_whitelist', 'scan_list', 'default_macaddr'],
        ],
        'mesh' => [
            'title' => 'Mesh (802.11s)',
            'intro' => 'Only for mesh interfaces. Leave alone unless this network is a mesh.',
            'prefixes' => ['mesh_'],
        ],
        'expert' => [
            'title' => 'Expert and raw configuration',
            'intro' => 'Everything the sections above do not cover, plus the escape hatches: '.
                'raw hostapd lines that are written into the generated configuration verbatim. '.
                'A typo here breaks the whole radio, not just this network.',
            'names' => ['custom_cfg', 'hostapd_bss_options', 'hostapd_options'],
        ],
    ];

    /** what the schema leaves undocumented, in our words */
    private const EXTRA_DOCS = [
        'encryption' => 'Encryption mode as uci writes it: none, owe, psk2 (WPA2 with a '.
            'passphrase), sae (WPA3), sae-mixed or wpa3-mixed (both at once), wpa2/wpa3 for '.
            '802.1X. Everything else in this section follows from this choice.',
        'key' => 'The passphrase for psk/sae modes, or the shared secret index for WEP. With '.
            'per device keys this is only the fallback for clients that have none of their own.',
        'ppsk' => 'Per device keys: hostapd asks RADIUS for the key of each MAC address instead '.
            'of using one passphrase for everyone. Requires a reachable RADIUS server — without '.
            'it nobody gets in.',
        'macfilter' => 'How the MAC list is used: disable, allow (only those listed) or deny '.
            '(everyone but those listed).',
        'maclist' => 'The MAC addresses macfilter refers to.',
        'custom_cfg' => 'Raw hostapd configuration lines, appended verbatim. The last resort '.
            'for options uci does not model.',
        'domain_name' => 'Passpoint: the domain names this network belongs to, comma separated. '.
            'A client matching one of them treats the network as its home network.',
        'basic_rate' => 'Rates a client must support to associate at all, in kbit/s. Raising '.
            'the lowest rate keeps slow legacy devices out and shortens the time each frame '.
            'occupies the medium.',
        'iw_enabled' => 'Switches Interworking (802.11u) on. Prerequisite for Passpoint.',
        'ssid' => 'The name broadcast over the air, up to 32 characters. The name of this entry in the controller may differ — this is what clients see.',
        'mode' => 'What role the interface plays: ap (offers a network), sta (joins one), adhoc, mesh, monitor or wds.',
        'start_disabled' => 'The interface is created but stays down until something brings it up. For networks that are only switched on when they are needed.',
        'wds' => 'Four address mode: lets a connected device bridge a whole network behind it instead of only itself. Both sides have to agree.',
        'radios' => 'Which radios of the access point carry this network. Empty means all of them.',
        'powersave' => 'Client mode: allow the radio to sleep between beacons.',
        'rsn_preauth' => 'Lets a client authenticate with the next access point while it is still connected to the current one — the predecessor of 802.11r, and only for WPA2 Enterprise.',
        'dynamic_own_ip_addr' => 'Derive the NAS-IP-Address from the route towards the RADIUS server instead of configuring it fixed.',
        'eap_type' => 'Client mode: which EAP method to use — tls, ttls, peap or fast.',
        'priv_key' => 'Client mode: the private key belonging to the client certificate.',
        'priv_key_pwd' => 'Password protecting that private key.',
        'ca_cert_usesystem' => 'Validate the server certificate against the system CA store instead of a supplied file.',
        'ca_cert2_usesystem' => 'The same for phase 2 of a tunnelled EAP method.',
        'subject_match2' => 'Phase 2: accept the server certificate only if its subject contains this string.',
        'altsubject_match2' => 'Phase 2: accept the server certificate only if a subjectAltName matches one of these entries.',
        'domain_match' => 'Accept the server certificate only if its domain equals one of these values exactly. The strongest of the three name checks.',
        'domain_match2' => 'The same for phase 2.',
        'domain_suffix_match' => 'Like domain_match, but the certificate domain only has to end with one of these values — so one entry covers a whole domain.',
        'domain_suffix_match2' => 'The same for phase 2.',
        'bssid_whitelist' => 'Client mode: associate only with these BSSIDs, ignore every other.',
        'bssid_blacklist' => 'Client mode: never associate with these BSSIDs.',
        'supported_rates' => 'The rates this network offers, in kbit/s. Together with basic_rate this decides which devices can join at all.',
        'vlan_file' => 'The file hostapd keeps its dynamic VLAN assignments in.',
        'vlan_tagged_interface' => 'The interface that carries the tagged traffic for dynamic VLANs.',
        'venue_group' => 'Passpoint: what kind of place this is (business, residential, educational …), announced so a client can tell networks apart before joining.',
        'venue_type' => 'Passpoint: the more precise type within the venue group.',
        'gas_address3' => 'Which address goes into field 3 of GAS frames. Only needed for clients that reject the standard behaviour.',
        'iw_ipaddr_type_availability' => 'Announces over 802.11u whether a client can expect an IPv4 or IPv6 address here, and of what kind.',
        'multi_ap_backhaul_key' => 'The passphrase of the backhaul network in a Multi-AP (EasyMesh) setup — the link the access points use among themselves.',
        'mesh_id' => 'The name of the mesh network, the mesh counterpart of the SSID.',
        'mesh_auto_open_plinks' => 'Open peer links to other mesh nodes on sight instead of waiting to be asked.',
        'mesh_max_peer_links' => 'How many mesh neighbours this node accepts at the same time.',
        'mesh_plink_timeout' => 'After how many seconds an idle peer link is closed.',
        'mesh_holding_timeout' => 'How long a closing peer link is held before it is discarded (ms).',
        'mesh_confirm_timeout' => 'How long to wait for the confirmation of a peer link before giving up (ms).',
        'mesh_retry_timeout' => 'How long to wait for the answer to a peer link message (ms).',
        'mesh_max_retries' => 'How often a mesh frame is retried before it is dropped.',
        'mesh_ttl' => 'Hop limit for mesh data frames — how far a frame may travel through the mesh.',
        'mesh_element_ttl' => 'Hop limit for mesh management frames.',
        'mesh_rssi_threshold' => 'Below this level (dBm) no peer link is opened, so a barely audible neighbour does not become a bad route. 0 disables the limit.',
        'mesh_power_mode' => 'Power save behaviour towards mesh neighbours: active, light or deep.',
        'mesh_awake_window' => 'How long a node stays awake after a beacon when using mesh power save (in time units).',
        'mesh_sync_offset_max_neighor' => 'The largest clock offset to a neighbour that is tolerated before synchronisation steps in. The missing letter in "neighor" is OpenWrt s own, kept so the option still matches.',
        'mesh_gate_announcements' => 'Whether this node announces itself as a gateway out of the mesh.',
        'mesh_min_discovery_timeout' => 'Shortest wait before a path search is repeated (ms).',
        'mesh_path_refresh_time' => 'How long before expiry an active path is refreshed (ms).',
        'mesh_hwmp_active_path_timeout' => 'How long a learned path stays valid without traffic (time units). HWMP is the mesh path selection protocol.',
        'mesh_hwmp_active_path_to_root_timeout' => 'The same, for the path to the root node.',
        'mesh_hwmp_preq_min_interval' => 'Minimum interval between two path requests (time units) — the brake against request storms.',
        'mesh_hwmp_max_preq_retries' => 'How often a path request is repeated before the target counts as unreachable.',
        'mesh_hwmp_confirmation_interval' => 'Minimum interval between two path confirmations (ms).',
        'mesh_hwmp_net_diameter_traversal_time' => 'Estimated time for a frame to cross the whole mesh (time units). Several other timeouts are derived from it.',
        'mesh_hwmp_rootmode' => 'Whether and how this node acts as mesh root: 0 not at all, 2 to 4 are different announcement modes.',
        'mesh_hwmp_root_interval' => 'How often the root announces its paths (ms).',
        'mesh_hwmp_rann_interval' => 'How often the root announces itself (ms).',
        'ssid:string' => 'Not a real option — a type annotation that slipped into the schema. Use ssid.',
        'password:wpakey' => 'Not a real option — a type annotation that slipped into the schema. Use password.',
        'port:port' => 'Not a real option — a type annotation that slipped into the schema.',
        'server:host' => 'Not a real option — a type annotation that slipped into the schema.',
        // Not in OpenWrt's schema, but in use here — so they get a text too.
        'ieee80211v' => 'Switches on 802.11v (wireless network management) as a whole. OpenWrt\'s '
            . 'schema only knows the single parts (bss_transition, wnm_sleep_mode, '
            . 'time_advertisement); this collective switch comes from older configurations '
            . 'and is best replaced by them.',
        'roam_rssi_threshold' => 'Below this level (dBm) a client is nudged towards a better '
            . 'access point. Not an OpenWrt option — it is evaluated by the roaming logic on '
            . 'our side, not by hostapd.',
        'encryption_note' => '',
    ];

    /** cross checks that only make sense between options */
    private const HINTS = [
        ['when' => ['encryption' => '/sae|wpa3/'], 'require' => ['ieee80211w' => '2'],
            'text' => 'WPA3 requires protected management frames. ieee80211w must be 2 (required), not 1.'],
        ['when' => ['ieee80211r' => '/^(1|true|on)$/'], 'need' => ['mobility_domain'],
            'text' => 'Fast roaming without a mobility domain does nothing — every access point '.
                'needs the same domain id.'],
        ['when' => ['ppsk' => '/^(1|true|on)$/'], 'need' => ['auth_server'],
            'text' => 'Per device keys over RADIUS need an authentication server; without one '.
                'every association is rejected.'],
        ['when' => ['dynamic_vlan' => '/^[12]$/'], 'need' => ['vlan_naming'],
            'text' => 'Dynamic VLANs without a naming scheme produce interface names that are '.
                'hard to predict; set vlan_naming explicitly.'],
    ];

    /** the section type an option belongs to */
    public const IFACE = 'iface';
    public const DEVICE = 'device';

    /** @var array<string,array> loaded schemas by section type */
    private $schema = [];
    private $projectDir;

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * OpenWrt's own schema for one section type, as shipped in config/wireless.
     *
     * wifi-device.json sat there unread for as long as it has been in the
     * repository: schema() had the iface file hard-wired, so the 152 options a
     * radio can carry were described by a file nothing opened, and the radio
     * editor was nineteen fields written by hand.
     */
    private function schema($type = self::IFACE)
    {
        if (!isset($this->schema[$type])) {
            $file = $this->projectDir.'/config/wireless/wifi-'.$type.'.json';
            $data = is_readable($file) ? json_decode(file_get_contents($file), true) : null;
            $this->schema[$type] = (is_array($data) && isset($data['properties'])) ? $data['properties'] : [];
        }

        return $this->schema[$type];
    }

    /**
     * Is this option known to either schema?
     *
     * Without a type it answers for both, which is what a check that only has
     * an option name — a raw hostapd line, say — can ask.
     */
    public function isKnown($name, $type = null)
    {
        if (null !== $type) {
            return isset($this->schema($type)[$name]);
        }

        return isset($this->schema(self::IFACE)[$name]) || isset($this->schema(self::DEVICE)[$name]);
    }

    /**
     * Which section type declares this option, or null if neither does.
     *
     * The answer that says a raw line in hostapd_bss_options is really a radio
     * option: stationary_ap lives in wifi-device, and setting it per bss writes
     * it eleven times into the file it was going to be in once.
     */
    public function sectionOf($name)
    {
        if (isset($this->schema(self::IFACE)[$name])) {
            return self::IFACE;
        }

        return isset($this->schema(self::DEVICE)[$name]) ? self::DEVICE : null;
    }

    /**
     * Everything known about one option, with aliases resolved: uci accepts
     * "acct_server" but the schema documents "acct_server_addr".
     */
    public function option($name, $type = self::IFACE)
    {
        $schema = $this->schema($type);
        $entry = $schema[$name] ?? null;
        $aliasOf = null;
        if ($entry && 'alias' === ($entry['type'] ?? null)) {
            $aliasOf = $entry['default'] ?? null;
            $entry = $aliasOf && isset($schema[$aliasOf]) ? $schema[$aliasOf] : $entry;
        }

        $type = $entry['type'] ?? 'string';
        $default = array_key_exists('default', $entry ?? []) && 'alias' !== ($entry['type'] ?? '')
            ? $entry['default'] : null;

        return [
            'name' => $name,
            'known' => null !== $entry,
            'alias_of' => $aliasOf,
            'type' => $type,
            // An alias inherits the description of what it points at — both
            // from the schema and from our own texts, otherwise iw_venue_group
            // would stay blank while venue_group right below it is explained.
            'description' => $entry['description']
                ?? self::EXTRA_DOCS[$name]
                ?? ($aliasOf ? (self::EXTRA_DOCS[$aliasOf] ?? null) : null),
            // Where the text comes from. Ours are written from the hostapd and
            // mac80211 sources, so they are worth reading with one eye open.
            'doc_source' => isset($entry['description']) ? 'openwrt'
                : ((isset(self::EXTRA_DOCS[$name]) || ($aliasOf && isset(self::EXTRA_DOCS[$aliasOf]))) ? 'apman' : null),
            'default' => $default,
            // A value tells you what happens. No value and a documented
            // default tells you what happens too. No value and no default
            // means the option is never written — for a switch that is simply
            // "off", for anything else it means hostapd falls back to whatever
            // it compiled in, which is not visible from here.
            'has_default' => null !== $default,
            'switch' => 'boolean' === $type,
            'enum' => $entry['enum'] ?? null,
            'minimum' => $entry['minimum'] ?? null,
            'maximum' => $entry['maximum'] ?? null,
            'is_list' => 'array' === ($entry['type'] ?? null),
            'group' => $this->groupOf($name),
        ];
    }

    public function groupOf($name)
    {
        foreach (self::GROUPS as $key => $group) {
            if (in_array($name, $group['names'] ?? [], true)) {
                return $key;
            }
        }
        foreach (self::GROUPS as $key => $group) {
            foreach ($group['prefixes'] ?? [] as $prefix) {
                if (0 === strpos($name, $prefix)) {
                    return $key;
                }
            }
        }

        return 'expert';
    }

    public function groupTitles()
    {
        $out = [];
        foreach (self::GROUPS as $key => $group) {
            $out[$key] = ['title' => $group['title'], 'intro' => $group['intro'] ?? ''];
        }

        return $out;
    }

    /**
     * The editor model for one section: every group with its options, the ones
     * that are set first, each with value, default and documentation.
     *
     * $type picks the schema — a network's options or a radio's.
     */
    public function describe($values, $lists, $type = self::IFACE)
    {
        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $groups[$key] = [
                'key' => $key,
                'title' => $group['title'],
                'intro' => $group['intro'] ?? '',
                'set' => [],
                'available' => [],
                'counts' => ['set' => 0, 'default' => 0, 'off' => 0, 'unwritten' => 0],
            ];
        }

        // everything the schema knows, plus whatever this SSID has on top
        $names = array_keys($this->schema($type));
        foreach (array_keys($values) as $name) {
            if ('' !== $name && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        foreach (array_keys($lists) as $name) {
            if ('' !== $name && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        sort($names);

        foreach ($names as $name) {
            if ('' === $name) {
                continue;
            }
            $option = $this->option($name, $type);
            $isList = $option['is_list'] || isset($lists[$name]);
            $option['is_list'] = $isList;
            $option['value'] = $isList ? ($lists[$name] ?? null) : ($values[$name] ?? null);
            $option['is_set'] = $isList ? isset($lists[$name]) : array_key_exists($name, $values);
            if ($option['is_set']) {
                $option['state'] = 'set';
            } elseif ($option['has_default']) {
                $option['state'] = 'default';
            } elseif ($option['switch'] || $isList) {
                $option['state'] = 'off';
            } else {
                $option['state'] = 'unwritten';
            }
            $target = isset($groups[$option['group']]) ? $option['group'] : 'expert';
            $groups[$target][$option['is_set'] ? 'set' : 'available'][] = $option;
            $groups[$target]['counts'][$option['state']] =
                ($groups[$target]['counts'][$option['state']] ?? 0) + 1;
        }

        return $groups;
    }

    /** cross option warnings, evaluated against the current values */
    public function hints($values)
    {
        $out = [];
        foreach (self::HINTS as $hint) {
            $applies = true;
            foreach ($hint['when'] as $name => $pattern) {
                if (!isset($values[$name]) || !preg_match($pattern, (string) $values[$name])) {
                    $applies = false;
                }
            }
            if (!$applies) {
                continue;
            }
            foreach ($hint['need'] ?? [] as $name) {
                if (empty($values[$name])) {
                    $out[] = $hint['text'];
                    continue 2;
                }
            }
            foreach ($hint['require'] ?? [] as $name => $want) {
                if ((string) ($values[$name] ?? '') !== (string) $want) {
                    $out[] = $hint['text'];
                    continue 2;
                }
            }
        }

        return $out;
    }
}
