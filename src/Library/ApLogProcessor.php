<?php

namespace ApManBundle\Library;

use ApManBundle\Service\ApContextService;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\ResettableInterface;

/**
 * Stamps every record written while the daemon handles an AP message with
 * the name of that access point, in the extra array.
 *
 * extra and not context, so the context keys some call sites already set
 * ('ap', 'apname') keep their own meaning.
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
        if (null !== $ap) {
            $record->extra['ap'] = $ap;
        }

        return $record;
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
