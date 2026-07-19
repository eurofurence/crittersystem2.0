<?php

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central QR code module - reusable wrapper around endroid/qr-code. Used by
 * the digital ID feature, certification check-in QRs, and anything else that
 * needs a server-rendered QR code (PNG or inline data URI).
 */
final class QrCodeGenerator
{
    public function build(string $data, int $size = 280, int $margin = 10): ResultInterface
    {
        return (new Builder(
            writer: new PngWriter(),
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: $margin,
        ))->build();
    }

    /** A `Response` serving the QR as a PNG (with no-store cache headers - tokens rotate). */
    public function pngResponse(string $data, int $size = 280, int $margin = 10): Response
    {
        $result = $this->build($data, $size, $margin);

        return new Response($result->getString(), Response::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'no-store, must-revalidate',
        ]);
    }

    /** `data:image/png;base64,...` for inline embedding in a template. */
    public function dataUri(string $data, int $size = 280, int $margin = 10): string
    {
        return $this->build($data, $size, $margin)->getDataUri();
    }
}
