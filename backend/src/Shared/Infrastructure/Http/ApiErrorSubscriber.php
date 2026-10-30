<?php

namespace App\Shared\Infrastructure\Http;

use App\Identity\Application\Command\AccessAuthorizationDenied;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiErrorSubscriber
{
    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = match (true) {
            $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof IdempotencyConflict => 409,
            $exception instanceof AccessAuthorizationDenied => 403,
            $exception instanceof AccessMutationFailed => 422,
            default => 500,
        };
        $code = match (true) {
            $exception instanceof IdempotencyConflict => 'idempotency_conflict',
            $exception instanceof AccessAuthorizationDenied => 'forbidden',
            $exception instanceof AccessMutationFailed => 'access_mutation_failed',
            $status >= 500 => 'internal_error',
            default => 'request_error',
        };

        $response = new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $status >= 500 ? 'Ocurrió un error interno.' : $exception->getMessage(),
            ],
        ], $status);
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }

        $event->setResponse($response);
    }
}
