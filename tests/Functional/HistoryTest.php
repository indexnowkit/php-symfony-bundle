<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\Http\Response;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * indexnowkit/history installed with `history.store: pdo` over the Doctrine default connection and `dispatch: sync`:
 * the container's submission store is the PDO store, a submission on kernel.terminate lands in the table,
 * `indexnow:history` lists it (table, --json, filters, --purge), the profiler panel shows it, `check` / `status` /
 * `config --json` report the store — and a missing table is one `check` error, not a broken request.
 */
final class HistoryTest extends BundleTestCase
{
    protected static string $dispatch = 'history';

    #[TestDox('a sync submission is recorded in the pdo store and listed by indexnow:history and the profiler')]
    public function testSyncSubmissionIsRecordedAndListed(): void
    {
        $client = $this->browser();
        $client->enableProfiler();
        $client->catchExceptions(false);
        $this->createHistoryTable();
        self::assertInstanceOf(PdoSubmissionStore::class, static::getContainer()->get(SubmissionStoreInterface::class));

        $client->request('POST', '/articles?slug=recorded');
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $this->sentUrls());

        $tester = $this->tester('indexnow:history');
        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('https://www.example.com/en/articles/recorded', $display);
        self::assertStringContainsString('https://www.example.com/de/articles/recorded', $display);
        self::assertStringContainsString('1 record(s)', $display);

        self::assertSame(0, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertSame(['ok', 'api', 200], [$decoded['records'][0]['status'], $decoded['records'][0]['engine'], $decoded['records'][0]['http_status']]);
        self::assertSame(['https://www.example.com/en/articles/recorded', 'https://www.example.com/de/articles/recorded'], $decoded['records'][0]['urls']);

        self::assertSame(0, $tester->execute(['--url' => 'https://www.example.com/de/articles/recorded?utm_source=x']));
        self::assertStringContainsString('1 record(s)', $tester->getDisplay(), '--url is normalized the way the submission was');
        self::assertSame(0, $tester->execute(['--status' => 'skipped']));
        self::assertStringContainsString('No records match.', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--purge' => null]));
        self::assertMatchesRegularExpression('/^purged 0 records older than \d{4}-/', trim($tester->getDisplay()));

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('indexnow');
        self::assertInstanceOf(IndexNowDataCollector::class, $collector);
        self::assertTrue($collector->hasHistory());
        self::assertNull($collector->getRecentError());
        self::assertCount(1, $collector->getRecent());
        self::assertSame('ok', $collector->getRecent()[0]['status']);

        $client->request('GET', '/_profiler/' . $profile->getToken() . '?panel=indexnow');
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Recent submissions', $html);
        self::assertStringContainsString('https://www.example.com/en/articles/recorded', $html);
    }

    public function testCheckStatusAndConfigReportTheStore(): void
    {
        static::bootKernel();
        $this->createHistoryTable();
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']));

        $check = $this->tester('indexnow:check');
        self::assertSame(0, $check->execute([]));
        self::assertStringContainsString('history: pdo store (indexnow_submissions)', $check->getDisplay());
        self::assertStringContainsString('history: no records yet', $check->getDisplay());

        $status = $this->tester('indexnow:status');
        self::assertSame(0, $status->execute([]));
        self::assertStringContainsString('dispatch: sync', $status->getDisplay());
        self::assertStringContainsString('www.example.com: 0 consecutive 403', $status->getDisplay());
        self::assertStringContainsString('history: 0 records, no successful submission recorded', $status->getDisplay());

        self::assertSame(0, $status->execute(['--json' => true]));
        $decoded = json_decode($status->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'sync', 'adapter' => []], $decoded['dispatch']);
        self::assertStringStartsWith('cache.app (', $decoded['debounce']['store']);
        self::assertSame(['store' => 'history', 'records' => 0, 'last_success' => null, 'error' => null], $decoded['history']);

        $config = $this->tester('indexnow:config');
        self::assertSame(0, $config->execute(['--json' => true]));
        $decoded = json_decode($config->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['store' => 'pdo', 'limit' => 500, 'key_prefix' => null, 'pdo' => ['dsn' => null, 'service' => 'default', 'table' => 'indexnow_submissions'], 'retention_days' => 90], $decoded['history']);
        self::assertArrayNotHasKey('history', $decoded['adapter']);
    }

    #[TestDox('without the table, check prints the history.store error with the migration hint and a request still works')]
    public function testMissingTableIsACheckError(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=untabled');
        self::assertResponseStatusCodeSame(201, 'the failing store is logged by the submitter, never thrown from kernel.terminate');
        self::assertCount(2, $this->sentUrls());

        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']));
        $check = $this->tester('indexnow:check');
        self::assertSame(1, $check->execute(['--json' => true]));
        $decoded = json_decode($check->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $items = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'history.store'));
        self::assertCount(1, $items);
        self::assertSame('error', $items[0]['level']);
        self::assertStringContainsString('docs/migrations.md', $items[0]['message']);

        $status = $this->tester('indexnow:status');
        self::assertSame(0, $status->execute(['--json' => true]), 'status stays read-only and exit 0; the error is a field');
        $decoded = json_decode($status->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('history', $decoded['history']['store']);
        self::assertNotNull($decoded['history']['error']);
    }

    private function createHistoryTable(): void
    {
        $connection = $this->em()->getConnection();
        foreach (Schema::sql('sqlite') as $sql) {
            $connection->executeStatement($sql);
        }
    }

    /**
     * The required members of packages/history/docs/status.schema.json, top level and one level down (the schema
     * validator lives in the history package's tests; here the shape of what the bundle wires is enough).
     *
     * @param array<string, mixed> $status
     */
    public static function assertStatusFollowsTheSchema(array $status): void
    {
        // The `required` members of the schema, copied: the history package is not a sibling directory in the split repository.
        $required = [
            '' => ['enabled', 'dry_run', 'environment', 'dispatch', 'debounce', 'engines', 'forbidden_escalation', 'hosts', 'history', 'core'],
            'dispatch' => ['mode', 'adapter'],
            'debounce' => ['per_url', 'store'],
            'history' => ['store', 'records', 'last_success', 'error'],
        ];
        foreach ($required[''] as $key) {
            self::assertArrayHasKey($key, $status);
        }
        foreach (['dispatch', 'debounce', 'history'] as $section) {
            self::assertIsArray($status[$section]);
            foreach ($required[$section] as $key) {
                self::assertArrayHasKey($key, $status[$section]);
            }
        }
        foreach ($status['hosts'] as $host) {
            self::assertSame(['host', 'forbidden', 'escalated'], array_keys($host));
        }
    }
}
