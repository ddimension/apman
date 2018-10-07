<?php

namespace ApManBundle\Entity;

/**
 * SSID
 */
class SSID
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /**
     * Constructor
     */
    public function __construct()
    {
    }

    /**
     * @return string
     */
    public function __toString() {
	    return (string)$this->getName();
    }	    

    /**
     * Import Config
     *
     * @param $doctrine
     * @param \Object $config
     * @return
     */
    public function importConfig($doctrine , $config) {
	    $foundKeys = array();
	    $em = $doctrine->getEntityManager();

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
	    $query->setParameter('names', array_keys((array)$config));
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
	    $query->setParameter('names', array_keys((array)$config));
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
	    $query->setParameter('names', array_keys((array)$config));
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
	    $query->setParameter('names', array_keys((array)$config));
	    $query->getResult();

	    // New Keys
	    foreach ((array)$config as $cfgEntry => $cfgValue) {
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
     * Export Config
     *
     * @return \Object
     */
    public function exportConfig() {
	    $res = new \stdClass();
	    foreach ($this->config_options as $cfg) {
		    $name = $cfg->getName();
		    if (empty($name)) continue;
		    $res->$name = $cfg->getValue();
	    }
	    foreach ($this->config_lists as $cfg) {
		    $name = $cfg->getName();
		    $res->$name = array();
		    foreach ($cfg->getOptions() as $element) {
		    	$res->$name[] = $element->getValue();
		    }
	    }
	    return $res;
    }

    /**
     * get IsEnabled
     * @return \boolean
     */
    public function getIsEnabled()
    {
	$disabled = '';
	foreach ($this->getConfigOptions() as $configOption) {
		if ($configOption->getName() == 'disabled') {
			$disabled = $configOption->getValue();
			break;
		}	
	}
	if ($disabled == 1) {
		return false;
	}
	return true;
    }

    /**
     * get DeviceCount
     * @return \integer
     */
    public function getDeviceCount()
    {
	return count($this->getDevices());
    }

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $config_options;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $config_lists;


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
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
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Add configOption
     *
     * @param \ApManBundle\Entity\SSIDConfigOption $configOption
     *
     * @return SSID
     */
    public function addConfigOption(\ApManBundle\Entity\SSIDConfigOption $configOption)
    {
        $this->config_options[] = $configOption;

        return $this;
    }

    /**
     * Remove configOption
     *
     * @param \ApManBundle\Entity\SSIDConfigOption $configOption
     */
    public function removeConfigOption(\ApManBundle\Entity\SSIDConfigOption $configOption)
    {
        $this->config_options->removeElement($configOption);
    }

    /**
     * Get configOptions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConfigOptions()
    {
        return $this->config_options;
    }

    /**
     * Add configList
     *
     * @param \ApManBundle\Entity\SSIDConfigList $configList
     *
     * @return SSID
     */
    public function addConfigList(\ApManBundle\Entity\SSIDConfigList $configList)
    {
        $this->config_lists[] = $configList;

        return $this;
    }

    /**
     * Remove configList
     *
     * @param \ApManBundle\Entity\SSIDConfigList $configList
     */
    public function removeConfigList(\ApManBundle\Entity\SSIDConfigList $configList)
    {
        $this->config_lists->removeElement($configList);
    }

    /**
     * Get configLists
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConfigLists()
    {
        return $this->config_lists;
    }
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $config_files;


    /**
     * Add configFile
     *
     * @param \ApManBundle\Entity\SSIDConfigFile $configFile
     *
     * @return SSID
     */
    public function addConfigFile(\ApManBundle\Entity\SSIDConfigFile $configFile)
    {
        $this->config_files[] = $configFile;

        return $this;
    }

    /**
     * Remove configFile
     *
     * @param \ApManBundle\Entity\SSIDConfigFile $configFile
     */
    public function removeConfigFile(\ApManBundle\Entity\SSIDConfigFile $configFile)
    {
        $this->config_files->removeElement($configFile);
    }

    /**
     * Get configFiles
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConfigFiles()
    {
        return $this->config_files;
    }
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $devices;


    /**
     * Add device
     *
     * @param \ApManBundle\Entity\Device $device
     *
     * @return SSID
     */
    public function addDevice(\ApManBundle\Entity\Device $device)
    {
        $this->devices[] = $device;

        return $this;
    }

    /**
     * Remove device
     *
     * @param \ApManBundle\Entity\Device $device
     */
    public function removeDevice(\ApManBundle\Entity\Device $device)
    {
        $this->devices->removeElement($device);
    }

    /**
     * Get devices
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDevices()
    {
        return $this->devices;
    }
}
