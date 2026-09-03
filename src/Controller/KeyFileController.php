<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Controller;

use IndexNowKit\Key\KeyFileResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /{key}.txt -> the key itself. Only keys of the requested host are served (404 otherwise).
 */
final class KeyFileController
{
    /**
     * @param bool $varyHost `Vary: Host` on the response (set when a `hosts` map is configured)
     */
    public function __construct(private readonly KeyFileResponder $responder, private readonly int $maxAge = KeyFileResponder::DEFAULT_MAX_AGE, private readonly bool $varyHost = false) {}

    public function __invoke(Request $request, string $key): Response
    {
        $body = $this->responder->bodyForKey($key, $request->getHost());
        if ($body === null) {
            throw new NotFoundHttpException();
        }

        return new Response($body, 200, KeyFileResponder::headers($this->maxAge, $this->varyHost));
    }
}
