<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\CollectionType;

class SSIDConfigListAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('name');
        $formMapper->add('options', CollectionType::class, [
                // Prevents the "Delete" option from being displayed
            'type_options' => ['delete' => true],
            ], [
            'edit' => 'inline',
            'inline' => 'table',
            'sortable' => 'position',
            ]);
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('name');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->add('ssid', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('name');
        $listMapper->add('options', null, ['associated_property' => 'value']);
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
            ->add('name');
    }
}
