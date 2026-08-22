<?php

namespace App\Tests\Unit;

use ApManBundle\Library\ApLogProcessor;
use ApManBundle\Service\ApContextService;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Every line the subscriber emits while handling a message says which access
 * point it came from.
 */
class ApLogProcessorTest extends TestCase
{
    private function record(string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, $message);
    }

    public function testTheNameGoesIntoTheLineAndIntoExtra(): void
    {
        $context = new ApContextService();
        $context->setAp('ap-av-attic');
        $out = (new ApLogProcessor($context))($this->record('bss add notification is not an array'));

        $this->assertSame('[ap-av-attic] bss add notification is not an array', $out->message);
        $this->assertSame('ap-av-attic', $out->extra['ap']);
    }

    /** A line that already names it is not made to say it twice. */
    public function testALineThatAlreadySaysItIsLeftAlone(): void
    {
        $context = new ApContextService();
        $context->setAp('ap-av-attic');
        $out = (new ApLogProcessor($context))($this->record('ap not found ap-av-attic'));

        $this->assertSame('ap not found ap-av-attic', $out->message);
        $this->assertSame('ap-av-attic', $out->extra['ap']);
    }

    /** Outside message handling there is no access point, and none is invented. */
    public function testNothingIsAddedWhenNoMessageIsBeingHandled(): void
    {
        $out = (new ApLogProcessor(new ApContextService()))($this->record('doHouseKeeping()'));

        $this->assertSame('doHouseKeeping()', $out->message);
        $this->assertArrayNotHasKey('ap', $out->extra);
    }

    /** A forgotten clear is not sticky: the housekeeping tick resets it. */
    public function testResetClearsIt(): void
    {
        $context = new ApContextService();
        $context->setAp('ap-av-attic');
        $processor = new ApLogProcessor($context);
        $processor->reset();

        $this->assertSame('still here', $processor($this->record('still here'))->message);
    }
}
