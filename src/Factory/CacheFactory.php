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

    /**
     * Hand a command result to whoever is waiting for it.
     *
     * The web request blocks on the list instead of polling the cache, which
     * turns a 250 ms raster into a wake up in milliseconds.
     */
    public function pushResult($host, $id, $data)
    {
        try {
            $redis = $this->getRedisClient();
            $key = 'apman:cmdwait:'.$host.':'.$id;
            $redis->rPush($key, json_encode($data));
            $redis->expire($key, 120);
        } catch (\Throwable $e) {
            $this->logger->debug('pushResult(): '.$e->getMessage());
        }
    }

    /**
     * Wait for whichever of several outstanding answers arrives first.
     *
     * BLPOP takes a list of keys and returns the first one that has data, so a
     * fan out to two dozen bsses waits once for all of them instead of once per
     * bss.
     *
     * @param array $ids id => host
     *
     * @return array|null ['id' => ..., 'data' => [...]]
     */
    public function waitForAnyResult(array $ids, $timeout = 5)
    {
        if (!$ids) {
            return null;
        }
        $keys = [];
        foreach ($ids as $id => $host) {
            $keys['apman:cmdwait:'.$host.':'.$id] = $id;
        }

        // an answer may have landed before we started waiting
        foreach ($ids as $id => $host) {
            $cached = $this->getCacheItemValue('command.result.'.$host.'.'.$id);
            if (is_array($cached)) {
                try {
                    $this->getRedisClient()->del('apman:cmdwait:'.$host.':'.$id);
                } catch (\Throwable $e) {
                }

                return ['id' => $id, 'data' => $cached];
            }
        }

        try {
            $res = $this->getRedisClient()->blPop(array_keys($keys), max(1, (int) ceil($timeout)));
        } catch (\Throwable $e) {
            $this->logger->debug('waitForAnyResult(): '.$e->getMessage());

            return null;
        }
        if (!is_array($res) || !isset($res[0], $res[1])) {
            return null;
        }
        $decoded = json_decode($res[1], true);

        return ['id' => $keys[$res[0]] ?? null, 'data' => is_array($decoded) ? $decoded : null];
    }

    /**
     * @return array|null the result, or null if none arrived in time
     */
    public function waitForResult($host, $id, $timeout = 5)
    {
        try {
            $redis = $this->getRedisClient();
            $res = $redis->blPop(['apman:cmdwait:'.$host.':'.$id], max(1, (int) ceil($timeout)));
            if (is_array($res) && isset($res[1])) {
                $decoded = json_decode($res[1], true);

                return is_array($decoded) ? $decoded : null;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('waitForResult(): '.$e->getMessage());
        }

        // the answer may have arrived before we started waiting
        $cached = $this->getCacheItemValue('command.result.'.$host.'.'.$id);

        return is_array($cached) ? $cached : null;
    }

    private function getRedisClient()
    {
        if (!isset($this->client) || !$this->client) {
            $this->client = $this->getCacheClient();
        }

        return $this->client;
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
