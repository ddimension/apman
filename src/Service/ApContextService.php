<?php

namespace ApManBundle\Service;

/**
 * The AP a log line belongs to.
 *
 * The subscriber handles one message at a time. While it does, this holder
 * names the access point that message came from, so the log processor can
 * stamp every line written in between — including lines from handlers that
 * never see the access point object itself.
 */
class ApContextService
{
    private $ap;

    public function setAp(?string $ap): void
    {
        $this->ap = $ap;
    }

    public function getAp(): ?string
    {
        return $this->ap;
    }

    public function clearAp(): void
    {
        $this->ap = null;
    }
}
