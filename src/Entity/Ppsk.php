<?php

namespace ApManBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A per device pre shared key for one SSID.
 *
 * The controller is the point of truth: these rows are rendered into uci
 * wifi-station sections (persistent, survives a reboot) and into the runtime
 * wpa_psk_file of every access point carrying the SSID (immediate, so a client
 * can roam right after enrolment).
 *
 * An iPSK is the same row with a wildcard MAC: the key alone is the identity,
 * so several of them share 00:00:00:00:00:00 on one SSID and it is the key
 * that has to be unique, not the address.
 *
 * The key is unique per SSID *and address*, not per SSID alone. Two devices
 * may hold the same passphrase — that is exactly what happens when an existing
 * network is converted: every station gets a row of its own carrying the
 * passphrase it already uses, so it keeps working untouched while gaining an
 * identity that can be rotated or withdrawn on its own. For MAC agnostic
 * entries (address 00:00:00:00:00:00) the constraint still means one row per
 * key, which is what makes an iPSK an identity.
 */
#[ORM\Table(name: 'ppsk')]
// One key per device per network. The psk used to be part of this, which
// allowed a second row for the same address — and the access point can only
// ever answer with one key, so the second was unreachable and became a way to
// lose a device quietly. The corollary is that a network can hold only one
// unbound key (they all carry ANY_MAC), which is the same rule the agent
// enforces when it picks what to answer.
#[ORM\UniqueConstraint(name: 'ppsk_ssid_mac', columns: ['ssid_id', 'mac'])]
#[ORM\Entity]
class Ppsk
{
    /** MAC agnostic entry: the key alone identifies the device */
    public const ANY_MAC = '00:00:00:00:00:00';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_WPS = 'wps';
    /** generated in the interface as a transportable identity key */
    public const SOURCE_IPSK = 'ipsk';
    /** taken over from the per device entries of the RADIUS server */
    public const SOURCE_RADIUS = 'radius';
    /** made from a station that was already connected with the network passphrase */
    public const SOURCE_CONVERTED = 'converted';
    /** learned by itself from a station that came in on a key bound to no address */
    public const SOURCE_AUTO = 'auto';

    /** hostapd stores the keyid in a fixed buffer; stay well below it */
    public const KEYID_MAX = 24;

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    #[ORM\JoinColumn(name: 'ssid_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: \ApManBundle\Entity\SSID::class)]
    private $ssid;

    #[ORM\Column(name: 'name', type: 'string', length: 128, nullable: true)]
    private $name;

    /**
     * Client MAC, or 00:00:00:00:00:00 for a key that is not bound to one.
     * MAC bound keys break when a client randomises per connection.
     */
    #[ORM\Column(name: 'mac', type: 'string', length: 17)]
    private $mac = self::ANY_MAC;

    /**
     * Passphrase (8..63 chars) or a raw 64 hex character PSK, which is what
     * hostapd generates during WPS enrolment.
     */
    #[ORM\Column(name: 'psk', type: 'string', length: 64)]
    private $psk;

    #[ORM\Column(name: 'vid', type: 'integer', nullable: true)]
    private $vid;

    #[ORM\Column(name: 'enabled', type: 'boolean')]
    private $enabled = true;

