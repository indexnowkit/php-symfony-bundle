<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `indexnow:sitemap` while `indexnowkit/sitemap` is not installed: a cron that ran the command before the package
 * went optional gets a sentence and exit 1 instead of "command not found". Every argument and option is accepted
 * and ignored.
 */
#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (needs indexnowkit/sitemap, which is not installed)')]
final class SitemapNotInstalledCommand extends Command
{
    public const MESSAGE = 'indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap';

    protected function configure(): void
    {
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>' . self::MESSAGE . '</error>'); // one line, not a wrapped block: a cron log greps it

        return ExitCode::FAILURE;
    }
}
