<?php

namespace Kirbydesk\Contentwizard;

use Kirby\Data\Json;
use Kirby\Filesystem\F;
use pwConfig;

/**
 * AI defaults of a project. Edited in the Project Wizard ("AI" tab) and
 * stored next to the other project settings in
 * content/.projectwizard/contentwizard.json. Missing values fall back to
 * the plugin defaults below.
 */
final class Settings
{
    public const DEFAULTS = [
        // Description of the website: topic, audience, voice.
        'project' => '',
        // Additional writing rules, one per line.
        'rules' => '',
        // Block types the generator must not use.
        'exclude' => ['pwmulticolumn', 'pwheading', 'pwmedia'],
        // Themes the generated sections alternate between ('' = off).
        'themeA' => 'default',
        'themeB' => 'variant',
        // Blocks with a background video/photo (e.g. the hero).
        'backgroundHeight'  => 'large',
        'backgroundOverlay' => 'solid',
        'backgroundOverlayIntensity' => 40,
        // 'auto' = theme with the best contrast to the measured background
        'backgroundTheme' => 'auto',
    ];

    private array $values;

    public function __construct(?array $values = null)
    {
        $this->values = array_replace(self::DEFAULTS, $values ?? self::read());
    }

    public static function file(): string
    {
        return pwConfig::projectDir() . '/contentwizard.json';
    }

    public static function read(): array
    {
        $file = self::file();
        if (!is_file($file)) return [];

        try {
            $data = Json::read($file);
        } catch (\Throwable) {
            return [];
        }

        return array_intersect_key($data, self::DEFAULTS);
    }

    /** Store the given values (only known keys, normalised). */
    public static function write(array $values): array
    {
        $clean = (new self(array_intersect_key($values, self::DEFAULTS)))->all();
        F::write(self::file(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $clean;
    }

    public function all(): array
    {
        $v = $this->values;
        return [
            'project'                    => trim((string) $v['project']),
            'rules'                      => trim((string) $v['rules']),
            'exclude'                    => array_values(array_filter((array) $v['exclude'], 'is_string')),
            'themeA'                     => (string) $v['themeA'],
            'themeB'                     => (string) $v['themeB'],
            'backgroundHeight'           => (string) $v['backgroundHeight'],
            'backgroundOverlay'          => (string) $v['backgroundOverlay'],
            'backgroundOverlayIntensity' => max(0, min(100, (int) $v['backgroundOverlayIntensity'])),
            'backgroundTheme'            => (string) $v['backgroundTheme'] ?: 'auto',
        ];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<string> */
    public function exclude(): array
    {
        return $this->all()['exclude'];
    }

    /** @return list<string> themes to alternate between (empty = off) */
    public function themes(): array
    {
        $all = $this->all();
        return array_values(array_filter([$all['themeA'], $all['themeB']], fn ($t) => $t !== ''));
    }

    /** @return list<string> */
    public function rules(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->all()['rules']) ?: [])));
    }
}
