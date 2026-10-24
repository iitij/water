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
        // يسجل جميع طلبات الـ API وزمن الاستجابة وأي أخطاء بين التطبيق والخادم
        $middleware->api(prepend: [
            \App\Http\Middleware\LogApiRequests::class,
        ]);
        // يرفض أي طلب من جهاز أُبطل (خطة 2.0 — الفصل 9.7).
        $middleware->api(append: [
            \App\Http\Middleware\EnsureDeviceIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
