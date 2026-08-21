<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class PpskAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('ssid');
        $formMapper->add('name', null, ['required' => false, 'help' => 'label, e.g. the device or its owner']);
        $formMapper->add('mac', null, ['help' => '00:00:00:00:00:00 makes the key independent of the mac, which survives mac randomisation']);
        $formMapper->add('psk', null, ['help' => 'passphrase (8..63) or a 64 character hex psk']);
        $formMapper->add('vid', null, ['required' => false, 'help' => 'optional vlan id for this device']);
        $formMapper->add('enabled', null, ['required' => false]);
        $formMapper->add('comment', null, ['required' => false]);
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('ssid');
        $datagridMapper->add('mac');
        $datagridMapper->add('name');
        $datagridMapper->add('source');
        $datagridMapper->add('enabled');
        $datagridMapper->add('lastSeen');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->add('ssid', null, ['associated_property' => 'name']);
        $listMapper->addIdentifier('name');
        $listMapper->add('mac');
        $listMapper->add('vid');
        $listMapper->add('source');
        $listMapper->add('enabled', 'boolean');
        $listMapper->add('created', 'datetime');
        // stamped by PpskService::recordUsed(), which both sources feed: the
        // keyid reports the agents send on the control channel, and the accept
        // events of their own RADIUS servers. On an SAE network the latter is
        // the only trace a use leaves, so neither source alone is complete.
        $listMapper->add('lastSeen', 'datetime', ['label' => 'Last used']);
        $listMapper->add('lastMac', null, ['label' => 'Last device']);
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
            ->add('mac')
            ->add('psk')
            ->add('vid')
            ->add('enabled', 'boolean')
            ->add('source')
            ->add('created', 'datetime')
            ->add('firstSeen', 'datetime', ['label' => 'First used'])
            ->add('lastSeen', 'datetime', ['label' => 'Last used'])
            ->add('lastMac', null, ['label' => 'Last device'])
            ->add('comment');
    }
}
