<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SSID.
 *
 * @ORM\Table(name="ssid")
 * @ORM\Entity
 */
class SSID
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
     * @ORM\Column(name="name", type="string", length=64, nullable=true)
     */
    private $name;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\SSIDConfigOption", mappedBy="ssid", cascade={"persist"})
     */
    private $config_options;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\SSIDConfigList", mappedBy="ssid", cascade={"persist"})
     */
    private $config_lists;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\SSIDConfigFile", mappedBy="ssid", cascade={"persist"})
     */
    private $config_files;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\Device", mappedBy="ssid", cascade={"persist"})
     */
    private $devices;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\SSIDFeatureMap", mappedBy="ssid", cascade={"persist"})
     */
    private $feature_maps;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->config_options = new \Doctrine\Common\Collections\ArrayCollection();
        $this->config_lists = new \Doctrine\Common\Collections\ArrayCollection();
        $this->config_files = new \Doctrine\Common\Collections\ArrayCollection();
        $this->devices = new \Doctrine\Common\Collections\ArrayCollection();
        $this->feature_maps = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getName();
    }

    public function __clone()
    {
        $this->id = null;
        $this->config_options = new \Doctrine\Common\Collections\ArrayCollection();
        $this->config_lists = new \Doctrine\Common\Collections\ArrayCollection();
        $this->config_files = new \Doctrine\Common\Collections\ArrayCollection();
        $this->devices = new \Doctrine\Common\Collections\ArrayCollection();
        $this->feature_maps = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Import Config.
     *
     * @param $doctrine
     * @param \object $config
     *
     * @return
     */
    public function importConfig($doctrine, $config)
    {
        $foundKeys = [];
        $em = $doctrine->getManager();

        // File

        // Options
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery(
                    'SELECT cfg
                     FROM ApManBundle:SSIDConfigOption cfg
		     WHERE
		     cfg.ssid = :ssid
                     AND cfg.name IN (:names)'
        );
        $query->setParameter('ssid', $this);
        $query->setParameter('names', array_keys((array) $config));
        $configs = $query->getResult();
        foreach ($configs as $cfg) {
            $name = $cfg->getName();
            $cfg->setValue($config->$name);
            $em->persist($cfg);
            $foundKeys[] = $name;
        }
        $query = $em->createQuery(
                    'DELETE
                     FROM ApManBundle:SSIDConfigOption cfg
		     WHERE
                     cfg.ssid = :ssid
                     AND cfg.name NOT IN (:names)'
        );

        $query->setParameter('ssid', $this);
        $query->setParameter('names', array_keys((array) $config));
        $query->getResult();

        // Lists
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery(
                    'SELECT cfg
                     FROM ApManBundle:SSIDConfigList cfg
		     WHERE
                     cfg.ssid = :ssid
                     AND cfg.name IN (:names)'
        );
        $query->setParameter('ssid', $this);
        $query->setParameter('names', array_keys((array) $config));
        $configs = $query->getResult();
        foreach ($configs as $cfg) {
            $query = $em->createQuery(
                'DELETE
			     FROM ApManBundle:SSIDConfigListOption cfg
			     WHERE
			     cfg.ssid_config_list = :ssid_config_list'
            );

            $query->setParameter('ssid_config_list', $cfg);
            $query->getResult();
            $name = $cfg->getName();
            foreach ($config->$name as $val) {
                $entry = new SSIDConfigListOption();
                $entry->setSSIDConfigList($cfg);
                $entry->setValue($val);
                $em->persist($entry);
            }
            $em->persist($cfg);
            $foundKeys[] = $name;
        }
        $query = $em->createQuery(
                'DELETE
			     FROM ApManBundle:SSIDConfigList cfg
			     WHERE
			     cfg.ssid = :ssid
			     AND cfg.name NOT IN (:names)'
            );
        $query->setParameter('ssid', $this);
        $query->setParameter('names', array_keys((array) $config));
        $query->getResult();

        // New Keys
        foreach ((array) $config as $cfgEntry => $cfgValue) {
            if (in_array($cfgEntry, $foundKeys)) {
                continue;
            }
            if (is_array($cfgValue) || is_object($cfgValue)) {
                $cfg = new SSIDConfigList();
                $name = $cfgEntry;
                foreach ($config->$name as $val) {
                    $entry = new SSIDConfigListOption();
                    $entry->setSSIDConfigList($cfg);
                    $entry->setValue($val);
                    $em->persist($entry);
                }
            } else {
                $cfg = new SSIDConfigOption();
                $cfg->setValue($cfgValue);
            }
            $cfg->setSSID($this);
            $cfg->setName($cfgEntry);
            $em->persist($cfg);
        }
        $em->flush();
    }

    /**
     * Export Config.
     *
     * @return \object
     */
    public function exportConfig()
    {
        $res = new \stdClass();
        foreach ($this->getConfigOptions() as $cfg) {
            $name = $cfg->getName();
            if (empty($name)) {
                continue;
            }
            $value = $cfg->getValue();
            if (is_null($value)) {
                $value = '';
            }
            $res->$name = $value;
        }
        foreach ($this->config_lists as $cfg) {
            $name = $cfg->getName();
            $res->$name = [];
            foreach ($cfg->getOptions() as $element) {
                $value = $element->getValue();
                if (is_null($value)) {
                    $value = '';
                }
                $res->$name[] = $value;
            }
        }

        return $res;
    }

    /**
     * get IsEnabled.
     *
     * @return \boolean
     */
    public function getIsEnabled()
    {
        $disabled = '';
        foreach ($this->getConfigOptions() as $configOption) {
            if ('disabled' == $configOption->getName()) {
                $disabled = $configOption->getValue();
                break;
            }
        }
        if (1 == $disabled) {
            return false;
        }

        return true;
    }

    /**
     * get DeviceCount.
     *
     * @return \integer
     */
    public function getDeviceCount()
    {
        return count($this->getDevices());
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
     * @return SSID
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
     * Add configOption.
     *
     * @param \ApManBundle\Entity\SSIDConfigOption $configOption
     *
     * @return SSID
     */
    public function addConfigOption(SSIDConfigOption $configOption)
    {
        $this->config_options[] = $configOption;

        return $this;
    }

    /**
     * Remove configOption.
     *
     * @param \ApManBundle\Entity\SSIDConfigOption $configOption
     */
    public function removeConfigOption(SSIDConfigOption $configOption)
    {
        $this->config_options->removeElement($configOption);
    }

    /**
     * Get configOptions.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConfigOptions()
    {
        return $this->config_options;
    }

    /**
     * Add configList.
     *
     * @param \ApManBundle\Entity\SSIDConfigList $configList
     *
     * @return SSID
     */
    public function addConfigList(SSIDConfigList $configList)
    {
        $this->config_lists[] = $configList;

        return $this;
    }

    /**
     * Remove configList.
     *
     * @param \ApManBundle\Entity\SSIDConfigList $configList
     */
    public function removeConfigList(SSIDConfigList $configList)
    {
        $this->config_lists->removeElement($configList);
    }

    /**
     * Get configLists.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConfigLists()
    {
        return $this->config_lists;
    }

    /**
     * Add configFile.
     *
     * @param \ApManBundle\Entity\SSIDConfigFile $configFile
     *
     * @return SSID
     */
    public function addConfigFile(SSIDConfigFile $configFile)
    {
        $this->config_files[] = $configFile;

        return $this;
    }

    /**
     * Remove configFile.
     *
     * @param \ApManBundle\Entity\SSIDConfigFile $configFile
     */
    public function removeConfigFile(SSIDConfigFile $configFile)
    {
        $this->config_files->removeElement($configFile);
    }

    /**
     * Get configFiles.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConfigFiles()
    {
        return $this->config_files;
    }

    /**
     * Add device.
     *
     * @param \ApManBundle\Entity\Device $device
     *
     * @return SSID
     */
    public function addDevice(Device $device)
    {
        $this->devices[] = $device;

        return $this;
    }

    /**
     * Remove device.
     *
     * @param \ApManBundle\Entity\Device $device
     */
    public function removeDevice(Device $device)
    {
        $this->devices->removeElement($device);
    }

    /**
     * Get devices.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDevices()
    {
        return $this->devices;
    }

    /**
     * Add SSIDFeatureMap.
     *
     * @param \ApManBundle\Entity\SSIDFeatureMap $featuremap
     *
     * @return SSID
     */
    public function addSSIDFeatureMap(SSIDFeatureMap $featuremap)
    {
        $this->feature_maps[] = $featuremap;

        return $this;
    }

    /**
     * Remove SSIDFeatureMap.
     *
     * @param \ApManBundle\Entity\SSIDFeatureMap $feature
     */
    public function removeSSIDFeatureMap(SSIDFeatureMap $feature)
    {
        $this->feature_maps->removeElement($feature);
    }

    /**
     * Get SSIDFeatureMap.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getSSIDFeatureMaps()
    {
        return $this->feature_maps;
    }
}