    #[ORM\Column(name: 'source', type: 'string', length: 16)]
    private $source = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'created', type: 'datetime')]
    private $created;

    #[ORM\Column(name: 'comment', type: 'text', nullable: true)]
    private $comment;

    /**
     * The identity hostapd reports back for a station that authenticated with
     * this key ("keyid=" in the psk file). Without it a wildcard MAC key is
     * anonymous — every client looks the same.
     */
    #[ORM\Column(name: 'keyid', type: 'string', length: 32, nullable: true)]
    private $keyid;

    /**
     * Bind the key to the first device that uses it.
     *
     * A wildcard address is what makes a key transportable — and also means a
     * copy of it works just as well. With this set, the first station that
     * authenticates becomes the key's address and every other device is locked
     * out from then on. Wrong for a key handed to a person with three devices,
     * right for one that stands for a single machine.
     */
    #[ORM\Column(name: 'pin_mac', type: 'boolean', options: ['default' => false])]
    private $pinMac = false;

    #[ORM\Column(name: 'first_seen', type: 'datetime', nullable: true)]
    private $firstSeen;

    #[ORM\Column(name: 'last_seen', type: 'datetime', nullable: true)]
    private $lastSeen;

    /**
     * The address of the station that used this key last. Not an identity —
     * clients randomise it — but it is what makes a key traceable in the logs.
     */
    #[ORM\Column(name: 'last_mac', type: 'string', length: 17, nullable: true)]
    private $lastMac;

    /**
     * The shared key this one pushed aside while it was being registered.
     *
     * A registration key is the only key a network accepts from an unknown
     * device for the length of the enrolment, which is what makes the enrolment
     * exclusive. The moment it is claimed and pinned to an address, the key it
     * displaced goes back into service — this is where the controller
     * remembers which one that was.
     *
     * Deliberately a plain id and not a relation: it points at a row that may
     * have been deleted in the meantime, and a dangling registration must not
     * block anything.
     */
    #[ORM\Column(name: 'restores_id', type: 'integer', nullable: true)]
    private $restoresId;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function __toString()
    {
        return ($this->name ?: $this->mac).' @ '.($this->ssid ? $this->ssid->getName() : '?');
    }

    public function getId()
    {
        return $this->id;
    }

    public function getSsid()
    {
        return $this->ssid;
    }

    public function setSsid($ssid)
    {
        $this->ssid = $ssid;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function getMac()
    {
        return $this->mac;
    }

    public function setMac($mac)
    {
        $this->mac = strtolower(trim($mac ?: self::ANY_MAC));

        return $this;
    }

    public function getPsk()
    {
        return $this->psk;
    }

    public function setPsk($psk)
    {
        $this->psk = trim($psk);

        return $this;
    }

    public function getVid()
    {
        return $this->vid;
    }

    public function setVid($vid)
    {
        $this->vid = $vid;

        return $this;
    }

    public function getEnabled()
    {
        return $this->enabled;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;

        return $this;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function setSource($source)
    {
        $this->source = $source;

        return $this;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    public function getComment()
    {
        return $this->comment;
    }

    public function setComment($comment)
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * hostapd accepts a 64 character hex string as a raw PSK and anything else
     * as a passphrase.
     */
    public function isRawPsk()
    {
        return 64 === strlen($this->psk) && ctype_xdigit($this->psk);
    }

    public function getKeyid()
    {
        return $this->keyid;
    }

    public function setKeyid($keyid)
    {
        $this->keyid = $keyid;

        return $this;
    }

    /**
     * A keyid that survives the trip through the psk file: it is parsed as a
     * whitespace delimited token, so only unreserved characters, and the
     * leading id keeps it unique and mappable back to this row.
     */
    public function buildKeyid()
    {
        $slug = strtolower((string) $this->name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $keyid = $this->id.($slug ? '-'.$slug : '');

        return substr($keyid, 0, self::KEYID_MAX);
    }

    public function getPinMac()
    {
        return $this->pinMac;
    }

    public function setPinMac($pinMac)
    {
        $this->pinMac = (bool) $pinMac;

        return $this;
    }

    /** wanted, but still on the wildcard: no device has claimed it yet */
    public function isPinPending()
    {
        return $this->pinMac && self::ANY_MAC === $this->mac;
    }

    public function isPinned()
    {
        return $this->pinMac && self::ANY_MAC !== $this->mac;
    }

    public function getFirstSeen()
    {
        return $this->firstSeen;
    }

    public function setFirstSeen($firstSeen)
    {
        $this->firstSeen = $firstSeen;

        return $this;
    }

    public function getLastSeen()
    {
        return $this->lastSeen;
    }

    public function setLastSeen($lastSeen)
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    public function getLastMac()
    {
        return $this->lastMac;
    }

    public function setLastMac($lastMac)
    {
        $this->lastMac = $lastMac;

        return $this;
    }

    public function getRestoresId()
    {
        return $this->restoresId;
    }

    public function setRestoresId($restoresId)
    {
        $this->restoresId = null === $restoresId ? null : (int) $restoresId;

        return $this;
    }

    /** a key handed out for one enrolment, waiting to be claimed */
    public function isRegistration()
    {
        return null !== $this->restoresId && self::ANY_MAC === $this->mac;
    }

    /** has a client ever authenticated with this key? */
    public function isUsed()
    {
        return null !== $this->firstSeen;
    }

    public function isValid()
    {
        if ($this->isRawPsk()) {
            return true;
        }
        $len = strlen($this->psk);

        return $len >= 8 && $len <= 63;
    }
}
