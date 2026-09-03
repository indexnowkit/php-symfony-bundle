<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * How submissions are wired in this application: is the Messenger message routed to a transport, is the Doctrine
 * listener active. The failures the core cannot see; printed by `indexnow:check` after the built-in lines.
 */
final class WiringCheck implements CheckInterface
{
    public function __construct(
        private readonly string $dispatchMode,
        private readonly bool $messengerRouted,
        private readonly bool $doctrineHooked,
    ) {}

    public function check(CheckReport $report): void
    {
        if ($this->dispatchMode === 'messenger' && !$this->messengerRouted) {
            $report->warning('dispatch is "messenger" but SubmitUrlsMessage is not routed to a transport: it is handled synchronously, 429/5xx are not retried. Set indexnowkit.messenger.transport or add framework.messenger.routing.');
        }
        if ($this->doctrineHooked) {
            $report->ok('doctrine: entity changes are submitted automatically (onFlush/postFlush + commit-safe middleware)');
        } else {
            $report->warning('doctrine: entity hooks are NOT active (needs indexnowkit/doctrine + doctrine/doctrine-bundle, doctrine.enabled: true and enabled: true); use indexnow:submit or $indexNow->submit()');
        }
    }
}
