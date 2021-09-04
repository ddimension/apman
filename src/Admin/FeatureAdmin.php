<?php

declare(strict_types=1);

namespace ApManBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

final class FeatureAdmin extends AbstractAdmin
{

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('name')
            ->add('implementation')
            ->add('config')
            ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('name')
            ->add('implementation')
            ->add('config')
            ->add('_action', null, [
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
            ->add('name')
	    ->add('implementation')
    	    ->add('config', TextAreaType::class);
	    ;
	$formMapper->get('config')->addModelTransformer(new CallbackTransformer(
	    function ($tagsAsArray) {
		//object stdclass json, need to be transform as string for render form
		return json_encode($tagsAsArray, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	    },
	    function ($tagsAsString) {
		//string, need to be transform as stdClass for json type for persist in DB
		return json_decode($tagsAsString, true, 512, JSON_THROW_ON_ERROR);
	    }
	));

    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('name')
            ->add('implementation')
            ->add('config')
            ;
    }
}
