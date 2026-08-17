<?php

namespace App\Tests\Unit\Service;

use App\Entity\Location;
use App\Service\EmbedSanitizer;
use PHPUnit\Framework\TestCase;

final class EmbedSanitizerTest extends TestCase
{
    private function sanitizer(): EmbedSanitizer
    {
        return new EmbedSanitizer('www.google.com, openstreetmap.org');
    }

    /**
     * An embed URL is accepted only over https and only for a listed host or a subdomain of one;
     * every other host is refused.
     */
    public function testUrlAllowRules(): void
    {
        $s = $this->sanitizer();
        self::assertTrue($s->isAllowedUrl('https://www.google.com/maps?q=1'));
        self::assertTrue($s->isAllowedUrl('https://sub.openstreetmap.org/x'));
        self::assertFalse($s->isAllowedUrl('http://www.google.com/maps'));
        self::assertFalse($s->isAllowedUrl('https://evil.example.com/maps'));
    }

    public function testEmbedSrcFromMapUrl(): void
    {
        $location = (new Location('Hall'))->setMapUrl('https://www.google.com/maps?q=hall');
        self::assertSame('https://www.google.com/maps?q=hall', $this->sanitizer()->embedSrc($location));
    }

    public function testEmbedSrcExtractsAllowedIframe(): void
    {
        $location = (new Location('Hall'))->setEmbedHtml('<iframe src="https://openstreetmap.org/export/embed.html?x=1" style="border:0"></iframe>');
        self::assertSame('https://openstreetmap.org/export/embed.html?x=1', $this->sanitizer()->embedSrc($location));
    }

    public function testDisallowedIframeIsRejected(): void
    {
        $location = (new Location('Hall'))->setEmbedHtml('<iframe src="https://evil.example.com/x"></iframe>');
        self::assertNull($this->sanitizer()->embedSrc($location));
    }
}
