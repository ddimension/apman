<?php

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollection;

class AccessPointAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper->add('name', 'text')
            ->add('username', 'text')
            ->add('password', 'text')
            ->add('ubus_url', 'url')
            ->add('ipv4', 'text');
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper->add('name');
        $datagridMapper->add('username');
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper->addIdentifier('name');
        $listMapper->addIdentifier('ipv4');
        $listMapper->addIdentifier('model');
        $listMapper->addIdentifier('system');
        $listMapper->addIdentifier('codename');
        $listMapper->addIdentifier('kernel');
        $listMapper->addIdentifier('uptime', 'datetime');
        $listMapper->addIdentifier('load');
	$listMapper->addIdentifier('_action', null, array(
		'actions' => array(
			'syslog' => array(
				'template' => 'ApManBundle:CRUD:list__action_syslog.html.twig'
			),
			'login' => array(
				'template' => 'ApManBundle:CRUD:list__action_login.html.twig'
			)
		)
	));
    }

    public function getBatchActions() {
        $actions = parent::getBatchActions();

#        if ($this->hasRoute('print') && $this->isGranted('VIEW')) {
            $actions['configure_and_restart'] = array('label' => 'Stop, Configure and Start', 'ask_confirmation' => true);
            $actions['configure'] = array('label' => 'Configure', 'ask_confirmation' => true);
            $actions['stop_radio'] = array('label' => 'Stop Radio', 'ask_confirmation' => true);
            $actions['start_radio'] = array('label' => 'Start Radio', 'ask_confirmation' => true);
            $actions['wifi_restart'] = array('label' => 'WiFi Restart', 'ask_confirmation' => true);
            $actions['refresh_radios'] = array('label' => 'Wifi Radio Config refresh from AP', 'ask_confirmation' => true);
            $actions['reboot'] = array('label' => 'Reboot', 'ask_confirmation' => true);
 #       }

        return $actions;
    }

    protected function configureRoutes(RouteCollection $collection) {
	    $collection->add('syslog', $this->getRouterIdParameter().'/syslog');
	    $collection->add('login', $this->getRouterIdParameter().'/login');
    }
}

