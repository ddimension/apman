<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class SSIDConfigOptionAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('ssid');
        $formMapper->add('name');
        $formMapper->add('value');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('ssid');
        $datagridMapper->add('name');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->add('ssid', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('name');
        $listMapper->add('value');
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
            ->add('ssid', null, ['associated_property' => 'name'])
            ->add('name')
            ->add('value');
    }

    public function prePersist(object $object): void
    {
    }
}
