<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Check\LocalesCheck;
use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\Article;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * W14: `#[IndexNow(locales: 'all')]` needs `framework.enabled_locales`; with an empty list it silently produces one
 * URL without a locale. The `nolocales` variant is that application, and `indexnow:check` names the option.
 */
final class LocalesCheckTest extends BundleTestCase
{
    protected static string $dispatch = 'nolocales';

    #[TestDox('check warns and names framework.enabled_locales and the entity asking for every locale')]
    public function testEmptyEnabledLocalesIsOneWarningLine(): void
    {
        static::bootKernel();
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']));

        $check = $this->tester('indexnow:check');
        self::assertSame(0, $check->execute(['--json' => true]));
        $decoded = json_decode($check->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $items = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === LocalesCheck::CODE));

        self::assertCount(1, $items, 'exactly one line, not one per rule');
        self::assertSame('warning', $items[0]['level']);
        self::assertStringContainsString(Article::class, $items[0]['message']);
        self::assertStringContainsString('framework.enabled_locales', $items[0]['message']);
    }
}
