<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Symfony\Component\Form\Extension\Core\Type\TextType;


class DeviceAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('name', TextType::class);
        $formMapper->add('ifname', TextType::class);
        $formMapper->add('config');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper->add('radio.accesspoint.name');
        $datagridMapper->add('ssid');
        $datagridMapper->add('name');
        $datagridMapper->add('ifname');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('radio.accesspoint.name', null, array('label' => 'Accesspoint'));
        $listMapper->addIdentifier('radio.name');
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('ifname');
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
