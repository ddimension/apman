<?php

namespace ApManBundle\Factory;

use PhpMqtt\Client\MqttClient;

class MqttFactory
{
    private $logger;
    private $client;

    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getClient($id = null, $cleanSession = null)
    {
        if (isset($this->client)) {
            $this->logger->info('Reusing Mqtt client');

            return $this->client;
        }
        $this->logger->info('Starting Mqtt client');
        if (empty($id)) {
            //$id = 'apmanwebclient';
        }
        if (is_null($cleanSession)) {
            $cleanSession = true;
        }
        $m = $this;
        /*
            $this->client = new \Mosquitto\Client($id, $cleanSession);
            $this->client->onLog(function ($level, $string) use ($m) {
                $c = get_class($m);
                switch ($level) {
                case \Mosquitto\Client::LOG_DEBUG:
                    $m->logger->debug($c.': '.$string);
                    break;
                case \Mosquitto\Client::LOG_INFO:
                    $m->logger->info($c.': '.$string);
                    break;
                case \Mosquitto\Client::LOG_NOTICE:
                    $m->logger->notice($c.': '.$string);
                    break;
                case \Mosquitto\Client::LOG_WARNING:
                    $m->logger->warning($c.': '.$string);
                    break;
                case \Mosquitto\Client::LOG_ERR:
                    $m->logger->error($c.': '.$string);
                    break;
                default:
                    $m->logger->debug($c.': '.$string);
                    break;
            }
        });
        */
        if (empty($_SERVER['MQTT_PORT'])) {
            $_SERVER['MQTT_PORT'] = 1883;
        }
        $this->client = new MqttClient(
        $_SERVER['MQTT_HOST'],
        $_SERVER['MQTT_PORT'],
        $id,
        \PhpMqtt\Client\MqttClient::MQTT_3_1,
        new \PhpMqtt\Client\Repositories\MemoryRepository(),
        $this->logger);
        $connectionSettings = new \PhpMqtt\Client\ConnectionSettings();
        if (!empty($_SERVER['MQTT_USERNAME']) and !empty($_SERVER['MQTT_PASSWORD'])) {
            $connectionSettings = $connectionSettings->setUsername($_SERVER['MQTT_USERNAME']);
            $connectionSettings = $connectionSettings->setPassword($_SERVER['MQTT_PASSWORD']);
        }
        $status = $this->client->connect($connectionSettings, true);
        $this->logger->info('Connected');

        return $this->client;
    }

    public function getClientMosquitto($id = null, $cleanSession = null)
    {
        if (isset($this->client)) {
            $this->logger->info('Reusing Mqtt client');

            return $this->client;
        }
        $this->logger->info('Starting Mqtt client');
        if (empty($id)) {
            //$id = 'apmanwebclient';
        }
        if (is_null($cleanSession)) {
            $cleanSession = true;
        }
        $m = $this;
        $this->client = new \Mosquitto\Client($id, $cleanSession);
        $this->client->onLog(function ($level, $string) use ($m) {
            $c = get_class($m);
            switch ($level) {
            case \Mosquitto\Client::LOG_DEBUG:
                $m->logger->debug($c.': '.$string);
                break;
            case \Mosquitto\Client::LOG_INFO:
                $m->logger->info($c.': '.$string);
                break;
            case \Mosquitto\Client::LOG_NOTICE:
                $m->logger->notice($c.': '.$string);
                break;
            case \Mosquitto\Client::LOG_WARNING:
                $m->logger->warning($c.': '.$string);
                break;
            case \Mosquitto\Client::LOG_ERR:
                $m->logger->error($c.': '.$string);
                break;
            default:
                $m->logger->debug($c.': '.$string);
                break;
        }
        });
        if (!empty($_SERVER['MQTT_USERNAME']) and !empty($_SERVER['MQTT_PASSWORD'])) {
            $success = $this->client->setCredentials($_SERVER['MQTT_USERNAME'], $_SERVER['MQTT_PASSWORD']);
        }
        if (empty($_SERVER['MQTT_PORT'])) {
            $_SERVER['MQTT_PORT'] = 1883;
        }
        $success = $this->client->connect($_SERVER['MQTT_HOST'], $_SERVER['MQTT_PORT']);
        if ($success) {
            $this->logger->info('Failed to connected.');

            return null;
        }
        $this->logger->info('Connected');

        return $this->client;
    }
}
