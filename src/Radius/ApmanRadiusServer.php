<?php

namespace ApManBundle\Radius;

use SWSN\ReactRadius\Packet\RequestPacket;
use SWSN\ReactRadius\Packet\ResponsePacket;
use SWSN\ReactRadius\ReactRadiusServer;

/**
 * The library's server plus a Message-Authenticator on every answer.
 *
 * RFC 3579 defines attribute 80 as an HMAC-MD5 over the whole packet, and
 * since the BlastRADIUS attack (CVE-2024-3596) it is the only part of a
 * response that cannot be forged by someone who can reach the wire: the
 * Response Authenticator is plain MD5 and has a known chosen prefix weakness.
 * hostapd 2.11 and newer can insist on it — `radius_require_message_authenticator`
 * — so we always send it rather than wait for the day somebody switches that on.
 *
 * Order matters and is the whole reason this method is copied rather than
 * wrapped: the Message-Authenticator is computed over the packet with its own
 * field zeroed and with the *request's* authenticator in the authenticator
 * field, and only then is the Response Authenticator computed over the result.
 */
class ApmanRadiusServer extends ReactRadiusServer
{
    private const ATTR_MESSAGE_AUTHENTICATOR = 80;

    protected function serializeResponse(ResponsePacket $responsePacket, RequestPacket $requestPacket): string
    {
        $attributes = '';
        foreach ($responsePacket->getAttributes() as $attribute) {
            $attributes .= $this->attributeManager->serializeAttribute($attribute, $requestPacket);
        }

        // type + length + sixteen zero octets, to be filled in below
        $placeholder = chr(self::ATTR_MESSAGE_AUTHENTICATOR).chr(18).str_repeat("\0", 16);
        $attributes .= $placeholder;

        // code + identifier + length; the length counts the twenty byte header
        $header = chr($responsePacket->getType())
            .chr($responsePacket->getIdentifier())
            .pack('n', strlen($attributes) + 20);

        $mac = hash_hmac('md5', $header.$requestPacket->getAuthenticator().$attributes, $this->psk, true);
        // put it where the zeros were: the last block of the attribute string
        $attributes = substr($attributes, 0, -16).$mac;

        $responseAuth = md5($header.$requestPacket->getAuthenticator().$attributes.$this->psk, true);

        return $header.$responseAuth.$attributes;
    }
}
