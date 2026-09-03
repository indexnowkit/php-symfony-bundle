<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\TestDox;

final class KeyFileDisabledTest extends BundleTestCase
{
    protected static string $dispatch = 'nokey';

    #[TestDox('H03 serve_key_file: false -> 404 even for the configured key')]
    public function testKeyFileNotServed(): void
    {
        $client = $this->browser();
        $client->request('GET', '/' . TestKernel::KEY . '.txt');

        self::assertResponseStatusCodeSame(404);
    }
}
