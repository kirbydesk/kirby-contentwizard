<?php

namespace Kirbydesk\Contentwizard;

use Kirby\Cms\Blueprint;
use Kirby\Cms\Fieldset;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Form\Form;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Turns the generator's answer into Kirby block data. Every block starts
 * with the defaults of its blueprint — exactly what the panel does when
 * an editor adds a block — so layout and style follow the project
 * settings. From the flat answer object only the fields of the block's
 * type are taken; pagewizard's own field types get their JSON envelope
 * (pwtext: align/level/size, pweditor: mode/align/size) and rich text is
 * converted to the format of the field (HTML, plain text).
 *
 * Images and background videos come from Pexels. A media block whose
 * image cannot be found is left out; decorative images (e.g. on cards)
 * are simply skipped.
 *
 * Top-level blocks alternate between the configured themes (e.g.
 * default / variant) so the sections of the page stand apart; a block
 * with a background video keeps its default theme.
 */
final class BlockBuilder
{
    /**
     * @param list<string> $themes theme values to alternate between
     * @param string|null $backgroundHeight block height when a background video/image is set
     */
    public function __construct(
        private readonly ModelWithContent $model,
        private readonly BlockCatalog $catalog,
        private readonly ?Pexels $pexels = null,
        private readonly ?string $language = null,
        private readonly array $themes = [],
        private readonly ?string $backgroundHeight = null,
    ) {
    }

    /**
     * @param list<array> $generated flat block objects from Generator
     * @param list<string>|null $allowed types allowed at this level (nested lists)
     * @return list<array> Kirby blocks
     */
    public function build(array $generated, ?array $allowed = null): array
    {
        $blocks = [];
        foreach ($generated as $item) {
            if (!is_array($item)) continue;

            $type = $item['type'] ?? null;
            if ($allowed !== null && !in_array($type, $allowed, true)) {
                $type = $allowed[0] ?? null;
            }

            $block = is_string($type) ? $this->block($type, $item) : null;
            if ($block !== null) $blocks[] = $block;
        }

        if ($allowed === null) $this->alternateThemes($blocks);

        return $blocks;
    }

    /** @param list<array> $blocks top-level blocks */
    private function alternateThemes(array &$blocks): void
    {
        if (count($this->themes) < 2) return;

        $i = 0;
        foreach ($blocks as &$block) {
            if (!array_key_exists('theme', $block['content'])) continue;
            if (in_array($block['content']['backgroundtype'] ?? null, ['image', 'video'], true)) continue;

            $theme   = $this->themes[$i++ % count($this->themes)];
            $options = array_column($this->catalog->allFields($block['type'])['theme']['options'] ?? [], 'value');
            if (in_array($theme, $options, true)) $block['content']['theme'] = $theme;
        }
    }

    private function block(string $type, array $item): ?array
    {
        $entry = $this->catalog->entry($type);
        if ($entry === null) return null;

        try {
            $props    = Blueprint::find('blocks/' . $type);
            $fieldset = new Fieldset(array_merge($props, ['type' => $type, 'parent' => $this->model]));
            $form     = new Form(fields: $fieldset->fields(), model: $this->model, language: 'current');
            $content  = $form->defaults();
        } catch (Throwable) {
            return null;
        }

        $fields = $this->catalog->allFields($type);
        $filled = false;

        foreach ($entry['fields'] as $name => $spec) {
            switch ($spec['kind']) {
                case 'background':
                    $this->background($content, $type, $item[Generator::BACKGROUND] ?? null);
                    break;

                case 'image':
                    $image = $this->image($item[Generator::IMAGE] ?? null);
                    if ($image !== null) {
                        $content[$name] = [$image];
                    } elseif (empty($spec['optional'])) {
                        // the image is what the block is about (media block)
                        return null;
                    }
                    break;

                case 'blocks':
                    $list = $item[Generator::ITEMS] ?? [];
                    $content[$name] = is_array($list)
                        ? $this->build($list, array_column($spec['blocks'], 'type'))
                        : [];
                    $filled = $filled || $content[$name] !== [];
                    break;

                case 'structure':
                    $rows = [];
                    foreach ((array) ($item[Generator::ITEMS] ?? []) as $row) {
                        if (!is_array($row)) continue;
                        $rows[] = array_map(fn ($v) => self::plain((string) $v), array_intersect_key($row, $spec['columns']));
                    }
                    $content[$name] = $rows;
                    $filled = $filled || $rows !== [];
                    break;

                default:
                    $value = is_scalar($item[$name] ?? null) ? trim((string) $item[$name]) : '';
                    $content[$name] = $this->text($fields[$name] ?? [], $value);
                    $filled = $filled || $value !== '';
            }
        }

        // Nothing written for this block (e.g. an unused list item type).
        if (!$filled) return null;

        return [
            'id'       => Str::uuid(),
            'type'     => $type,
            'isHidden' => false,
            'content'  => $content,
        ];
    }

