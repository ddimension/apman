<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Feature.
 */
#[ORM\Table(name: 'feature')]
#[ORM\Entity]
class Feature
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
    #[ORM\Column(name: 'name', type: 'string', length: 64, nullable: false)]
    private $name;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'implementation', type: 'string', length: 64, nullable: false)]
    private $implementation;

    /**
     * @var array|null
     */
    #[ORM\Column(name: 'config', type: 'json', nullable: false)]
    private $config = [];

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getName();
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return Feature
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set implementation.
     *
     * @param string $implementation
     *
     * @return Feature
     */
    public function setImplementation($implementation)
    {
        $this->implementation = $implementation;

        return $this;
    }

    /**
     * Get implementation.
     *
     * @return string
     */
    public function getImplementation()
    {
        return $this->implementation;
    }

    /**
     * Add config.
     *
     * @return Feature
     */
    public function setConfig(array $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get configOptions.
     *
     * @return \array
     */
    public function getConfig()
    {
        return $this->config;
    }

    // getInstance() used to live here and always returned null: it read
    // $this->instance, and there is no such property — the column is called
    // implementation. Nothing called it. It also implied a constructor that
    // takes the Feature, which is not how the implementations are built.
}
