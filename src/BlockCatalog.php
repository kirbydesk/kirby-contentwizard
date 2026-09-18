<?php

namespace Kirbydesk\Contentwizard;

use Kirby\Cms\Blueprint;
use Kirby\Cms\Fieldset;
use Kirby\Cms\ModelWithContent;
use Kirby\Toolkit\I18n;
use pwConfig;
use Throwable;

/**
 * Describes the blocks the generator may use: every block activated in
 * the project (pagewizard's project config) with the content fields
 * Claude can fill. Nothing is hard-coded — a newly installed kirbyblock
 * plugin shows up automatically.
 *
 * Only the block's `content` tab is considered; layout, style, grid and
 * settings keep the project defaults. Fields Claude cannot provide
 * (files, links, icons, …) are left out, and a block that depends on
 * such a field (e.g. media, buttons) is left out as a whole.
 *
 * Catalog entry:
 *   [
 *     'type'   => 'pwsteplist',
 *     'label'  => 'Step List',
 *     'hint'   => 'Use for …' | null,   // i18n key "<plugin>.ai"
 *     'fields' => [name => field spec],
 *   ]
 *
 * Field spec `kind`: pwtext | pweditor | text | textarea | html | structure | blocks | image
 * | background (pseudo field "_background": video/image behind the block)
 */
final class BlockCatalog
{
    /** Required fields of these types make a whole block unusable. */
    private const BLOCKING_TYPES = ['link', 'url'];

    /**
     * Field types that are skipped silently (defaults are kept). Choice
     * fields are presentation (alignment, size, style) and stay on the
     * project defaults; they would also bloat the response schema.
     */
    private const IGNORED_TYPES = ['headline', 'hidden', 'info', 'line', 'gap', 'pwalign', 'pwicon', 'toggle', 'toggles', 'select', 'radio', 'multiselect', 'color', 'range', 'number', 'link', 'url'];

    /** Pseudo field for a block's background video/image (style tab). */
    public const BACKGROUND = '_background';

    /** Nested blocks deeper than this are not described. */
    private const MAX_DEPTH = 3;

    /** @var array<string, array|null> described entries by block type */
    private array $entries = [];

    /**
     * @param list<string> $exclude Block types the generator must not use
     * @param bool $images Whether single-image fields can be filled (Pexels)
     */
    public function __construct(
        private readonly ModelWithContent $model,
        private readonly array $exclude = [],
        private readonly bool $images = false,
    ) {
    }

    /**
     * @return list<array> Catalog entries for all usable top-level blocks.
     */
    public function blocks(): array
    {
        // Blueprint labels are resolved in the current panel language;
        // the prompt is written in English, so resolve them in English.
        $locale = I18n::$locale;
        I18n::$locale = 'en';

        try {
            $entries = [];
            foreach (pwConfig::projectConfig('blocks') as $type) {
                if (in_array($type, $this->exclude, true)) continue;
                $entry = $this->describe($type, 1);
                if ($entry !== null) $entries[] = $entry;
            }
            return $entries;
        } finally {
            I18n::$locale = $locale;
        }
    }

    /**
     * Resolved content-tab fields of a block type (Kirby field props).
     */
    public function contentFields(string $type): array
    {
        $fieldset = $this->fieldset($type);
        return $fieldset?->tabs()['content']['fields'] ?? [];
    }

    /** Catalog entry of a (nested) block type described by blocks(). */
    public function entry(string $type): ?array
    {
        return $this->entries[$type] ?? null;
    }

    /** All fields of a block type across tabs (Kirby field props). */
    public function allFields(string $type): array
    {
        return $this->fieldset($type)?->fields() ?? [];
    }

    /** Background spec of a block type, or null. */
    public function backgroundOf(string $type): ?array
    {
        return $this->backgroundSpec($type);
    }

    private function fieldset(string $type): ?Fieldset
    {
        try {
            $props = Blueprint::find('blocks/' . $type);
            return new Fieldset(array_merge($props, ['type' => $type, 'parent' => $this->model]));
        } catch (Throwable) {
            return null;
        }
    }

