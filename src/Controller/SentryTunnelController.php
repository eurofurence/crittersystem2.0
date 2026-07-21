<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SentryTunnelController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $sentryDsn,
    ) {
    }

    #[Route('/sentry-tunnel', name: 'sentry_tunnel', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Tunnel disabled if no DSN is configured.
        if ($this->sentryDsn === '') {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $envelope = $request->getContent();
        if ($envelope === '') {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // The envelope's first line is a JSON header that carries the target DSN.
        $newline = strpos($envelope, "\n");
        $headerLine = $newline === false ? $envelope : substr($envelope, 0, $newline);

        try {
            $header = json_decode($headerLine, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $incomingDsn = $header['dsn'] ?? null;
        if (!\is_string($incomingDsn) || $incomingDsn === '') {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // SECURITY: only relay envelopes addressed to OUR DSN. Without this the
        // endpoint is an open relay to any Sentry project (SSRF/abuse).
        if (!hash_equals($this->sentryDsn, $incomingDsn)) {
            $this->logger->warning('Sentry tunnel: rejected envelope with mismatched DSN.');
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        // Build the upstream URL from the TRUSTED configured DSN, not the browser value.
        $parts = parse_url($this->sentryDsn);
        if (!\is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            $this->logger->error('Sentry tunnel: configured sentry_dsn is malformed.');
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $projectId = trim($parts['path'], '/');
        if ($projectId === '' || !ctype_digit($projectId)) {
            $this->logger->error('Sentry tunnel: could not derive project id from sentry_dsn.');
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $upstream = sprintf('%s://%s/api/%s/envelope/', $scheme, $host, $projectId);

        try {
            $response = $this->httpClient->request('POST', $upstream, [
                'headers' => ['Content-Type' => 'application/x-sentry-envelope'],
                'body' => $envelope,
                'timeout' => 15,
            ]);

            // Relay Sentry's status back to the SDK.
            return new Response(
                $response->getContent(false),
                $response->getStatusCode(),
                ['Content-Type' => $response->getHeaders(false)['content-type'][0] ?? 'application/json'],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Sentry tunnel: forward failed: {msg}', ['msg' => $e->getMessage()]);
            return new Response('', Response::HTTP_BAD_GATEWAY);
        }
    }
}