<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        require __DIR__.'/middleware.php';
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // JSON-only API: never render HTML error pages.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Business errors (401/403/404/409/422/503) are expected outcomes, not incidents.
        $exceptions->dontReport(ApiException::class);

        $json = fn (int $status, string $code, string $message, array $details = []) => response()->json(
            ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null])],
            $status,
        );

        $exceptions->render(fn (ValidationException $e) => $json(
            422, 'VALIDATION_ERROR', 'Input tidak valid', ['fields' => array_map(fn ($m) => $m[0], $e->errors())],
        ));
        $exceptions->render(fn (NotFoundHttpException $e) => $e->getPrevious() instanceof ModelNotFoundException
            ? $json(404, 'NOT_FOUND', 'Data tidak ditemukan')
            : $json(404, 'NOT_FOUND', 'Endpoint tidak ditemukan'));
        $exceptions->render(fn (AuthorizationException $e) => $json(403, 'FORBIDDEN', $e->getMessage()));
        $exceptions->render(function (Throwable $e) use ($json) {
            if ($e instanceof ApiException) {
                return $e->render();
            }
            if ($e instanceof HttpExceptionInterface) {
                return $json($e->getStatusCode(), 'HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Request tidak dapat diproses');
            }

            // Unexpected error: logged by the reporter; show the stack trace only in debug mode.
            return config('app.debug') ? null : $json(500, 'INTERNAL_ERROR', 'Terjadi kesalahan pada server');
        });
    })->create();
