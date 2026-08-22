<?php

namespace ApManBundle\Service;

/**
 * A feature that is nothing but its catalog row.
 *
 * Most of the catalog is this: "Advanced AP Settings", "802.11r PSK SAE",
 * "Load Sharing Settings" and a dozen more carry a json map of uci options and
 * no code at all. The merge itself lives in AbstractFeatureService, because
 * every other implementation starts from it too.
 *
 * What used to be here besides that: sixty-eight lines of setters identical to
 * three other classes, and an applyConstraints() body that began with an
 * unconditional `return;` and continued with a copy of the OWE one — including
 * a call to setupOweSsid(), a method this class does not have. Had the return
 * ever been removed it would have been a fatal error on the provisioning path.
 */
class DefaultFeatureService extends AbstractFeatureService
{
    public function getName(): string
    {
        return 'default';
    }
}
