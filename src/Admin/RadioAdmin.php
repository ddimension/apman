<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Symfony\Component\Form\Extension\Core\Type\TextType;


class RadioAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('name', TextType::class);
        $formMapper->add('config_type');
        $formMapper->add('config_path');
        $formMapper->add('config_disabled');
        $formMapper->add('config_channel');
        $formMapper->add('config_channel_list');
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
//	$formMapper->add('config_ht_capab', 'array');
		->add('config_ht_capab');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
	$datagridMapper->add('name')
	->add('config_disabled')
	->add('config_channel')
	->add('config_hwmode')
	->add('config_htmode')
	->add('config_country');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('accesspoint',null, array('associated_property' => 'name'))
        ->addIdentifier('name')
        ->addIdentifier('is_enabled', 'boolean')
	->addIdentifier('config_channel', null, array('label' => 'Channel'))
	->addIdentifier('config_band', null, array('label' => 'Band'))
	->addIdentifier('config_hwmode', null, array('label' => 'HW Mode'))
	->addIdentifier('config_htmode', null, array('label' => 'HT Mode'))
	->addIdentifier('config_txpower', null, array('label' => 'Tx Power'))
	->addIdentifier('config_country', null, array('label' => 'Country'))
        ->addIdentifier('channel')
        ->addIdentifier('txpower')
        ->addIdentifier('mode')
        ->addIdentifier('hw_info');
	$listMapper->addIdentifier('_action', null, array(
		'actions' => array(
			'radio_status' => array(
				'template' => 'CRUD/list__action_radio_status.html.twig'
			),
			'radio_neighbors' => array(
				'template' => 'CRUD/list__action_radio_neighbors.html.twig'
			)
		)
	));
    }

    protected function configureRoutes(RouteCollection $collection) {
	    $collection->add('radio_status', $this->getRouterIdParameter().'/status');
	    $collection->add('radio_neighbors', $this->getRouterIdParameter().'/neighbors');
    }
}
