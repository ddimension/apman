<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SSIDConfigListOption.
 */
#[ORM\Table(name: 'ssid_config_list_option')]
#[ORM\Entity]
class SSIDConfigListOption
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'value', type: 'string', nullable: true)]
    private $value;

    /**
     * @var \ApManBundle\Entity\SSIDConfigList
     */
    #[ORM\JoinColumn(name: 'ssid_config_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: \ApManBundle\Entity\SSIDConfigList::class, inversedBy: 'options')]
    private $ssid_config_list;

    /**
     * Set value.
     *
     * @param string $value
     *
     * @return SSIDConfigOption
     */
    /**
     * The list this entry belongs to. The column is not nullable, so an entry
     * without one cannot be written — and there was no way to set it.
     */
    public function setSsidConfigList(?SSIDConfigList $list = null)
    {
        $this->ssid_config_list = $list;

        return $this;
    }

    public function getSsidConfigList()
    {
        return $this->ssid_config_list;
    }

    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value.
     *
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }
}
