<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SSIDConfigListOption
 *
 * @ORM\Table(name="ssid_config_list_option")
 * @ORM\Entity
 */
class SSIDConfigListOption
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string|null
     *
     * @ORM\Column(name="value", type="string", nullable=true)
     */
    private $value;

    /**
     * @var \ApManBundle\Entity\SSIDConfigList
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\SSIDConfigList", inversedBy="options")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="ssid_config_list_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $ssid_config_list;


}
