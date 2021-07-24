<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class DeviceAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('name', TextType::class);
        $formMapper->add('ifname', TextType::class);
        $formMapper->add('address');
	$formMapper->add('config', TextAreaType::class);

	$formMapper->get('config')->addModelTransformer(new CallbackTransformer(
	    function ($tagsAsArray) {
		//object stdclass json, need to be transform as string for render form
		return json_encode($tagsAsArray, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	    },
	    function ($tagsAsString) { 
		//string, need to be transform as stdClass for json type for persist in DB
		return json_decode($tagsAsString, true, 512, JSON_THROW_ON_ERROR);
	    }
	));
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper->add('radio.accesspoint.name');
        $datagridMapper->add('ssid');
        $datagridMapper->add('name');
        $datagridMapper->add('address');
        $datagridMapper->add('ifname');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('radio.accesspoint.name', null, array('label' => 'Accesspoint'));
        $listMapper->addIdentifier('radio.name');
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('ifname');
        $listMapper->addIdentifier('address');
        $listMapper->addIdentifier('ssid.name');
        $listMapper->addIdentifier('is_enabled','boolean');
        $listMapper->addIdentifier('statistics_transmit','decimal', array('label' => 'Transmit (B)'));
        $listMapper->addIdentifier('statistics_receive', 'decimal', array('label' => 'Receive (B)'));
        $listMapper->addIdentifier('channel');
        $listMapper->addIdentifier('tx_power');
        $listMapper->addIdentifier('hw_mode');
	$listMapper->addIdentifier('clients');
    }
}
