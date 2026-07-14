<?php

namespace App\Tests\Unit\Pdf;

use App\Pdf\PdfRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class PdfRendererTest extends TestCase
{
    private function renderer(array $templates = []): PdfRenderer
    {
        return new PdfRenderer(new Environment(new ArrayLoader($templates)));
    }

    public function testRenderHtmlProducesPdfBytes(): void
    {
        $pdf = $this->renderer()->renderHtml('<h1>Schedule</h1><p>Hello</p>');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertNotEmpty($pdf);
    }

    public function testRenderTemplateUsesTwig(): void
    {
        $renderer = $this->renderer(['doc.html.twig' => '<h1>{{ title }}</h1>']);

        $pdf = $renderer->renderTemplate('doc.html.twig', ['title' => 'Matrix']);

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
