<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // behind Northflank's load balancer: trust X-Forwarded-* so URLs are https
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\ApplyPlatformTimezone::class);
        // refuse a role without permission BEFORE route-model binding can answer 404, so a missing record never leaks
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnforceModuleAccess::class,
        );
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'conditional.get' => \App\Http\Middleware\ConditionalGet::class,
            'access.window' => \App\Http\Middleware\EnforceAccessWindow::class,
            'module.access' => \App\Http\Middleware\EnforceModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->expectsJson() || $request->is('api/*');
        });
    })
    ->create();