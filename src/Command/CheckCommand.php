<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\SymfonyBundle\Check\SampleOptions;
use IndexNowKit\SymfonyBundle\DependencyInjection\ConfigFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:check', description: 'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired')]
final class CheckCommand extends Command
{
    /**
     * @param array<string, mixed> $rawConfig bundle config with env placeholders resolved
     */
    public function __construct(private readonly CheckRunner $runner, private readonly array $rawConfig, private readonly string $environment, private readonly ?SampleOptions $samples = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::check()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hosts = $input->getOption('host');
        $probeUrl = $input->getOption('probe-url');
        if ($this->samples !== null) {
            $this->samples->urls = self::strings($input->getOption('sample'));
            $this->samples->classes = self::strings($input->getOption('sample-class'));
        }

        return $this->runner->run(
            new SymfonyStyle($input, $output),
            fn(): mixed => ConfigFactory::build($this->rawConfig, $this->environment),
            (bool) $input->getOption('live'),
            \is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : (\is_string($hosts) ? $hosts : null),
            \is_string($probeUrl) ? $probeUrl : null,
            (bool) $input->getOption('json'),
            (bool) $input->getOption('strict'),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $option): array
    {
        return \is_array($option) ? array_values(array_filter($option, static fn(mixed $v): bool => \is_string($v) && $v !== '')) : [];
    }
}
