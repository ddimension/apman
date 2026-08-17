<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;

class SSIDConfigFileAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('ssid');
        $formMapper->add('name');
        $formMapper->add('filename');
        $formMapper->add('content');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('name');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->addIdentifier('ssid', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('filename');
        $listMapper->addIdentifier('content');
    }
}
