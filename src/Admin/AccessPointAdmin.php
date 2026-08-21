<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class AccessPointAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('name', TextType::class)
            ->add('username', TextType::class)
            ->add('password', TextType::class)
            ->add('ubus_url', UrlType::class)
            ->add('ipv4', TextType::class)
            ->add('ProvisioningEnabled')
            ->add('IsProductive');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper->add('name');
        $datagridMapper->add('username');
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper->addIdentifier('name');
        $listMapper->add('ipv4');
        $listMapper->add('model');
        $listMapper->add('system');
        $listMapper->add('codename');
        $listMapper->add('kernel');
        $listMapper->add('uptime', 'datetime');
        $listMapper->add('ProvisioningEnabled', 'boolean');
        $listMapper->add('IsProductive', 'boolean');
        $listMapper->add('load');
        $listMapper->add('state');
        // what the tree makes of the same access point, from its radios and
        // their bsses. Sits next to the flat state while the two are compared.
        $listMapper->add('treeState', null, ['label' => 'Tree']);

        // The default actions have to be listed too: passing an 'actions'
        // option replaces them, and without show/edit/delete an access point
        // cannot be viewed or changed from the list at all.
        $listMapper->add(ListMapper::NAME_ACTIONS, null, [
            'actions' => [
                'show' => [],
                'edit' => [],
                'delete' => [],
                'syslog' => [
                    'template' => 'CRUD/list__action_syslog.html.twig',
                ],
                'login' => [
                    'template' => 'CRUD/list__action_login.html.twig',
                ],
                'lldp' => [
                    'template' => 'CRUD/list__action_lldp.html.twig',
                ],
            ],
        ]);
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('name')
            ->add('ipv4')
            ->add('ubus_url', 'url')
            ->add('username')
            ->add('model')
            ->add('system')
            ->add('codename')
            ->add('kernel')
            ->add('uptime', 'datetime')
            ->add('load')
            ->add('state')
            ->add('treeState', null, ['label' => 'Tree state'])
            ->add('ProvisioningEnabled', 'boolean')
            ->add('IsProductive', 'boolean');
    }

    protected function configureBatchActions(array $actions): array
    {
        $actions['configure_and_restart'] = ['label' => 'Stop, Configure and Reboot', 'ask_confirmation' => true];
        $actions['configure'] = ['label' => 'Configure', 'ask_confirmation' => true];
        $actions['stop_radio'] = ['label' => 'Stop Radio', 'ask_confirmation' => true];
        $actions['start_radio'] = ['label' => 'Start Radio', 'ask_confirmation' => true];
        $actions['wifi_restart'] = ['label' => 'WiFi Restart', 'ask_confirmation' => true];
        $actions['refresh_radios'] = ['label' => 'Wifi Radio Config refresh from AP', 'ask_confirmation' => true];
        $actions['reboot'] = ['label' => 'Reboot', 'ask_confirmation' => true];

        return $actions;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('syslog', $this->getRouterIdParameter().'/syslog');
        $collection->add('login', $this->getRouterIdParameter().'/login');
        $collection->add('lldp', $this->getRouterIdParameter().'/lldp');
    }
}
