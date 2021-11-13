<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;

class SSIDConfigListOptionAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('value');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        //       $datagridMapper->add('ssid_config_list');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('ssid_config_list', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('value');
    }
}
