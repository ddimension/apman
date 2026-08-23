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
            ->add('blockedUntil', null, ['label' => 'Blocked until'])
            ->add('blockedReason', null, ['label' => 'Why'])
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
            ->add('blockedUntil', null, ['label' => 'Blocked until'])
            ->add('blockedReason', null, ['label' => 'Why'])
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
            ->add('blockedUntil', null, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'Blocked until',
                'help' => 'A station with a date in the future here is thrown off every bss it is on '
                    .'and thrown off again whenever it manages to associate. It is on for a moment '
                    .'each time: hostapd bans per bss, in memory, with an end, so the block is kept '
                    .'alive by the controller rather than held by the access point. Empty means '
                    .'welcome.',
            ])
            ->add('blockedReason', null, [
                'required' => false,
                'label' => 'Why',
                'help' => 'So that whoever finds this in three months knows whether it may be undone.',
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
            ->add('blockedUntil', null, ['label' => 'Blocked until'])
            ->add('blockedReason', null, ['label' => 'Why'])
            ;
    }
}
