<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * An application check picked up by autoconfiguration (tag indexnowkit.check) and printed by indexnow:check.
 */
final class CdnCheck implements CheckInterface
{
    public function check(CheckReport $report): void
    {
        $report->ok('cdn: key file purged from the edge');
    }
}
