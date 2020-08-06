<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Form\FormMapper;

class SSIDConfigListAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('name');
	$formMapper->add('options', 'sonata_type_collection', array(
                // Prevents the "Delete" option from being displayed
			'type_options' => array('delete' => true)
		    ), array(
			'edit' => 'inline',
			'inline' => 'table',
			'sortable' => 'position',
		    ));

    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper->add('name');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('ssid',null, array('associated_property' => 'name'));
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('options', null, array('associated_property' => 'value'));
    }
}
