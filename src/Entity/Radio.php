<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Radio
 *
 * @ORM\Table(name="radio")
 * @ORM\Entity
 */
class Radio extends \ApManBundle\DynamicEntity\Radio
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
     * @ORM\Column(name="name", type="string", nullable=true)
     */
    private $name;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_type", type="string", length=64, nullable=true)
     */
    private $config_type;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_path", type="string", length=64, nullable=true)
     */
    private $config_path;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_disabled", type="string", length=1, nullable=true)
     */
    private $config_disabled;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_channel", type="string", length=4, nullable=true)
     */
    private $config_channel;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_channel_list", type="string", length=64, nullable=true)
     */
    private $config_channel_list;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_hwmode", type="string", length=64, nullable=true)
     */
    private $config_hwmode;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_txpower", type="string", length=64, nullable=true)
     */
    private $config_txpower;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_country", type="string", length=64, nullable=true)
     */
    private $config_country;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_require_mode", type="string", length=64, nullable=true)
     */
    private $config_require_mode;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_log_level", type="string", length=64, nullable=true)
     */
    private $config_log_level;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_htmode", type="string", length=64, nullable=true)
     */
    private $config_htmode;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_noscan", type="string", length=64, nullable=true)
     */
    private $config_noscan;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_beacon_int", type="string", length=64, nullable=true)
     */
    private $config_beacon_int;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_basic_rate", type="string", length=64, nullable=true)
     */
    private $config_basic_rate;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_supported_rates", type="string", length=64, nullable=true)
     */
    private $config_supported_rates;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_rts", type="string", length=64, nullable=true)
     */
    private $config_rts;

    /**
     * @var string|null
     *
     * @ORM\Column(name="config_antenna_gain", type="string", length=64, nullable=true)
     */
    private $config_antenna_gain;

    /**
     * @var array|null
     *
     * @ORM\Column(name="config_ht_capab", type="array", nullable=true)
     */
    private $config_ht_capab;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ApManBundle\Entity\Device", mappedBy="radio", cascade={"persist"})
     */
    private $devices;

    /**
     * @var \ApManBundle\Entity\AccessPoint
     *
     * @ORM\ManyToOne(targetEntity="ApManBundle\Entity\AccessPoint", inversedBy="radios")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="accesspoint_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    private $accesspoint;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->devices = new \Doctrine\Common\Collections\ArrayCollection();
    }

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
     * @return Radio
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
     * Add device
     *
     * @param \ApManBundle\Entity\Device $device
     *
     * @return Radio
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

    /**
     * Set accesspoint
     *
     * @param \ApManBundle\Entity\AccessPoint $accesspoint
     *
     * @return Radio
     */
    public function setAccesspoint(\ApManBundle\Entity\AccessPoint $accesspoint)
    {
        $this->accesspoint = $accesspoint;

        return $this;
    }

    /**
     * Get accesspoint
     *
     * @return \ApManBundle\Entity\AccessPoint
     */
    public function getAccesspoint()
    {
        return $this->accesspoint;
    }

    /**
     * Set configType
     *
     * @param string $configType
     *
     * @return Radio
     */
    public function setConfigType($configType)
    {
        $this->config_type = $configType;

        return $this;
    }

    /**
     * Get configType
     *
     * @return string
     */
    public function getConfigType()
    {
        return $this->config_type;
    }

    /**
     * Set configPath
     *
     * @param string $configPath
     *
     * @return Radio
     */
    public function setConfigPath($configPath)
    {
        $this->config_path = $configPath;

        return $this;
    }

    /**
     * Get configPath
     *
     * @return string
     */
    public function getConfigPath()
    {
        return $this->config_path;
    }

    /**
     * Set configDisabled
     *
     * @param string $configDisabled
     *
     * @return Radio
     */
    public function setConfigDisabled($configDisabled)
    {
        $this->config_disabled = $configDisabled;

        return $this;
    }

    /**
     * Get configDisabled
     *
     * @return string
     */
    public function getConfigDisabled()
    {
        return $this->config_disabled;
    }

    /**
     * Set configChannel
     *
     * @param string $configChannel
     *
     * @return Radio
     */
    public function setConfigChannel($configChannel)
    {
        $this->config_channel = $configChannel;

        return $this;
    }

    /**
     * Get configChannel
     *
     * @return string
     */
    public function getConfigChannel()
    {
        return $this->config_channel;
    }

    /**
     * Set configHwmode
     *
     * @param string $configHwmode
     *
     * @return Radio
     */
    public function setConfigHwmode($configHwmode)
    {
        $this->config_hwmode = $configHwmode;

        return $this;
    }

    /**
     * Get configHwmode
     *
     * @return string
     */
    public function getConfigHwmode()
    {
        return $this->config_hwmode;
    }

    /**
     * Set configTxpower
     *
     * @param string $configTxpower
     *
     * @return Radio
     */
    public function setConfigTxpower($configTxpower)
    {
        $this->config_txpower = $configTxpower;

        return $this;
    }

    /**
     * Get configTxpower
     *
     * @return string
     */
    public function getConfigTxpower()
    {
        return $this->config_txpower;
    }

    /**
     * Set configCountry
     *
     * @param string $configCountry
     *
     * @return Radio
     */
    public function setConfigCountry($configCountry)
    {
        $this->config_country = $configCountry;

        return $this;
    }

    /**
     * Get configCountry
     *
     * @return string
     */
    public function getConfigCountry()
    {
        return $this->config_country;
    }

    /**
     * Set configRequireMode
     *
     * @param string $configRequireMode
     *
     * @return Radio
     */
    public function setConfigRequireMode($configRequireMode)
    {
        $this->config_require_mode = $configRequireMode;

        return $this;
    }

    /**
     * Get configRequireMode
     *
     * @return string
     */
    public function getConfigRequireMode()
    {
        return $this->config_require_mode;
    }

    /**
     * Set configLogLevel
     *
     * @param string $configLogLevel
     *
     * @return Radio
     */
    public function setConfigLogLevel($configLogLevel)
    {
        $this->config_log_level = $configLogLevel;

        return $this;
    }

    /**
     * Get configLogLevel
     *
     * @return string
     */
    public function getConfigLogLevel()
    {
        return $this->config_log_level;
    }

    /**
     * Set configHtmode
     *
     * @param string $configHtmode
     *
     * @return Radio
     */
    public function setConfigHtmode($configHtmode)
    {
        $this->config_htmode = $configHtmode;

        return $this;
    }

    /**
     * Get configHtmode
     *
     * @return string
     */
    public function getConfigHtmode()
    {
        return $this->config_htmode;
    }

    /**
     * Set configNoscan
     *
     * @param string $configNoscan
     *
     * @return Radio
     */
    public function setConfigNoscan($configNoscan)
    {
        $this->config_noscan = $configNoscan;

        return $this;
    }

    /**
     * Get configNoscan
     *
     * @return string
     */
    public function getConfigNoscan()
    {
        return $this->config_noscan;
    }

    /**
     * Set configBeaconInt
     *
     * @param string $configBeaconInt
     *
     * @return Radio
     */
    public function setConfigBeaconInt($configBeaconInt)
    {
        $this->config_beacon_int = $configBeaconInt;

        return $this;
    }

    /**
     * Get configBeaconInt
     *
     * @return string
     */
    public function getConfigBeaconInt()
    {
        return $this->config_beacon_int;
    }

    /**
     * Set configBasicRate
     *
     * @param string $configBasicRate
     *
     * @return Radio
     */
    public function setConfigBasicRate($configBasicRate)
    {
        $this->config_basic_rate = $configBasicRate;

        return $this;
    }

    /**
     * Get configBasicRate
     *
     * @return string
     */
    public function getConfigBasicRate()
    {
        return $this->config_basic_rate;
    }

    /**
     * Set configSupportedRates
     *
     * @param string $configSupportedRates
     *
     * @return Radio
     */
    public function setConfigSupportedRates($configSupportedRates)
    {
        $this->config_supported_rates = $configSupportedRates;

        return $this;
    }

    /**
     * Get configSupportedRates
     *
     * @return string
     */
    public function getConfigSupportedRates()
    {
        return $this->config_supported_rates;
    }

    /**
     * Set configRts
     *
     * @param string $configRts
     *
     * @return Radio
     */
    public function setConfigRts($configRts)
    {
        $this->config_rts = $configRts;

        return $this;
    }

    /**
     * Get configRts
     *
     * @return string
     */
    public function getConfigRts()
    {
        return $this->config_rts;
    }

    /**
     * Set configAntennaGain
     *
     * @param string $configAntennaGain
     *
     * @return Radio
     */
    public function setConfigAntennaGain($configAntennaGain)
    {
        $this->config_antenna_gain = $configAntennaGain;

        return $this;
    }

    /**
     * Get configAntennaGain
     *
     * @return string
     */
    public function getConfigAntennaGain()
    {
        return $this->config_antenna_gain;
    }

    /**
     * Set configHtCapab
     *
     * @param array $configHtCapab
     *
     * @return Radio
     */
    public function setConfigHtCapab($configHtCapab)
    {
        foreach ($configHtCapab as $key => $value) {
		if (trim($value) == '') 
			unset($configHtCapab[$key]);
	}
        $this->config_ht_capab = $configHtCapab;

        return $this;
    }

    /**
     * Get configHtCapab
     *
     * @return array
     */
    public function getConfigHtCapab()
    {
	$config = $this->config_ht_capab;
	$config[] = '';
        return $config;
    }

    /**
     * Set configChannelList
     *
     * @param string $configChannelList
     *
     * @return Radio
     */
    public function setConfigChannelList($configChannelList)
    {
        $this->config_channel_list = $configChannelList;

        return $this;
    }

    /**
     * Get configChannelList
     *
     * @return string
     */
    public function getConfigChannelList()
    {
        return $this->config_channel_list;
    }

    /**
     * Import config
     *
     * @param object $config
     * @return boolean
     */
    public function importConfig($config) {
        $cfgVars = get_class_vars(get_class($this));
	foreach ($cfgVars as $key => $value) {
		$cfgVar = 'config_'.$key;
		if (substr($key,0,7) == 'config_') {
			if (gettype($this->$key) == 'array') {
				$this->$key = array();
			} else {
				$this->$key = null;
			}
		} else {
			unset($cfgVars[$key]);
		}
	}
	foreach ((array)$config as $key => $value) {
		$cfgVar = 'config_'.$key;
		if (is_array($value) || is_object($value)) {
			$this->$cfgVar = (array)$value;
		} else {
			$this->$cfgVar = $value;
		}
	}
	return true;
    }	    

    /**
     * Export config
     *
     * @return object
     */
    public function exportConfig() {
	$cfgVars = get_class_vars(get_class($this));
        $res = new \stdClass();
	foreach ($cfgVars as $key => $value) {
		if (substr($key,0,7) != 'config_') continue;
		$cfgVar = substr($key,7);
		$res->$cfgVar = $this->$key;
	}
	return $res;
    }
    
    /**
     * Get fullname
     *
     * @return string
     */
    public function getFullName()
    {
        return $this->getAccessPoint()->getName().' '.$this->getName();
    }

    /**
     * get IsEnabled
     * @return \boolean
     */
    public function getIsEnabled()
    {
	return intval($this->getConfigDisabled())<1;
    }
}
