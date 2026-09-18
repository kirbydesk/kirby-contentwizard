<?php

namespace Kirbydesk\Contentwizard;

use pwConfig;

/**
 * Picks the theme whose text reads best on a given background.
 *
 * The text colour of each theme is the heading colour from the Project
 * Wizard (content/.projectwizard/elements.json, falling back to
 * pagewizard's defaults). The background is described by its relative
 * luminance (0 = black, 1 = white), measured from the photo or video and
 * darkened by the block's black overlay.
 */
final class ThemeContrast
{
    public const THEMES = ['default', 'variant', 'variant2', 'variant3'];

    /**
     * @param list<string> $candidates theme values allowed for the block
     */
    public static function best(float $luminance, array $candidates): ?string
    {
        $best  = null;
        $ratio = 0.0;
        foreach (array_intersect($candidates, self::THEMES) as $theme) {
            $color = self::textColor($theme);
            if ($color === null) continue;

            $r = self::ratio(self::luminance($color), $luminance);
            if ($r > $ratio) {
                $ratio = $r;
                $best  = $theme;
            }
        }
        return $best;
    }

    /** Heading text colour of a theme (hex) or null. */
    public static function textColor(string $theme): ?string
    {
        $override = pwConfig::projectOverride('elements')['global'][$theme]['element-heading-text'] ?? null;
        if (is_string($override) && $override !== '') return $override;

        $default = pwConfig::pluginConfig('elements')['heading']['colors']['element-heading-text'][$theme] ?? null;
        return is_string($default) && $default !== '' ? $default : null;
    }

    /** Luminance behind the text after a black overlay of $intensity (0–100). */
    public static function withOverlay(float $luminance, string $overlay, int $intensity): float
    {
        $alpha = max(0, min(100, $intensity)) / 100;
        return match ($overlay) {
            'solid'    => $luminance * (1 - $alpha),
            // a gradient covers the text side fully, the rest less
            'gradient' => $luminance * (1 - $alpha * 0.75),
            default    => $luminance,
        };
    }

    /** WCAG relative luminance of a hex colour (#rgb, #rrggbb, #rrggbbaa). */
    public static function luminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        if (strlen($hex) < 6) return 0.0;

        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($hex, 0, 6), 2));
        return self::channelLuminance($r, $g, $b);
    }

    public static function channelLuminance(float $r, float $g, float $b): float
    {
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    private static function ratio(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
