<?php

namespace ApManBundle\Factory;

use Symfony\Component\Cache\Adapter\RedisAdapter;

class CacheFactory
{
    private $logger;
    private $cache;
    private $client;

    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * get CacheClientInstance.
     *
     * @return Memcached
     */
    private function getCacheClient()
    {
        /*
    $client = MemcachedAdapter::createConnection(
        $_SERVER['MEMCACHE']
    );
         */
        $this->client = RedisAdapter::createConnection(
        'redis://'.$_SERVER['REDIS']
    );

        return $this->client;
    }

    /**
     * get Cache.
     *
     * @return MemcachedAdapter
     */
    public function getCache()
    {
        if (isset($this->cache)) {
            return $this->cache;
        }
        $this->client = $this->getCacheClient();
        $this->cache = new RedisAdapter($this->client, 'apman', 87600);

        return $this->cache;
    }

    public function addCacheItem($key, $data, $expires = null)
    {
        if (is_null($this->cache)) {
            $this->cache = $this->getCache();
        }

        $key = str_replace(':', '', $key);
        $item = $this->cache->getItem($key);
        if (!is_null($expires)) {
            $item->expiresAfter($expires);
        } else {
            $item->expiresAfter(30);
        }
        $item->set($data);
        $this->cache->save($item);
    }

    public function getCacheItemValue($key)
    {
        if (is_null($this->cache)) {
            $this->cache = $this->getCache();
        }

        $key = str_replace(':', '', $key);
        if (is_null($this->cache)) {
            $this->cache = $this->getCache();
        }

        $item = $this->cache->getItem($key);
        $value = $item->get();

        return $value;
    }

    public function getMultipleCacheItemValues($keys)
    {
        if (is_null($this->cache)) {
            $this->cache = $this->getCache();
        }

        $tkeys = [];
        $ti = [];
        foreach ($keys as $key) {
            $nkey = str_replace(':', '', $key);
            $tkeys[] = $nkey;
            $ti[$nkey] = $key;
        }
        $items = $this->cache->getItems($tkeys);
        $res = [];
        foreach ($items as $key => $item) {
            $value = $item->get();
            $res[$ti[$key]] = $value;
        }

        return $res;
    }

    public function deleteCacheItem($key)
    {
        if (is_null($this->cache)) {
            $this->cache = $this->getCache();
        }

        $key = str_replace(':', '', $key);
        $this->cache->deleteItem($key);

        return;
    }
}
