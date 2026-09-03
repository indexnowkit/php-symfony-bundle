<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Routing;

use IndexNowKit\Key\KeyValidator;
use IndexNowKit\SymfonyBundle\Controller\KeyFileController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The key file route (`key_file.path`, `key_file.host`, `key_file.route_name`), built from the resolved config so the
 * name is configurable too (a route name cannot take a `%parameter%`). `config/routes.php` imports it as a service.
 */
final class KeyFileRouteLoader
{
    public function __construct(private readonly string $name, private readonly string $path, private readonly string $host) {}

    public function __invoke(): RouteCollection
    {
        $route = new Route(
            $this->path,
            ['_controller' => KeyFileController::class],
            ['key' => '[' . KeyValidator::ALPHABET . ']{' . KeyValidator::MIN_LENGTH . ',' . KeyValidator::MAX_LENGTH . '}'],
            [],
            $this->host,
            [],
            ['GET'],
        );
        $routes = new RouteCollection();
        $routes->add($this->name, $route);

        return $routes;
    }
}
