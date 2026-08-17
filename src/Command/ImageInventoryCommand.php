<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Collect everything an image build needs to know about the fleet — over MQTT,
 * not over ssh and not out of the database.
 *
 * The agent already publishes `properties/system/board` retained, so the board
 * name, the target and the running release arrive within milliseconds of
 * subscribing, for every access point that is online, without waking any of
 * them up. Devices that are switched off are simply not in the inventory, which
 * is the correct outcome: we do not want to build an image for a device whose
 * board we would have to guess.
 *
 * Two things are not retained anywhere and are fetched through the command
 * channel when asked for: the apman uci config (--with-config) and the list of
 * explicitly installed packages (--with-packages).
 */
class ImageInventoryCommand extends Command
{
    protected static $defaultName = 'apman:image-inventory';

    /** ubus status 4, "not found" — the file does not exist on this device */
    private const UBUS_NOT_FOUND = 4;

    private $logger;
    private $mqttFactory;
    private $rpcService;

    private $devices = [];
    private $pending = [];

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \ApManBundle\Service\wrtJsonRpc $rpcService,
        $name = null
    ) {
        parent::__construct($name);
        $this->logger = $logger;
        $this->mqttFactory = $mqttFactory;
        $this->rpcService = $rpcService;
    }

    protected function configure(): void
    {
        $this
            ->setName('apman:image-inventory')
            ->setDescription('Collect a device inventory for image builds over mqtt')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED,
                'Write the inventory here instead of to stdout')
            // one status interval is 10 s, and a device only counts as present
            // when it publishes one, so the default has to be longer than that
            ->addOption('wait', 'w', InputOption::VALUE_REQUIRED,
                'Seconds to collect properties and status', 12)
            ->addOption('include-stale', null, InputOption::VALUE_NONE,
                'Also list devices that only exist as a retained property')
            ->addOption('ap', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only this access point, repeatable')
            ->addOption('with-config', null, InputOption::VALUE_NONE,
                'Also fetch the apman uci config of every device')
            ->addOption('with-packages', null, InputOption::VALUE_NONE,
                'Also fetch the explicitly installed packages of every device')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED,
                'Seconds to wait for command answers', 10)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // the json goes to stdout so the build script can just redirect it;
        // everything a human wants to read has to go to stderr or it would end
        // up in the inventory file
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $wait = max(1, (int) $input->getOption('wait'));
        $only = array_map('strtolower', (array) $input->getOption('ap'));

        $client = $this->mqttFactory->getClient('apman-inventory-'.getmypid(), true);
        if (!$client) {
            $err->writeln('<error>no mqtt connection</error>');

            return 1;
        }

        $err->writeln(sprintf('collecting for %d s ...', $wait), OutputInterface::VERBOSITY_VERBOSE);
        // both property topics are retained, so the broker replays the whole
        // fleet at once; the periodic status is the liveness test — see below
        $client->listen([
            'apman/ap/+/properties/system/board' => 1,
            'apman/ap/+/properties/agent' => 1,
            'apman/ap/+/device/hostapd/+/status' => 0,
        ], function (\ApManBundle\Mqtt\Message $message) use ($only) {
            $this->onProperty($message, $only);
        }, $wait);
        $client->disconnect();

        if (!$this->devices) {
            $err->writeln('<error>no device answered — is the agent running and is the topic prefix apman/?</error>');

            return 1;
        }
        ksort($this->devices);

        // Retained properties outlive the device: a renamed or decommissioned
        // access point keeps its board message on the broker forever, and
        // building an image for it would be building for a machine that no
        // longer exists. Only a device that published a status while we were
        // listening is really there.
        $stale = [];
        foreach ($this->devices as $name => $device) {
            if (empty($device['live'])) {
                $stale[] = $name;
            }
        }
        if ($stale && !$input->getOption('include-stale')) {
            foreach ($stale as $name) {
                unset($this->devices[$name]);
            }
            $err->writeln(sprintf(
                '<comment>%d stale retained device(s) skipped: %s</comment>',
                count($stale), implode(', ', $stale)));
        }
        if (!$this->devices) {
            $err->writeln('<error>every device was stale — nothing is publishing status</error>');

            return 1;
        }
        $err->writeln(sprintf('%d device(s) found', count($this->devices)),
            OutputInterface::VERBOSITY_VERBOSE);

        $extra = [];
        if ($input->getOption('with-config')) {
            $extra['apman_config'] = ['uci', 'get', (object) ['config' => 'apman']];
        }
        if ($input->getOption('with-packages')) {
            // /etc/apk/world is exactly the set someone asked for, which is
            // what an image build wants — the full installed list would drag
            // in every dependency the profile brings anyway
            $extra['packages'] = ['file', 'read', (object) ['path' => '/etc/apk/world']];
        }
        foreach ($extra as $key => $call) {
            $this->fetch($client, $key, $call, (int) $input->getOption('timeout'), $err);
        }

        $client->disconnect();

        $inventory = [
            'generated' => date('c'),
            'generated_ts' => time(),
            'source' => 'mqtt://'.($_SERVER['MQTT_HOST'] ?? '?').':'.($_SERVER['MQTT_PORT'] ?? 1883),
            'devices' => array_values($this->devices),
        ];
        $json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $file = $input->getOption('output');
        if (!$file) {
            $output->writeln($json);

            return 0;
        }
        if (false === @file_put_contents($file, $json."\n")) {
            $err->writeln('<error>cannot write '.$file.'</error>');

            return 1;
        }
        $err->writeln(sprintf('<info>%d device(s) written to %s</info>', count($this->devices), $file));

        return 0;
    }

    /**
     * A retained property of one access point.
     */
    private function onProperty($msg, array $only)
    {
        $parts = explode('/', $msg->topic);
        // apman/ap/<name>/properties/... and apman/ap/<name>/device/...
        if (count($parts) < 5 || 'ap' !== $parts[1]) {
            return;
        }
        $name = $parts[2];
        if ($only && !in_array(strtolower($name), $only, true)) {
            return;
        }

        if ('status' === end($parts) && 'device' === $parts[3]) {
            // no need to look inside: that it arrived at all is the signal
            if (!isset($this->devices[$name])) {
                $this->devices[$name] = ['name' => $name];
            }
            $this->devices[$name]['live'] = true;

            return;
        }

        $data = json_decode($msg->payload, true);
        if (!is_array($data)) {
            return;
        }

        if (!isset($this->devices[$name])) {
            $this->devices[$name] = ['name' => $name];
        }
        $device = $this->devices[$name];

        if ('agent' === end($parts)) {
            $device['agent'] = [
                'version' => $data['version'] ?? null,
                'features' => $data['features'] ?? [],
            ];
            $this->devices[$name] = $device;

            return;
        }

        // system/board
        $device['hostname'] = $data['hostname'] ?? null;
        $device['model'] = $data['model'] ?? null;
        $device['board_name'] = $data['board_name'] ?? null;
        // the image builder profile is the board name with the vendor comma
        // turned into an underscore: xiaomi,ax3600 -> xiaomi_ax3600
        $device['profile'] = isset($data['board_name'])
            ? str_replace(',', '_', $data['board_name']) : null;
        $device['rootfs_type'] = $data['rootfs_type'] ?? null;

        $target = $data['release']['target'] ?? '';
        $split = explode('/', $target, 2);
        $device['target'] = $split[0] ?? null;
        $device['subtarget'] = $split[1] ?? null;

        $device['running'] = [
            'distribution' => $data['release']['distribution'] ?? null,
            'version' => $data['release']['version'] ?? null,
            'revision' => $data['release']['revision'] ?? null,
            'description' => $data['release']['description'] ?? null,
            'kernel' => $data['kernel'] ?? null,
        ];
        $device['seen'] = time();
        $this->devices[$name] = $device;
    }

    /**
     * Ask every device one ubus call and fold the answers into the inventory.
     *
     * All requests go out first and the answers are collected as they arrive —
     * asking one device at a time would cost the timeout per device.
     */
    private function fetch($client, $key, array $call, $timeout, OutputInterface $output)
    {
        $this->pending = [];
        $run = bin2hex(random_bytes(3));
        foreach ($this->devices as $name => $device) {
            $id = 'inv-'.$key.'-'.$name.'-'.$run;
            $this->pending[$id] = $name;
        }
        // subscribe first, then ask, then wait — in that order, or an answer
        // that comes back quickly would arrive before anybody listens
        $filters = [];
        foreach ($this->devices as $name => $device) {
            $filters['apman/ap/'.$name.'/command_result/#'] = 1;
        }
        $client->subscribe($filters, function (\ApManBundle\Mqtt\Message $message) use ($key) {
            $this->onAnswer($message, $key);
        });
        foreach ($this->pending as $id => $name) {
            $cmd = $this->rpcService->createRpcRequest($id, 'call', null, $call[0], $call[1], $call[2]);
            $client->publish('apman/ap/'.$name.'/command', json_encode($cmd), 1);
        }
        $client->wait(max(1, (int) $timeout), function () {
            return !$this->pending;
        });
        if ($this->pending) {
            $output->writeln(sprintf('<comment>%s: no answer from %s</comment>',
                $key, implode(', ', array_values($this->pending))));
        }
    }

    private function onAnswer($msg, $key)
    {
        $data = json_decode($msg->payload, true);
        if (!is_array($data) || !isset($data['id']) || !isset($this->pending[$data['id']])) {
            return;
        }
        $name = $this->pending[$data['id']];
        unset($this->pending[$data['id']]);

        if (isset($data['error'])) {
            // a device without /etc/apk/world is an opkg device, not a failure
            // worth aborting over — it just contributes no package list
            if (self::UBUS_NOT_FOUND !== ($data['error']['code'] ?? null)) {
                $this->logger->warning('imageInventory('.$name.'): '.$key.' failed: '.
                    ($data['error']['message'] ?? 'unknown'));
            }

            return;
        }

        $result = $data['result'] ?? null;
        if ('packages' === $key) {
            $this->devices[$name]['packages'] = $this->parseWorld($result['data'] ?? '');

            return;
        }
        if ('apman_config' === $key) {
            $this->devices[$name]['apman_config'] = $result['values'] ?? $result;
        }
    }

    /**
     * /etc/apk/world is one package per line, optionally with a version
     * constraint appended.
     */
    private function parseWorld($text)
    {
        $packages = [];
        foreach (preg_split('/\s+/', (string) $text) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $packages[] = preg_split('/[<>=~]/', $line)[0];
        }
        sort($packages);

        return $packages;
    }
}
