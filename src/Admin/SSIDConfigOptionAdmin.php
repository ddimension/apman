<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;

class SSIDConfigOptionAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('ssid');
        $formMapper->add('name');
        $formMapper->add('value');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper->add('ssid');
        $datagridMapper->add('name');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('ssid', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('value');
    }

    public function prePersist($object)
    {
        //	$object = parent::create($object);
        file_put_contents('/tmp/xxxxw', "bla\n");
        if ($this->isChild()) {
            echo "HOHOHO\n<br>";
            echo 'P:'.$this->getParent()->getId();
        }
        echo "FFOHOHO\n<br>";
    }
}
