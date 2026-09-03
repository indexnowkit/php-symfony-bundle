<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Testing\FakeTransport;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class BundleTestCase extends WebTestCase
{
    protected static string $dispatch = 'sync';

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false, static::$dispatch);
    }

    protected function browser(): KernelBrowser
    {
        $client = static::createClient([], ['HTTP_HOST' => 'www.example.com', 'HTTPS' => 'on']);
        $client->disableReboot(); // keep the in-memory sqlite database across requests
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $client;
    }

    protected function transport(): FakeTransport
    {
        $transport = static::getContainer()->get(FakeTransport::class);
        \assert($transport instanceof FakeTransport);

        return $transport;
    }

    /**
     * @return list<string>
     */
    protected function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport()->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }
}
