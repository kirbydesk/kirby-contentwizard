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
 * Field spec `kind`: pwtext | pweditor | text | html | structure | choice | blocks
 */
final class BlockCatalog
{
    /** Field types that make a whole block unusable for the generator. */
    private const BLOCKING_TYPES = ['files', 'link', 'url'];

    /** Field types that are skipped silently (defaults are kept). */
    private const IGNORED_TYPES = ['headline', 'hidden', 'info', 'line', 'gap', 'pwalign', 'pwicon', 'toggle', 'multiselect', 'color', 'range', 'number'];

    /** Nested blocks deeper than this are not described. */
    private const MAX_DEPTH = 3;

    public function __construct(
        private readonly ModelWithContent $model,
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

        try {
            $props = Blueprint::find('blocks/' . $type);
        } catch (Throwable) {
            return null;
        }

        $fields = [];
        foreach ($this->contentFields($type) as $name => $field) {
            $fieldType = $field['type'] ?? 'text';

            if (in_array($fieldType, self::BLOCKING_TYPES, true)) return null;
            if (in_array($fieldType, self::IGNORED_TYPES, true)) continue;

            $spec = $this->fieldSpec($field, $depth);
            if ($spec !== null) $fields[$name] = $spec;
        }

        // A block without anything to write is of no use.
        if ($fields === []) return null;

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
            'toggles', 'select', 'radio' => $this->choiceSpec($field, $base),
            'structure' => $this->structureSpec($field, $base),
            'blocks'    => $this->blocksSpec($field, $base, $depth),
            default     => null,
        };
    }

    private function choiceSpec(array $field, array $base): ?array
    {
        $values = [];
        foreach ($field['options'] ?? [] as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : $option;
            if (is_string($value) || is_int($value)) $values[] = (string) $value;
        }
        return $values === [] ? null : $base + ['kind' => 'choice', 'values' => $values];
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
