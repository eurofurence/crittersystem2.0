<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shipped nginx configurations, of which there are two: the Compose front (docker/nginx) and
 * the in-pod sidecar (deploy/k8s/configmap.yaml).
 *
 * They drift, and the drift is invisible until production breaks. nginx buffers a FastCGI response
 * header block in a single buffer and answers 502 when it does not fit; its default is one page,
 * which a signed-in page's Set-Cookie headers can exceed. The Compose config was raised after that
 * happened and the Kubernetes one was not, so signing in through SSO returned 502 in production
 * while every test stayed green.
 */
final class DeploymentNginxConfigTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function configs(): iterable
    {
        yield 'compose' => [__DIR__.'/../../docker/nginx/default.conf'];
        yield 'kubernetes sidecar' => [__DIR__.'/../../deploy/k8s/configmap.yaml'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configs')]
    public function testTheFastcgiHeaderBufferIsRaisedAboveTheDefaultPage(string $path): void
    {
        $config = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/fastcgi_buffer_size\s+(\d+)k;/',
            $config,
            $path.' does not raise fastcgi_buffer_size, so a large Set-Cookie header answers 502',
        );

        preg_match('/fastcgi_buffer_size\s+(\d+)k;/', $config, $matches);
        self::assertGreaterThanOrEqual(
            16,
            (int) $matches[1],
            'a page can carry several kilobytes of Set-Cookie; the nginx default of one page is not enough',
        );
    }
}
