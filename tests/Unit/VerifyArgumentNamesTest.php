<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use IndexNowKit\SymfonyBundle\DependencyInjection\VerifyServices;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * A16: {@see VerifyServices::register()} passes the pre-flight decorators their arguments by name. A renamed
 * parameter in `indexnowkit/verify` then fails the compilation of the container instead of silently shifting a
 * value into the next slot, and this pins the names the bundle relies on so the failure names them too.
 */
final class VerifyArgumentNamesTest extends TestCase
{
    /** The `$name` keys of VerifyServices::register(); `$inWebRequest` is passed to the submitter only. */
    private const SHARED = ['inner', 'transport', 'config', 'keys', 'normalizer', 'logger', 'events', 'store', 'robots', 'clock'];

    #[TestDox('every named argument the bundle passes exists on VerifyingSubmitter and VerifyingSubmitterFactory')]
    public function testTheNamedArgumentsExist(): void
    {
        $submitter = self::parameters(VerifyingSubmitter::class);
        $factory = self::parameters(VerifyingSubmitterFactory::class);

        foreach (self::SHARED as $name) {
            self::assertContains($name, $submitter, \sprintf('VerifyingSubmitter has no $%s parameter', $name));
            self::assertContains($name, $factory, \sprintf('VerifyingSubmitterFactory has no $%s parameter', $name));
        }
        self::assertContains('inWebRequest', $submitter);
        self::assertSame('inner', $submitter[0], 'the decorated service is the first argument (.inner)');
        self::assertSame('inner', $factory[0]);
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function parameters(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor);

        return array_map(static fn(ReflectionParameter $p): string => $p->getName(), $constructor->getParameters());
    }
}
