<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class DeviceAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
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

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('radio.accesspoint.name');
        $datagridMapper->add('ssid');
        $datagridMapper->add('name');
        $datagridMapper->add('address');
        $datagridMapper->add('ifname');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->add('radio.accesspoint.name', null, ['label' => 'Accesspoint']);
        $listMapper->add('radio.name');
        $listMapper->addIdentifier('name');
        $listMapper->add('ifname');
        $listMapper->add('address');
        $listMapper->add('ssid.name');
        $listMapper->add('is_enabled', 'boolean');
        $listMapper->add('statistics_transmit', 'decimal', ['label' => 'Transmit (B)']);
        $listMapper->add('statistics_receive', 'decimal', ['label' => 'Receive (B)']);
        $listMapper->add('channel');
        $listMapper->add('tx_power');
        $listMapper->add('hw_mode');
        $listMapper->add('clients');
        $listMapper->add(ListMapper::NAME_ACTIONS, null, [
            'actions' => [
                'show' => [],
                'edit' => [],
                'delete' => [],
            ],
        ]);
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('radio.accesspoint.name', null, ['label' => 'Accesspoint'])
            ->add('radio.name')
            ->add('name')
            ->add('ifname')
            ->add('address')
            ->add('ssid.name')
            ->add('is_enabled', 'boolean')
            ->add('statistics_transmit', 'decimal', ['label' => 'Transmit (B)'])
            ->add('statistics_receive', 'decimal', ['label' => 'Receive (B)'])
            ->add('channel')
            ->add('tx_power')
            ->add('hw_mode')
            ->add('clients')
            ->add('config', 'array');
    }
}