    private function describe(string $type, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) return null;
        return $this->entries[$type] = $this->build($type, $depth);
    }

    private function build(string $type, int $depth): ?array
    {
        try {
            $props = Blueprint::find('blocks/' . $type);
        } catch (Throwable) {
            return null;
        }

        $fields   = [];
        $hasFiles = false;
        foreach ($this->contentFields($type) as $name => $field) {
            $fieldType = $field['type'] ?? 'text';

            if (in_array($fieldType, self::BLOCKING_TYPES, true) && !empty($field['required'])) return null;
            if (in_array($fieldType, self::IGNORED_TYPES, true)) continue;

            if ($fieldType === 'files') {
                $hasFiles = true;
                if ($this->images && self::isSingleImage($field)) {
                    $fields[$name] = ['kind' => 'image', 'label' => self::english($field['label'] ?? null), 'required' => false];
                }
                continue;
            }

            $spec = $this->fieldSpec($field, $depth);
            if ($spec !== null) $fields[$name] = $spec;
        }

        // A block without anything to write is of no use — and a media
        // block whose files cannot be filled would stay empty.
        if ($fields === []) return null;
        if ($hasFiles && !in_array('image', array_column($fields, 'kind'), true)) return null;

        if ($this->images) {
            // Single images outside the content tab (e.g. a card's image
            // in the style tab) are decoration: filled when possible.
            foreach ($this->fieldset($type)?->fields() ?? [] as $name => $field) {
                if (isset($fields[$name]) || ($field['type'] ?? null) !== 'files') continue;
                if (!empty($field['when']) || !self::isSingleImage($field)) continue;
                if (array_key_exists($name, $this->contentFields($type))) continue;
                $fields[$name] = ['kind' => 'image', 'label' => self::english($field['label'] ?? null), 'required' => false, 'optional' => true];
            }

            if (($background = $this->backgroundSpec($type)) !== null) {
                $fields[self::BACKGROUND] = $background;
            }
        }

        $nameKey = is_string($props['name'] ?? null) ? $props['name'] : null;

        return [
            'type'   => $type,
            'label'  => self::english($nameKey) ?? $type,
            'hint'   => $nameKey !== null && str_ends_with($nameKey, '.name')
                ? self::english(substr($nameKey, 0, -5) . '.ai')
                : null,
            'fields' => $fields,
        ];
    }

    private function fieldSpec(array $field, int $depth): ?array
    {
        $label = self::english($field['label'] ?? null);
        $base  = ['label' => $label, 'required' => !empty($field['required'])];

        return match ($field['type']) {
            'pwtext'   => $base + ['kind' => 'pwtext', 'field' => $field],
            'pweditor' => $base + ['kind' => 'pweditor', 'mode' => $field['defaultMode'] ?? 'writer', 'field' => $field],
            'text', 'slug', 'email', 'tel' => $base + ['kind' => 'text'],
            'textarea' => $base + ['kind' => 'textarea'],
            'writer'   => $base + ['kind' => 'html'],
            'structure' => $this->structureSpec($field, $base),
            'blocks'    => $this->blocksSpec($field, $base, $depth),
            default     => null,
        };
    }

    /**
     * pagewizard's background pattern (e.g. hero, style tab): a
     * `backgroundtype` toggle plus files fields shown for "video" / "image".
     */
    private function backgroundSpec(string $type): ?array
    {
        $all = $this->fieldset($type)?->fields() ?? [];
        if (($all['backgroundtype']['type'] ?? null) !== 'toggles') return null;

        $spec = ['kind' => 'background', 'label' => 'Background', 'required' => false, 'typeField' => 'backgroundtype'];
        foreach ($all as $name => $field) {
            if (($field['type'] ?? null) !== 'files') continue;
            $when = array_change_key_case($field['when'] ?? []);
            if (($when['backgroundtype'] ?? null) === 'video') $spec['video'] = $name;
            if (($when['backgroundtype'] ?? null) === 'image') $spec['image'] = $name;
        }

        return isset($spec['video']) || isset($spec['image']) ? $spec : null;
    }

    /** A files field that takes exactly one image. */
    private static function isSingleImage(array $field): bool
    {
        if (($field['multiple'] ?? true) !== false && ($field['max'] ?? null) !== 1) return false;

        $accept   = (string) ($field['uploads']['accept'] ?? '');
        $template = (string) ($field['uploads']['template'] ?? '');
        return str_contains($accept, '.jpg') || str_contains($template, 'Image');
    }

    /** Structures whose sub-fields are all plain text (e.g. list items). */
    private function structureSpec(array $field, array $base): ?array
    {
        $columns = [];
        foreach ($field['fields'] ?? [] as $name => $sub) {
            if (!in_array($sub['type'] ?? 'text', ['text', 'textarea'], true)) return null;
            $columns[$name] = self::english($sub['label'] ?? null);
        }
        return $columns === [] ? null : $base + ['kind' => 'structure', 'columns' => $columns];
    }

    private function blocksSpec(array $field, array $base, int $depth): ?array
    {
        $types = [];
        foreach (array_keys($field['fieldsets'] ?? []) as $type) {
            $entry = $this->describe($type, $depth + 1);
            if ($entry !== null) $types[] = $entry;
        }
        return $types === [] ? null : $base + ['kind' => 'blocks', 'blocks' => $types];
    }

    /**
     * Resolve a label/i18n key in English (prompt language). Returns null
     * for empty values and for keys without a translation.
     */
    private static function english(mixed $value): ?string
    {
        if (is_array($value)) $value = $value['en'] ?? $value['*'] ?? reset($value);
        if (!is_string($value) || $value === '') return null;

        $translated = I18n::translate($value, null, 'en');
        if (is_string($translated) && $translated !== '') return strip_tags($translated);

        // plain labels (not i18n keys) are fine as they are
        return str_contains($value, '.') && !str_contains($value, ' ') ? null : $value;
    }
}
