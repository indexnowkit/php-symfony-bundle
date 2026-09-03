<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\SubmitterInterface;

/**
 * Builds the submitter console commands use for `--force` (no debounce) and `--dry-run`. Decorate
 * `indexnowkit.command_submitter_factory` to wrap what those commands submit through.
 */
interface SubmitterFactoryInterface
{
    public function create(bool $force, bool $dryRun): SubmitterInterface;
}
