<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// The key file route: name, path and host come from indexnowkit.key_file.{route_name,path,host}.
return static function (RoutingConfigurator $routes): void {
    $routes->import('indexnowkit.key_file_routes::__invoke', 'service');
};
