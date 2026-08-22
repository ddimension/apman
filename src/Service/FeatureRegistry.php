<?php

namespace ApManBundle\Service;

/**
 * The implementations, by the two names they are known under.
 *
 * A feature row in the database stores a fully qualified class name in
 * feature.implementation, and the code used to run it: `new $implementation()`
 * on a string an admin form accepted as free text, with no class_exists() and
 * no instanceof on the path that mattered. A typo there was a fatal error in
 * the middle of provisioning an access point.
 *
 * Everything is indexed twice on purpose. The class name keeps the existing
 * rows working — and the two commands and PpskService::hasIpskFeature() that
 * match on it in raw SQL — while getName() gives a handle that survives a
 * class being renamed. New rows should use the short name.
 */
class FeatureRegistry
{
    /** @var array<string,iFeatureService> */
    private array $byKey = [];

    /**
     * @param iterable<iFeatureService> $services tagged apman.feature
     */
    public function __construct(iterable $services)
    {
        foreach ($services as $service) {
            $this->byKey[$this->normalise($service::class)] = $service;
            $this->byKey[$service->getName()] = $service;
        }
    }

    /**
     * Both spellings of a class name are the same class.
     *
     * The feature table holds twenty rows written as
     * "\ApManBundle\Service\DefaultFeatureService" and one, inserted by
     * apman:ipsk-migrate through a parameterised query, without the leading
     * backslash. `new $string()` never minded; an array lookup does. Normalise
     * rather than migrate — a leading backslash is a spelling, not a fact
     * about the row.
     */
    private function normalise(string $key): string
    {
        return ltrim($key, '\\');
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$this->normalise($key)]);
    }

    /**
     * @throws \RuntimeException when the database names something that is not here
     */
    public function get(string $key): iFeatureService
    {
        $normalised = $this->normalise($key);
        if (!isset($this->byKey[$normalised])) {
            throw new \RuntimeException('no such feature implementation: '.$key
                .' (known: '.implode(', ', $this->names()).')');
        }

        return $this->byKey[$normalised];
    }

    /**
     * The short names, for the admin form and for error messages.
     *
     * @return string[]
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->byKey as $key => $service) {
            $names[$service->getName()] = $service->getName();
        }
        ksort($names);

        return array_values($names);
    }

    /**
     * Short name => class name, for a form that stores the class.
     *
     * @return array<string,string>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->byKey as $key => $service) {
            $choices[$service->getName()] = $service::class;
        }
        ksort($choices);

        return $choices;
    }
}
