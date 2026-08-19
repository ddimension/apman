<?php

namespace ApManBundle\Service;

use SWSN\ReactRadius\Attribute\AttributeInterface;
use SWSN\ReactRadius\Connection\Context;
use SWSN\ReactRadius\Exception\ReactRadiusException;
use SWSN\ReactRadius\ReactRadius;

/**
 * The RADIUS socket, on the same event loop as everything else.
 *
 * It answers exactly one kind of question: the access point asks whether a MAC
 * may join an SSID, and gets the key that station is supposed to use. No EAP,
 * no accounting — hostapd's MAC-ACL mode with wpa_psk_radius, which is the only
 * way to give a WPA3 station a key of its own: with SAE hostapd never looks
 * into wpa_psk_file.
 *
 * The server is off unless RADIUS_ENABLED says otherwise, so a controller that
 * knows nothing about this keeps behaving as before.
 */
class RadiusServerService
{
    private $logger;
    private $auth;
    private $enabled;
    private $bind;
    private $secret;
    /** answer every request with this key — for bringing the socket up, not for use */
    private $testPsk;
    private $server;
    private $apContext;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        RadiusAuthService $auth,
        \ApManBundle\Service\ApContextService $apContext,
        $enabled = false,
        $bind = '0.0.0.0:1812',
        $secret = '',
        $testPsk = ''
    ) {
        $this->logger = $logger;
        $this->auth = $auth;
        $this->apContext = $apContext;
        $this->enabled = (bool) $enabled;
        $this->bind = $bind ?: '0.0.0.0:1812';
        $this->secret = (string) $secret;
        $this->testPsk = (string) $testPsk;
    }

    public function isEnabled()
    {
        return $this->enabled && '' !== $this->secret;
    }

    public function getBind()
    {
        return $this->bind;
    }

    /**
     * Open the socket on the given loop. Returns false when the server is
     * switched off or has no secret — a RADIUS server without a shared secret
     * would answer everyone, so that case is a refusal, not a default.
     */
    public function listen(\React\EventLoop\LoopInterface $loop)
    {
        if (!$this->enabled) {
            $this->logger->info('RadiusServer: disabled');

            return false;
        }
        if ('' === $this->secret) {
            $this->logger->error('RadiusServer: no shared secret configured, not starting');

            return false;
        }

        $this->server = new \ApManBundle\Radius\ApmanRadiusServer($this->bind, $this->secret, null, $loop);
        // the library's own Tunnel-Password handler omits the length octet
        // RFC 2868 prescribes; ours writes it
        $this->server->setHandler(
            new \ApManBundle\Radius\ApmanTunnelPasswordHandler($this->secret),
            AttributeInterface::ATTR_TUNNEL_PASSWORD,
            'Tunnel-Password'
        );

        $this->server->on(ReactRadius::EVENT_PACKET, function (Context $context) {
            $this->handle($context);
        });
        $this->server->on(ReactRadius::EVENT_ERROR, function (ReactRadiusException $e) {
            $this->logger->warning('RadiusServer: '.$e->getMessage().' ['.$e->getCode().']');
        });
        $this->server->on(ReactRadius::EVENT_PACKET_DISCARDED, function ($e) {
            $this->logger->debug('RadiusServer: discarded a packet: '.$e->getMessage());
        });

        $this->logger->info('RadiusServer: listening on '.$this->bind);

        return true;
    }

    /**
     * One request. Everything that can go wrong here stays here: an
     * unanswered request makes one station wait for its retry, an exception
     * escaping into the loop would stop the whole controller.
     */
    private function handle(Context $context)
    {
        try {
            // Radius packets name their AP as NAS-Identifier (or the NAS IP);
            // stamp every log line this request produces with it. Cleared in
            // finally, so the line below the catch keeps it too.
            $request = $this->auth->readRequest($context);
            $this->apContext->setAp($request['nas'] ?: null);
            if ('' !== $this->testPsk) {
                $this->auth->answerFixed($context, $this->testPsk);

                return;
            }
            $this->auth->handle($context);
        } catch (\Throwable $e) {
            $this->logger->error('RadiusServer: '.$e->getMessage().' '.$e->getTraceAsString());
        } finally {
            $this->apContext->clearAp();
        }
    }
}
