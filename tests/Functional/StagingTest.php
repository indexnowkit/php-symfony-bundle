<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Testing\CheckOutputAssertions;

/**
 * A staging copy with the production key and no `dry_run` line submits real URLs: `indexnow:check` must fail on
 * it (the kernel environment "test" is not in production_environments), and pass with an explicit `dry_run: false`.
 */
final class StagingTest extends BundleTestCase
{
    protected static string $dispatch = 'staging';

    public function testCheckFailsOutsideProductionWhenDryRunIsUnset(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY));
        $tester = $this->tester('indexnow:check');

        CheckOutputAssertions::assertExitCode(1, $tester->execute([]), $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('✘ environment "test" is not in production_environments but dry_run is off: changes WILL be sent to search engines under key', $display);
        self::assertStringContainsString('set dry_run: false explicitly if this environment submits on purpose', $display);
        self::assertStringNotContainsString(TestKernel::KEY, $display, 'the key is masked');
    }
}