    /** Text value in the format of the target field. */
    private function text(array $field, string $value): string
    {
        switch ($field['type'] ?? 'text') {
            case 'pwtext':
                $envelope = ['text' => self::plain($value)];
                foreach (['align', 'level', 'size', 'textbackground', 'flourish', 'multiline'] as $key) {
                    if (isset($field[$key])) $envelope[$key] = $field[$key];
                }
                return self::json($envelope);

            case 'pweditor':
                $mode     = $field['defaultMode'] ?? 'writer';
                $envelope = ['mode' => $mode];
                foreach (['align', 'size'] as $key) {
                    if (isset($field[$key])) $envelope[$key] = $field[$key];
                }
                $envelope += ['textarea' => '', 'writer' => '', 'markdown' => ''];
                $envelope[$mode] = $mode === 'writer' ? self::html($value) : self::plain($value, true);
                return self::json($envelope);

            case 'writer':
                return self::html($value);

            case 'textarea':
                return self::plain($value, true);

            default:
                return self::plain($value);
        }
    }

    /** HTML for writer fields; plain text becomes paragraphs. */
    private static function html(string $value): string
    {
        if ($value === '' || preg_match('/<(p|ul|ol)\b/i', $value)) return $value;

        $paragraphs = preg_split('/\n\s*\n/', trim($value)) ?: [];
        return implode('', array_map(fn ($p) => '<p>' . nl2br(trim($p), false) . '</p>', $paragraphs));
    }

    /** Plain text; with $paragraphs, block elements become blank lines. */
    private static function plain(string $value, bool $paragraphs = false): string
    {
        if (!$paragraphs) {
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim(preg_replace('/\s+/', ' ', $value));
        }

        $value = preg_replace('/<li\b[^>]*>/i', '- ', $value);
        $value = preg_replace('/<\/li>\s*/i', "\n", $value);
        $value = preg_replace('/<\/(p|ul|ol)>\s*/i', "\n\n", $value);
        $value = preg_replace('/<br\s*\/?>/i', "\n", $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $value));
    }

    /**
     * Background video (or photo as fallback) behind a block. Sets the
     * background type, the file, a larger block height and — for
     * legibility — a solid overlay unless the project already defines
     * one. Without a match the block keeps its default background.
     */
    private function background(array &$content, string $type, mixed $value): void
    {
        if ($this->pexels === null || !$this->model instanceof Page || !is_array($value)) return;

        $query = trim((string) ($value['query'] ?? ''));
        $title = trim((string) ($value['alt'] ?? ''));
        if ($query === '') return;

        $spec = $this->catalog->backgroundOf($type);
        if ($spec === null) return;

        $file = isset($spec['video']) ? $this->pexels->attachVideo($this->model, $query, $title, (string) $this->language) : null;
        $kind = 'video';
        if ($file === null && isset($spec['image'])) {
            $file = $this->pexels->attach($this->model, $query, $title, (string) $this->language, 'pwBackgroundimage');
            $kind = 'image';
        }
        if ($file === null) return;

        $content[$spec['typeField']] = $kind;
        $content[$spec[$kind]]       = [$file->uuid()?->toString() ?? $file->filename()];

        if (array_key_exists('overlaytype', $content) && empty($content['overlaytype'])) {
            $content['overlaytype'] = 'solid';
        }

        // A background needs room to show.
        $heights = array_column($this->catalog->allFields($type)['height']['options'] ?? [], 'value');
        if ($this->backgroundHeight !== null && in_array($this->backgroundHeight, $heights, true)) {
            $content['height'] = $this->backgroundHeight;
        }
    }

    /**
     * Fetch the photo for an image field; returns the file reference to
     * store in the files field, or null.
     */
    private function image(mixed $value): ?string
    {
        if ($this->pexels === null || !$this->model instanceof Page || !is_array($value)) return null;

        $query = trim((string) ($value['query'] ?? ''));
        if ($query === '') return null;

        $file = $this->pexels->attach($this->model, $query, trim((string) ($value['alt'] ?? '')), (string) $this->language);
        if ($file === null) return null;

        return $file->uuid()?->toString() ?? $file->filename();
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
