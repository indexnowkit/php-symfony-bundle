<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

/**
 * Without DoctrineBundle the container must still compile and the CLI must still work; only the
 * entity-hooking half of the bundle (listener, submit-entity, explain) is absent.
 */
final class NoDoctrineTest extends BundleTestCase
{
    protected static string $dispatch = 'nodoctrine';

    public function testContainerCompilesWithoutDoctrine(): void
    {
        static::bootKernel();
        self::assertFalse(static::getContainer()->has('indexnowkit.doctrine.listener'));
        self::assertFalse(static::getContainer()->has('indexnow:submit-entity'));
    }

    public function testCheckReportsEntityHooksNotActive(): void
    {
        $tester = $this->tester('indexnow:check');
        $tester->execute([]);

        self::assertStringContainsString('entity hooks are NOT active', $tester->getDisplay());
    }

    public function testSubmitWorksWithFakeTransport(): void
    {
        $tester = $this->tester('indexnow:submit');
        self::assertSame(0, $tester->execute(['urls' => ['/a']]));
        self::assertSame(['https://www.example.com/a'], $this->sentUrls());
    }
}
