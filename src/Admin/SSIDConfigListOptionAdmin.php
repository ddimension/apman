<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class SSIDConfigListOptionAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('value');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        //       $datagridMapper->add('ssid_config_list');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->add('ssid_config_list', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('value');
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
            ->add('ssid_config_list', null, ['associated_property' => 'name'])
            ->add('value');
    }
}
