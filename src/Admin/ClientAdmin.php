<?php

declare(strict_types=1);

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

final class ClientAdmin extends AbstractAdmin
{
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('mac')
            ->add('name')
            ->add('mode_g')
            ->add('mode_a')
            ->add('airtimeWeight', null, ['label' => 'Airtime weight'])
            ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('mac')
            ->add('name')
            ->add('mode_g')
            ->add('mode_a')
            ->add('airtimeWeight', null, ['label' => 'Airtime weight'])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ]);
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('mac')
            ->add('name')
            ->add('mode_g')
            ->add('mode_a')
            ->add('airtimeWeight', null, [
                'required' => false,
                'label' => 'Airtime weight',
                'help' => '256 is normal, so 512 is twice a normal station\'s share of the medium and '
                    .'128 is half. Not a rate and not a cap: a station alone on a radio gets all of it '
                    .'whatever this says. It only works on a radio with airtime_mode set — without it '
                    .'hostapd accepts the weight, answers success, and the driver value does not move. '
                    .'Leave empty to have no opinion.',
            ])
            ;
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('mac')
            ->add('name')
            ->add('mode_g')
            ->add('mode_a')
            ->add('airtimeWeight', null, ['label' => 'Airtime weight'])
            ;
    }
}
