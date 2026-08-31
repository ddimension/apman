<?php

namespace ApManBundle\Service;

use React\EventLoop\LoopInterface;
use React\Socket\SocketServer;

/**
 * The browser push channel, served by the subscriber's own loop.
 *
 * Each browser tab on the client views opens /apman/stream/clients, and
 * apache proxies it to this socket. Serving the stream from this process is
 * the whole design: the events are raised here, so pushing them costs no
 * round trip and no php-fpm worker — an EventSource client is just one more
 * stream on the loop that already carries mqtt and radius. Holding one fpm
 * worker per open tab for the life of a stream was the alternative, and with
 * a pool of five it stalls the site at six tabs.
 *
 * The stream carries connect and disconnect events only; the grid poll is
 * still the completeness net. Nothing in here is allowed to throw into the
 * loop.
 */
class SseServer
{
    /** How long a connection may live before it is cut; the browser reconnects. */
    private const MAX_AGE = 60;
    /** Ping cadence, so idling proxies see the stream as alive. */
    private const PING_INTERVAL = 20;

    /** @var array<int,array{conn:object,mac:?string,at:int,buf:string,up:bool}> */
    private $clients = [];

    public function __construct(private \Psr\Log\LoggerInterface $logger)
    {
    }

    public function start(LoopInterface $loop): void
    {
        $server = new SocketServer('tcp://127.0.0.1:18080', [], $loop);
        $server->on('connection', function ($conn) {
            $id = spl_object_id($conn);
            $this->clients[$id] = ['conn' => $conn, 'mac' => null, 'at' => time(), 'buf' => '', 'up' => false];
            $conn->on('data', function ($chunk) use ($id) {
                $this->onData($id, $chunk);
            });
            $conn->on('close', function () use ($id) {
                unset($this->clients[$id]);
            });
            $conn->on('error', function () use ($id) {
                unset($this->clients[$id]);
            });
        });
        $server->on('error', function (\Throwable $e) {
            $this->logger->warning('sse: '.$e->getMessage());
        });

        $loop->addPeriodicTimer(self::PING_INTERVAL, function () {
            $this->ping();
        });
        $loop->addPeriodicTimer(self::PING_INTERVAL, function () {
            $this->expire();
        });
    }

    /** Push one event to every open stream, filtered by mac where asked. */
    public function push(array $data): void
    {
        $payload = 'data: '.json_encode($data)."\n\n";
        foreach ($this->clients as $c) {
            if (!$c['up']) {
                continue;
            }
            if (null !== $c['mac'] && $c['mac'] !== ($data['mac'] ?? null)) {
                continue;
            }
            try {
                $c['conn']->write($payload);
            } catch (\Throwable $e) {
            }
        }
    }

    private function onData(int $id, string $chunk): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $c = &$this->clients[$id];
        if ($c['up']) {
            // after the handshake the browser only reads; data is ignored
            return;
        }
        $c['buf'] .= $chunk;
        if (strlen($c['buf']) > 8192) {
            $this->clients[$id]['conn']->end("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");

            return;
        }
        $end = strpos($c['buf'], "\r\n\r\n");
        if (false === $end) {
            return;
        }
        $head = substr($c['buf'], 0, $end);
        $c['buf'] = '';
        // the proxy forwards the request path; only the mac filter matters
        if (preg_match('/[?&]mac=([0-9a-f:]+)/i', $head, $m)) {
            $c['mac'] = strtolower($m[1]);
        }
        $c['up'] = true;
        $c['conn']->write("HTTP/1.1 200 OK\r\n".
            "Content-Type: text/event-stream\r\n".
            "Cache-Control: no-cache\r\n".
            "Connection: close\r\n\r\n".
            ": ready\n\n");
    }

    private function ping(): void
    {
        foreach ($this->clients as $c) {
            if ($c['up']) {
                try {
                    $c['conn']->write(": ping\n\n");
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private function expire(): void
    {
        foreach ($this->clients as $id => $c) {
            if (time() - $c['at'] > self::MAX_AGE) {
                try {
                    $c['conn']->end();
                } catch (\Throwable $e) {
                }
                unset($this->clients[$id]);
            }
        }
    }
}
