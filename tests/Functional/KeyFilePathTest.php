<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Tests\Support\Factory;

/**
 * key_file.path can move the route away from the protocol default (/{key}.txt); cache_max_age controls
 * the Cache-Control header served with it.
 */
final class KeyFilePathTest extends BundleTestCase
{
    protected static string $dispatch = 'keyfilepath';

    public function testKeyFileIsServedAtTheConfiguredPathWithCustomMaxAge(): void
    {
        $client = $this->browser();
        $client->request('GET', '/keys/' . Factory::KEY . '.txt');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(Factory::KEY, $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'max-age=3600, public');
    }

    public function testDefaultPathIsNoLongerServed(): void
    {
        $client = $this->browser();
        $client->request('GET', '/' . Factory::KEY . '.txt');

        self::assertResponseStatusCodeSame(404);
    }
}
