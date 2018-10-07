<?php

namespace ApManBundle\Entity;

use Symfony\Component\Cache\Simple\FilesystemCache;

/**
 * AccessPoint
 */
class AccessPoint
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
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $ubus_url;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radios;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->radios = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function __toString()
    {
        if ($this->getName()) {
		return $this->getName();
	}
	return '-';
    }

    /**
     * dfg
     */
    public function getSession()
    {
	$cache = new FilesystemCache();
        $key = 'session_'.$this->getName(); 
	if ($cache->has($key)) {
		return $cache->get($key);
	}
	$session = \ApManBundle\Library\wrtJsonRpc::login($this->getUbusUrl(), $this->getUsername(), $this->getPassword());
	if (!$session) {
		return false;
	}
	$cache->set($key, $session, $session->getExpires()-1);
	return $session;
    }

    /**
     * get model
     */
    public function getModel()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->model;
    }
    /**
     * get model
     */
    public function getSystem()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->system;
    }
    /**
     * get model
     */
    public function getKernel()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->kernel;
    }

    /**
     * get model
     */
    public function getCodeName()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','board', null, 10);
	if (!$data || !isset($data->model)) return '-';
	return $data->release->version;
    }

    /**
     * get Uptime
     * @return \DateTime
     */
    public function getUptime()
    {
        if (!($session = $this->getSession())) {
		$date = new \DateTime();
		$date->setTimestamp(0);
		return $date;
	}
	$data = $session->callCached('system','info', null, 10);
	if (!$data || !isset($data->uptime)) return '0';
	$date = new \DateTime();
	$date->setTimestamp(time()-$data->uptime);
	return $date;

	#return $data->uptime;
    }

    /**
     * get info
     * @return \string
     */
    public function getLoad()
    {
        if (!($session = $this->getSession())) {
		return '-';
	}
	$data = $session->callCached('system','info', null, 10);
	if (!$data || !isset($data->load)) return '0';
	$d = array();
	foreach ($data->load as $load) {
		$d[] = sprintf('%0.02f', $load/100000);
	}
	return join(', ', $d);
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
     * @return AccessPoint
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
     * Set username
     *
     * @param string $username
     *
     * @return AccessPoint
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get username
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set password
     *
     * @param string $password
     *
     * @return AccessPoint
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get password
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set ubusUrl
     *
     * @param string $ubusUrl
     *
     * @return AccessPoint
     */
    public function setUbusUrl($ubusUrl)
    {
        $this->ubus_url = $ubusUrl;

        return $this;
    }

    /**
     * Get ubusUrl
     *
     * @return string
     */
    public function getUbusUrl()
    {
        return $this->ubus_url;
    }

    /**
     * Add radio
     *
     * @param \ApManBundle\Entity\Radio $radio
     *
     * @return AccessPoint
     */
    public function addRadio(\ApManBundle\Entity\Radio $radio)
    {
        $this->radios[] = $radio;

        return $this;
    }

    /**
     * Remove radio
     *
     * @param \ApManBundle\Entity\Radio $radio
     */
    public function removeRadio(\ApManBundle\Entity\Radio $radio)
    {
        $this->radios->removeElement($radio);
    }

    /**
     * Get radios
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRadios()
    {
        return $this->radios;
    }
    /**
     * @var string
     */
    private $ipv4;


    /**
     * Set ipv4
     *
     * @param string $ipv4
     *
     * @return AccessPoint
     */
    public function setIpv4($ipv4)
    {
        $this->ipv4 = $ipv4;

        return $this;
    }

    /**
     * Get ipv4
     *
     * @return string
     */
    public function getIpv4()
    {
        return $this->ipv4;
    }
}
