<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Controller;

use IndexNowKit\Key\KeyProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /{key}.txt -> the key itself. Only configured keys are served.
 */
final class KeyFileController
{
    public function __construct(private readonly KeyProviderInterface $keys, private readonly bool $enabled = true) {}

    public function __invoke(string $key): Response
    {
        if (!$this->enabled || !$this->keys->isKnownKey($key)) {
            throw new NotFoundHttpException();
        }

        return new Response($key, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
