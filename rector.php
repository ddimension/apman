<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Symfony\Set\SymfonySetList;

/**
 * Annotations to attributes. Doctrine ORM 3 dropped the annotation driver, so
 * the docblocks had to become attributes before the version could move.
 *
 * Deliberately narrow: only the annotation sets, no modernisation rules. A
 * version bump that also reformats 27 files is a bump nobody can review.
 *
 * The conversion is done and rector is not a dependency of this project — it
 * was a one-time tool and would otherwise ride along into the vendor tree on
 * the server. This file is kept as the record of how it ran; to use it again:
 *
 *     composer require --dev rector/rector
 *     vendor/bin/rector process --dry-run
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_82)
    ->withSets([
        DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES,
        SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
