<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App;

use IndexNowKit\Result;

/**
 * What a metrics integration looks like: a listener on the Result event, no decoration.
 */
final class ResultRecorderListener
{
    /** @var list<Result> */
    public array $results = [];

    public function __invoke(Result $result): void
    {
        $this->results[] = $result;
    }
}
