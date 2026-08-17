<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SentryTestController extends AbstractController 
{

    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Checks the Sentry wiring end to end: it logs an error to exercise the Monolog integration and
     * then throws, so uncaught-exception reporting is exercised too. The exception is deliberate.
     */
    #[Route(path: '/_sentry-test', name: 'sentry_test')]
    public function testLog(): Response
    {
        $this->logger->error('My custom logged error.');

        throw new \RuntimeException('Example exception.');
    }
}
