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

    // Symfony 8 uses native PHP attributes instead of PHPDoc annotations
    #[Route(path: '/_sentry-test', name: 'sentry_test')]
    public function testLog(): Response
    {
        // Tests if Monolog integration logs to Sentry
        $this->logger->error('My custom logged error.');

        // Tests if an uncaught exception logs to Sentry
        throw new \RuntimeException('Example exception.');
    }
}
