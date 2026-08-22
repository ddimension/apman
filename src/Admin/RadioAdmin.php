<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class RadioAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('name', TextType::class);
        $formMapper->add('config_type');
        $formMapper->add('config_path');
        $formMapper->add('config_disabled');
        $formMapper->add('config_channel');
        $formMapper->add('config_channels');
        $formMapper->add('config_band');
        $formMapper->add('config_hwmode');
        $formMapper->add('config_txpower');
        $formMapper->add('config_country');
        $formMapper->add('config_require_mode');
        $formMapper->add('config_log_level');
        $formMapper->add('config_htmode');
        $formMapper->add('config_noscan');
        $formMapper->add('config_beacon_int');
        $formMapper->add('config_basic_rate');
        $formMapper->add('config_supported_rates');
        $formMapper->add('config_rts');
        $formMapper->add('config_antenna_gain')
            ->add('config_ht_capab', TextType::class)
            // Everything wifi-device.json knows and this form does not — a
            // hundred and fifty-two options against nineteen columns. Written
            // the way uci spells them, and applied over the fields above.
            ->add('config', TextareaType::class, [
                'required' => false,
                'label' => 'Further options (json)',
                'help' => 'uci option names as in wifi-device.json, e.g. '
                    .'{"mbssid": 1, "he_bss_color": 12, "hostapd_options": ["..."]}. '
                    .'Applied over the fields above.',
                'attr' => ['rows' => 6],
            ]);

        $formMapper->get('config')->addModelTransformer(new CallbackTransformer(
            function ($configAsArray) {
                if (!is_array($configAsArray) || !count($configAsArray)) {
                    return '';
                }

                return json_encode($configAsArray, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            },
            function ($configAsString) {
                if (!is_string($configAsString) || '' === trim($configAsString)) {
                    return [];
                }

                return json_decode($configAsString, true, 512, JSON_THROW_ON_ERROR);
            }
        ));

        // config_ht_capab is a json column: the form needs a string, the
        // entity an array. Without this the create page dies with
        // "Array to string conversion".
        $formMapper->get('config_ht_capab')->addModelTransformer(new CallbackTransformer(
            function ($htCapabAsArray) {
                return json_encode($htCapabAsArray, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            },
            function ($htCapabAsString) {
                if (!is_string($htCapabAsString) || '' === trim($htCapabAsString)) {
                    return null;
                }

                return json_decode($htCapabAsString, true, 512, JSON_THROW_ON_ERROR);
            }
        ));
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('name')
            ->add('config_disabled')
            ->add('config_channel')
            ->add('config_hwmode')
            ->add('config_htmode')
            ->add('config_country');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->add('accesspoint', null, ['associated_property' => 'name'])
            ->addIdentifier('name')
            // from the state tree: this level carries the DFS/CAC state, which
            // used to be guessed one level up at the access point
            ->add('state', null, ['label' => 'State'])
            ->add('is_enabled', 'boolean')
            ->add('config_channel', null, ['label' => 'Channel'])
            ->add('config_channels', null, ['label' => 'Channel List'])
            ->add('config_band', null, ['label' => 'Band'])
            ->add('config_hwmode', null, ['label' => 'HW Mode'])
            ->add('config_htmode', null, ['label' => 'HT Mode'])
            ->add('config_txpower', null, ['label' => 'Tx Power'])
            ->add('config_country', null, ['label' => 'Country'])
            ->add('channel')
            ->add('txpower')
            ->add('mode')
            ->add('hw_info');

        // The default actions have to be listed too, see AccessPointAdmin.
        $listMapper->add(ListMapper::NAME_ACTIONS, null, [
            'actions' => [
                'show' => [],
                'edit' => [],
                'delete' => [],
                'radio_status' => [
                    'template' => 'CRUD/list__action_radio_status.html.twig',
                ],
                'radio_neighbors' => [
                    'template' => 'CRUD/list__action_radio_neighbors.html.twig',
                ],
            ],
        ]);
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('accesspoint', null, ['associated_property' => 'name'])
            ->add('name')
            ->add('is_enabled', 'boolean')
            ->add('config_type')
            ->add('config_path')
            ->add('config_disabled')
            ->add('config_channel', null, ['label' => 'Channel'])
            ->add('config_channels', null, ['label' => 'Channel List'])
            ->add('config_band', null, ['label' => 'Band'])
            ->add('config_hwmode', null, ['label' => 'HW Mode'])
            ->add('config_txpower', null, ['label' => 'Tx Power'])
            ->add('config_country', null, ['label' => 'Country'])
            ->add('config_require_mode')
            ->add('config_log_level')
            ->add('config_htmode', null, ['label' => 'HT Mode'])
            ->add('config_noscan')
            ->add('config_beacon_int')
            ->add('config_basic_rate')
            ->add('config_supported_rates')
            ->add('config_rts')
            ->add('config_antenna_gain')
            ->add('config_ht_capab', 'array')
            ->add('channel')
            ->add('txpower')
            ->add('mode')
            ->add('hw_info');
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('radio_status', $this->getRouterIdParameter().'/status');
        $collection->add('radio_neighbors', $this->getRouterIdParameter().'/neighbors');
    }
}
