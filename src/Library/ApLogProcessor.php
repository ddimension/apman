<?php

namespace ApManBundle\Library;

use ApManBundle\Service\ApContextService;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\ResettableInterface;

/**
 * Stamps every record written while the daemon handles an AP message with the
 * name of that access point — in the line itself, and in the extra array.
 *
 * In the line, because that is what anybody reads. `handleMessage(): bss add
 * notification is not an array` is a fine sentence and useless when seven
 * machines could have sent it, and the name sitting in a json object at the end
 * of the line is the name nobody looks at when they are scrolling. Lines that
 * already say which access point they mean are left alone rather than saying it
 * twice.
 *
 * In extra as well, and not in context, so the context keys some call sites
 * already set ('ap', 'apname') keep their own meaning and anything reading the
 * log as structured data still finds it in one predictable place.
 */
#[AsMonologProcessor(channel: 'app')]
class ApLogProcessor implements ResettableInterface
{
    private $context;

    public function __construct(ApContextService $context)
    {
        $this->context = $context;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $ap = $this->context->getAp();
        if (null === $ap) {
            return $record;
        }
        $record->extra['ap'] = $ap;
        if (str_contains($record->message, $ap)) {
            return $record;
        }

        // LogRecord::$message is readonly, so the prefix needs a new record
        return $record->with(message: '['.$ap.'] '.$record->message);
    }

    /**
     * The housekeeping tick resets the logger every ten seconds, which also
     * resets this processor — a forgotten clearAp() is never sticky.
     */
    public function reset(): void
    {
        $this->context->clearAp();
    }
}
