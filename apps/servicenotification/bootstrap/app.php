<?php

use App\Application\Exception\IdempotencyConflictException;
use App\Console\Commands\PublishOutboxMessagesCommand;
use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$errorCodeForStatus = static function (int $status): string {
    return match ($status) {
        Response::HTTP_BAD_REQUEST => 'bad_request',
        Response::HTTP_UNAUTHORIZED => 'unauthorized',
        Response::HTTP_FORBIDDEN => 'forbidden',
        Response::HTTP_NOT_FOUND => 'not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
        Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
        default => $status >= 500 ? 'internal_error' : 'http_error',
    };
};

$errorResponse = static function (Request $request, int $status, string $code, string $message, ?array $details = null) {
    $requestId = $request->attributes->get('request_id')
        ?: $request->headers->get('X-Request-Id')
        ?: (string) Str::uuid();

    $request->attributes->set('request_id', $requestId);

    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== null && $details !== []) {
        $error['details'] = $details;
    }

    return response()->json([
        'error' => $error,
        'meta' => [
            'request_id' => $requestId,
        ],
    ], $status)->header('X-Request-Id', $requestId);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            RequestIdMiddleware::class,
        ]);
    })
    ->withCommands([
        PublishOutboxMessagesCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions) use ($errorCodeForStatus, $errorResponse): void {
        $exceptions->dontReport([
            IdempotencyConflictException::class,
            ValidationException::class,
        ]);

        $exceptions->render(function (IdempotencyConflictException $exception, Request $request) use ($errorResponse) {
            return $errorResponse(
                request: $request,
                status: Response::HTTP_CONFLICT,
                code: 'idempotency_conflict',
                message: $exception->getMessage(),
                details: [
                    'idempotency_key' => $exception->idempotencyKey,
                ],
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($errorResponse) {
            return $errorResponse(
                request: $request,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: 'validation_error',
                message: 'Переданы некорректные параметры запроса.',
                details: $exception->errors(),
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($errorCodeForStatus, $errorResponse) {
            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();

                return $errorResponse(
                    request: $request,
                    status: $status,
                    code: $errorCodeForStatus($status),
                    message: $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : Response::$statusTexts[$status] ?? 'HTTP error.',
                );
            }

            return $errorResponse(
                request: $request,
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
                code: 'internal_error',
                message: 'Внутренняя ошибка сервиса.',
            );
        });
    })->create();
