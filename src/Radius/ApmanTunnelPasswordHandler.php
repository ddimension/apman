<?php

namespace ApManBundle\Radius;

use SWSN\ReactRadius\Attribute\AttributeInterface;
use SWSN\ReactRadius\Attribute\RawAttribute;
use SWSN\ReactRadius\Attribute\TunnelAttribute;
use SWSN\ReactRadius\AttributeHandler\AbstractAttributeHandler;
use SWSN\ReactRadius\Packet\PacketInterface;

/**
 * Tunnel-Password the way RFC 2868 actually writes it.
 *
 * The library's own handler encrypts correctly but forgets the first octet of
 * the plaintext: section 3.5 says the string is
 *
 *     P = Password-Length || Password || Padding
 *
 * and the receiver reads that length to know where the password ends. hostapd
 * does exactly that in decrypt_ms_key(): it rejects the attribute when the
 * first decrypted byte is zero or larger than the block, and otherwise copies
 * that many bytes starting at the second one. Without the length octet the
 * access point would take the first character of the passphrase as a length
 * and hand the rest to the four way handshake — every association would fail
 * for a reason nothing logs.
 *
 * Registered over the library's version in RadiusServerService.
 */
class ApmanTunnelPasswordHandler extends AbstractAttributeHandler
{
    private $secret;

    public function __construct($secret)
    {
        $this->secret = $secret;
    }

    /**
     * Only the server side is needed here — we never read a Tunnel-Password,
     * we only ever send one.
     */
    public function deserializeRawAttribute(RawAttribute $rawAttribute, PacketInterface $requestPacket): ?AttributeInterface
    {
        return null;
    }

    public function serializeValue(AttributeInterface $attribute, PacketInterface $requestPacket): ?string
    {
        /** @var TunnelAttribute $attribute */
        $value = (string) $attribute->getValue();

        // "The Salt field is two octets in length... The most significant bit
        // (leftmost) of the Salt field MUST be set (1). The contents of each
        // Salt field in a given Access-Accept packet MUST be unique."
        $salt = random_bytes(2);
        $salt[0] = chr(ord($salt[0]) | 0x80);

        // P = Password-Length || Password || Padding to a multiple of 16
        $plain = chr(strlen($value)).$value;
        $pad = (16 - (strlen($plain) % 16)) % 16;
        $plain .= str_repeat("\0", $pad);

        // b(1) = MD5(S + R + A), b(i) = MD5(S + c(i-1)), c(i) = p(i) xor b(i)
        $b = md5($this->secret.$requestPacket->getAuthenticator().$salt, true);
        $out = '';
        foreach (str_split($plain, 16) as $block) {
            $c = $block ^ $b;
            $b = md5($this->secret.$c, true);
            $out .= $c;
        }

        return $this->packInt8($attribute->getTag()).$salt.$out;
    }
}
