<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Testing\FakeTransport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class BundleTestCase extends WebTestCase
{
    protected static string $dispatch = 'sync';

    /**
     * @param array<array-key, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false, static::$dispatch);
    }

    /**
     * Reuses the already booted kernel (bootKernel() always shuts down and recreates one, which would
     * wipe an in-memory sqlite database populated earlier in the test).
     */
    protected function tester(string $command): CommandTester
    {
        $kernel = static::$booted ? static::$kernel : static::bootKernel();
        \assert($kernel !== null);
        $application = new Application($kernel);

        return new CommandTester($application->find($command));
    }

    protected function schema(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function browser(string $host = 'www.example.com'): KernelBrowser
    {
        $client = static::createClient([], ['HTTP_HOST' => $host, 'HTTPS' => 'on']);
        $client->disableReboot(); // keep the in-memory sqlite database across requests
        $this->schema();

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
