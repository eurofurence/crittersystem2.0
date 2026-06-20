<?php

namespace App\Tests\Unit\Service;

use App\Service\QrCodeGenerator;
use PHPUnit\Framework\TestCase;

final class QrCodeGeneratorTest extends TestCase
{
    public function testProducesAPngWithCorrectHeaderAndMimeType(): void
    {
        $generator = new QrCodeGenerator();
        $response = $generator->pngResponse('https://example.com/verify/abc', 200, 8);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        // PNG signature: \x89 P N G \r \n \x1a \n
        self::assertSame("\x89PNG\r\n\x1a\n", substr((string) $response->getContent(), 0, 8));
    }

    public function testDataUriEmbedsPngBase64(): void
    {
        self::assertStringStartsWith('data:image/png;base64,', (new QrCodeGenerator())->dataUri('hi'));
    }
}
