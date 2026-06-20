<?php

namespace App\Twig;

use App\Theme\Theme;
use App\Theme\ThemeResolver;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the active {@see Theme} as `active_theme` to all templates so the
 * layout can emit `<html data-bs-theme=...>` and the theme stylesheet link.
 */
final class ThemeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly ThemeResolver $resolver)
    {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['active_theme' => $this->resolver->resolve()];
    }
}
