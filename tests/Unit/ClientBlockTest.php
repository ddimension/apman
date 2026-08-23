<?php

namespace App\Tests\Unit;

use ApManBundle\Entity\Client;
use ApManBundle\Service\BlocklistService;
use PHPUnit\Framework\TestCase;

/**
 * A block is a date, and the only question anyone asks of it is "now?".
 */
class ClientBlockTest extends TestCase
{
    public function testAClientWithNoDateIsWelcome(): void
    {
        $this->assertFalse((new Client())->isBlocked());
    }

    public function testADateInThePastIsNotABlock(): void
    {
        $client = new Client();
        $client->setBlockedUntil(new \DateTime('-1 minute'));

        $this->assertFalse($client->isBlocked(),
            'a lapsed block is kept for its reason, not to keep anybody out');
        $this->assertNotNull($client->getBlockedUntil(),
            'and the date stays, because why it happened is worth knowing later');
    }

    public function testADateInTheFutureIs(): void
    {
        $client = new Client();
        $client->setBlockedUntil(new \DateTime('+1 minute'));

        $this->assertTrue($client->isBlocked());
    }

    /**
     * The ban is the enforcement, not the decision, and it has to be short
     * enough that lifting a block is not a wait: hostapd cannot be told to
     * forget one, only to let it lapse.
     */
    public function testTheBanIsShortEnoughThatUnblockingIsQuick(): void
    {
        $this->assertLessThanOrEqual(60000, BlocklistService::BAN_MS,
            'every millisecond of a ban is a millisecond that letting somebody back on takes');
    }
}
