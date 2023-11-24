<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;

class SSIDAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->with('Basics')
        ->add('name')
        ->add('setup_order')
        ->add('config_options', CollectionType::class, [
                // Prevents the "Delete" option from being displayed
            'type_options' => ['delete' => true],
            ], [
            'edit' => 'inline',
            'inline' => 'table',
            'sortable' => 'position',
            ])
        ->add('config_lists', CollectionType::class, [
                // Prevents the "Delete" option from being displayed
            'type_options' => ['delete' => true],
            ], [
            'edit' => 'inline',
            'inline' => 'table',
            'sortable' => 'position',
            ])
        ->add('config_files', CollectionType::class, [
                // Prevents the "Delete" option from being displayed
            'type_options' => ['delete' => true],
            ], [
            'edit' => 'inline',
            'inline' => 'table',
            'sortable' => 'position',
            ])
//		->add('config_options')
//		->add('config_lists')
    ->end();
        /*
                ->add('config_ssid')
                ->add('config_mode')
                ->add('config_network')
                ->add('config_ifname')
                ->add('config_isolate')
                ->add('config_wds')
                ->add('config_hidden')
            ->end()
            ->with('Encryption')
                ->add('config_encryption')
                ->add('config_key')
                ->add('config_key1')
                ->add('config_key2')
                ->add('config_key3')
                ->add('config_key4')
                ->add('config_wpa_group_rekey')
                ->add('config_wpa_psk_file')
                ->add('config_auth')
                ->add('config_auth_cache')
            ->end()
            ->with('Radius Accounting')
                ->add('config_acct_server')
                ->add('config_acct_port')
                ->add('config_acct_secret')
                ->add('config_acct_server_backup')
                ->add('config_acct_port_backup')
                ->add('config_acct_secret_backup')
                ->add('config_ownip')
            ->end()
            ->with('Radius Authentication')
                ->add('config_auth_server')
                ->add('config_auth_port')
                ->add('config_auth_secret')
                ->add('config_auth_server_backup')
                ->add('config_auth_port_backup')
                ->add('config_auth_secret_backup')
            ->end()
            ->with("Roaming")
                ->add('config_iapp_interface')
                ->add('config_rsn_preauth')
                ->add('config_ieee80211r')
                ->add('config_mobility_domain')
                ->add('config_nasid')
                ->add('config_pmk_r1_push')
                ->add('config_r0_key_lifetime')
                ->add('config_r1_key_holder')
                ->add('config_r0kh')
                ->add('config_r1kh')
                ->add('config_reassociation_deadline')
            ->end()
            ->with("802.11w Management Frame Encryption and Authentication")
                ->add('config_ieee80211w')
                ->add('config_ieee80211w_max_timeout')
                ->add('config_ieee80211w_retry_timeout')
            ->end()
            ->with("DAE")
                ->add('config_dae_client')
                ->add('config_dae_port')
                ->add('config_dae_secret')
            ->end()
            ->with("VLAN")
                ->add('config_dynamic_vlan')
                ->add('config_vlan_bridge')
                ->add('config_vlan_file')
                ->add('config_vlan_naming')
                ->add('config_vlan_tagged_interface')
            ->end()
            ->with("Identity")
                ->add('config_identity')
                ->add('config_anonymous_identity')
                ->add('config_ca_cert')
                ->add('config_client_cert')
                ->add('config_priv_key')
                ->add('config_priv_key_pwd')
            ->end()
            ->with("MAC ACLs")
                ->add('config_macfilter')
                ->add('config_maclist')
                ->add('config_macfile')
            ->end()
            ->with("Misc")
                ->add('config_bssid')
                ->add('config_bssid_blacklist')
                ->add('config_bssid_whitelist')
                ->add('config_disassoc_low_ack')
                ->add('config_doth')
                ->add('config_eap_type')
                ->add('config_eapol_version')
                ->add('config_ext_registrar')
                ->add('config_log_level')
                ->add('config_max_inactivity')
                ->add('config_maxassoc')
                ->add('config_mcast_rate')
                ->add('config_password')
                ->add('config_port')
                ->add('config_require_mode')
                ->add('config_server')
                ->add('config_short_preamble')
                ->add('config_supported_rates')
                ->add('config_uapsd')
                ->add('config_wmm')
            ->end()
            ->with("WPS")
                ->add('config_wps_ap_setup_locked')
                ->add('config_wps_device_name')
                ->add('config_wps_device_type')
                ->add('config_wps_independent')
                ->add('config_wps_label')
                ->add('config_wps_manufacturer')
                ->add('config_wps_pbc_in_m1')
                ->add('config_wps_pin')
                ->add('config_wps_pushbutton')
            ->end();
         */
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper->add('name');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('is_enabled', 'boolean');
        $listMapper->add('device_count', null, ['label' => 'Radios']);
        $listMapper->addIdentifier('setup_order');
    }
}
