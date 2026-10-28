<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Toda la API responde errores como JSON {error: "..."} en lugar de la página HTML de Symfony. */
#[AsEventListener(event: 'kernel.exception', priority: 10)]
class ApiExceptionListener
{
    public function __construct(private TokenStorageInterface $tokens, private bool $debug = false)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof AuthenticationException || ($e instanceof AccessDeniedException && !$this->tokens->getToken()?->getUser())) {
            $status = 401;
            $message = 'Authentication required.';
        } elseif ($e instanceof AccessDeniedException) {
            $status = 403;
            $message = 'You do not have permission to do this.';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: match ($status) { 404 => 'Not found.', default => 'Request error.' };
        } else {
            $status = 500;
            $message = $this->debug ? $e->getMessage() : 'Unexpected server error.';
        }
        $event->setResponse(new JsonResponse(['error' => $message], $status));
    }
}
