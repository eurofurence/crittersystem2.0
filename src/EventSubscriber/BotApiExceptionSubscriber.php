<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Bot\ActingUserNotLinkedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders HTTP exceptions raised on the /api/bot surface as JSON, so the bot
 * always gets a decodable `{error, message}` body instead of an HTML error page.
 *
 * The `error` slug is the machine-readable contract the bot switches on - most
 * importantly `acting_user_not_linked`, which tells it a link was revoked so it
 * can drop its stale local record rather than surface a generic failure.
 */
final class BotApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/bot')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $status = $exception->getStatusCode();
        $event->setResponse(new JsonResponse([
            'error' => $this->errorCode($exception, $status),
            'message' => $exception->getMessage(),
        ], $status));
    }

    private function errorCode(HttpExceptionInterface $exception, int $status): string
    {
        if ($exception instanceof ActingUserNotLinkedException) {
            return ActingUserNotLinkedException::ERROR_CODE;
        }

        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            default => 'error',
        };
    }
}
