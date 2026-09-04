<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * A typo inside an owned block (`key_file.enabld`) is rejected at compile time by the configuration tree, never
 * silently accepted: the bundle equivalent of the `unknownOptions()` warning of the other adapters.
 */
final class ConfigTypoTest extends BundleTestCase
{
    protected static string $dispatch = 'keyfiletypo';

    public function testTypoInsideKeyFileBlockFailsToCompile(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/enabld/');
        static::bootKernel();
    }
}
