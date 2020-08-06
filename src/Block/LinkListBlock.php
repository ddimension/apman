<?php

namespace ApManBundle\Block;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Sonata\BlockBundle\Block\Service\AbstractBlockService;
use Sonata\BlockBundle\Model\BlockInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\CoreBundle\Validator\ErrorElement;
use Sonata\BlockBundle\Block\BlockContextInterface;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Doctrine\ORM\EntityManager;
use ApManBundle\Service\AccessPointService;

class LinkListBlock extends AbstractBlockService {
    
    /**
     * @var SecurityContextInterface
     */
    protected $securityContext;
    
	/* (non-PHPdoc)
	 * @see \Sonata\BlockBundle\Block\BaseBlockService::__construct()
	 */
	public function __construct($name, EngineInterface $templating, $securityContext, EntityManager $em, AccessPointService $aps) {
	    parent::__construct($name, $templating);
	    $this->securityContext = $securityContext;
	    $this->em = $em;
	    $this->aps = $aps;
	}

    public function setDefaultSettings(OptionsResolverInterface $resolver) {
    }

    public function validateBlock(ErrorElement $errorElement, BlockInterface $block) {
    }

    public function buildEditForm(FormMapper $form, BlockInterface $block) {
    }
    
    public function execute(BlockContextInterface $blockContext, Response $response = null) {
	$wpsPendingRequests = $this->aps->getPendingWpsPinRequests();
	$msg = '<pre>';
/*
	if (is_array($wpsPendingRequests)) {
		foreach($wpsPendingRequests as $req) {
			$msg.=' '.$req."\n";
		}
	}
*/
	$msg.= '</pre>';
        $template = 'default/sonata-user-block.twig';
        return $this->renderPrivateResponse($template, array(
            'block_context' => $blockContext,
            'block' => $blockContext->getBlock(),
	    'msg' => $msg,
	    'wpsPendingRequests' => $wpsPendingRequests

        ), $response);
    }

}
