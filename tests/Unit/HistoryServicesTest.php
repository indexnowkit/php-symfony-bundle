<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use IndexNowKit\SymfonyBundle\DependencyInjection\HistoryServices;
use IndexNowKit\SymfonyBundle\DependencyInjection\IndexNowKitConfiguration;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;

/**
 * The pieces of the history wiring that do not need a kernel: the PDO factories and the validation of the `history`
 * node (a DSN and a service together, a table name that is not an identifier, an unknown store).
 */
final class HistoryServicesTest extends TestCase
{
    public function testPdoFromDsnThrowsOnErrors(): void
    {
        $pdo = HistoryServices::pdoFromDsn('sqlite::memory:');

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testPdoFromConnectionTakesAPdoOrTheNativeConnectionOfADbalConnection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        self::assertSame($pdo, HistoryServices::pdoFromConnection($pdo));

        $connection = new class ($pdo) {
            public function __construct(private readonly PDO $pdo) {}

            public function getNativeConnection(): PDO
            {
                return $this->pdo;
            }
        };
        self::assertSame($pdo, HistoryServices::pdoFromConnection($connection));
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE), 'the store expects exceptions');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/history\.pdo\.service/');
        HistoryServices::pdoFromConnection(new stdClass());
    }

    public function testConnectionIdExpandsABareConnectionName(): void
    {
        self::assertSame('doctrine.dbal.default_connection', HistoryServices::connectionId('default'));
        self::assertSame('doctrine.dbal.archive_connection', HistoryServices::connectionId('archive'));
        self::assertSame('app.history_pdo', HistoryServices::connectionId('app.history_pdo'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBlocks(): iterable
    {
        yield 'dsn and service together' => [['store' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'service' => 'default']], 'not both'];
        yield 'table that is not an identifier' => [['store' => 'pdo', 'pdo' => ['table' => 'indexnow-submissions']], 'pdo.table'];
        yield 'unknown store' => [['store' => 'redis'], 'history.store'];
        yield 'reserved character in key_prefix' => [['store' => 'psr16', 'key_prefix' => 'seo:'], 'key_prefix'];
        yield 'limit below one' => [['store' => 'psr16', 'limit' => 0], 'limit'];
    }

    /**
     * @param array<string, mixed> $history
     */
    #[DataProvider('invalidBlocks')]
    #[TestDox('the history node rejects $_dataName at compile time')]
    public function testTheNodeRejects(array $history, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        self::process(['key' => TestKernel::KEY, 'base_url' => 'https://www.example.com', 'history' => $history]);
    }

    public function testTheNodeFillsTheDefaults(): void
    {
        $processed = self::process(['key' => TestKernel::KEY, 'base_url' => 'https://www.example.com', 'history' => ['store' => 'pdo']]);

        self::assertSame(['store' => 'pdo', 'limit' => 500, 'key_prefix' => null, 'pdo' => ['dsn' => null, 'service' => null, 'table' => 'indexnow_submissions'], 'retention_days' => 90], $processed['history']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function process(array $config): array
    {
        $builder = new TreeBuilder('indexnowkit');
        (new IndexNowKitConfiguration(true, true, true))->build(new DefinitionConfigurator($builder, new DefinitionFileLoader($builder, new FileLocator()), __FILE__, __FILE__));

        return (new Processor())->process($builder->buildTree(), [$config]);
    }
}
