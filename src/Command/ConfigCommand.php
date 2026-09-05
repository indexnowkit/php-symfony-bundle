<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\SymfonyBundle\DependencyInjection\ConfigFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:config', description: 'Print the effective IndexNow configuration: defaults and environment applied, keys masked (paste it into a bug report)')]
final class ConfigCommand extends Command
{
    /**
     * @param array<string, mixed>  $rawConfig bundle config with env placeholders resolved
     * @param array<string, object> $packages  the configuration object of every installed optional package by block
     *                                         name (`verify` => `VerifyConfig`); each has `toArray()`
     */
    public function __construct(private readonly ConfigRunner $runner, private readonly array $rawConfig, private readonly string $environment, private readonly array $packages = [])
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::config()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packages = [];
        foreach ($this->packages as $name => $config) {
            if (method_exists($config, 'toArray')) {
                /** @var array<string, mixed> $block */
                $block = $config->toArray();
                $packages[$name] = $block;
            }
        }

        return $this->runner->run(new SymfonyStyle($input, $output), fn(): \IndexNowKit\Config => ConfigFactory::build($this->rawConfig, $this->environment), $this->rawConfig, (bool) $input->getOption('json'), $packages);
    }
}
