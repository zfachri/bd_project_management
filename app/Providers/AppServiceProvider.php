<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Scramble::extendOpenApi(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'JWT')
            );
        });

        // Only document API routes
        Scramble::routes(function (Route $route) {
            return Str::startsWith($route->uri, 'api/');
        });

        // Ignore specific routes if needed
        Scramble::ignoredefaultRoutes(function (Route $route) {
            return Str::startsWith($route->uri, 'api/internal/');
        });
    }
}