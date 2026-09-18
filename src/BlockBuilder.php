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
 * settings. The generated text is then put into the content fields;
 * pagewizard's own field types get their JSON envelope (pwtext:
 * align/level/size, pweditor: mode/align/size). Image fields are filled
 * from Pexels; a block whose image cannot be found is left out.
 */
final class BlockBuilder
{
    public function __construct(
        private readonly ModelWithContent $model,
        private readonly BlockCatalog $catalog,
        private readonly ?Pexels $pexels = null,
        private readonly ?string $language = null,
    ) {
    }

    /**
     * @param list<array> $generated [['type' => …, 'content' => […]], …]
     * @return list<array> Kirby blocks
     */
    public function build(array $generated): array
    {
        $blocks = [];
        foreach ($generated as $item) {
            $block = $this->block($item);
            if ($block !== null) $blocks[] = $block;
        }
        return $blocks;
    }

    private function block(array $item): ?array
    {
        $type = $item['type'] ?? null;
        if (!is_string($type)) return null;

        try {
            $props    = Blueprint::find('blocks/' . $type);
            $fieldset = new Fieldset(array_merge($props, ['type' => $type, 'parent' => $this->model]));
            $form     = new Form(fields: $fieldset->fields(), model: $this->model, language: 'current');
            $content  = $form->defaults();
        } catch (Throwable) {
            return null;
        }

        $fields = $this->catalog->contentFields($type);
        foreach ($item['content'] ?? [] as $name => $value) {
            if (!isset($fields[$name])) continue;

            if (($fields[$name]['type'] ?? null) === 'files') {
                $image = $this->image($value);
                if ($image === null) return null;
                $content[$name] = [$image];
                continue;
            }

            $content[$name] = $this->value($fields[$name], $value);
        }

        return [
            'id'       => Str::uuid(),
            'type'     => $type,
            'isHidden' => false,
            'content'  => $content,
        ];
    }

    private function value(array $field, mixed $value): mixed
    {
        switch ($field['type'] ?? 'text') {
            case 'pwtext':
                $envelope = ['text' => (string) $value];
                foreach (['align', 'level', 'size', 'textbackground', 'flourish', 'multiline'] as $key) {
                    if (isset($field[$key])) $envelope[$key] = $field[$key];
                }
                return self::json($envelope);

            case 'pweditor':
                $mode = $field['defaultMode'] ?? 'writer';
                $envelope = ['mode' => $mode];
                foreach (['align', 'size'] as $key) {
                    if (isset($field[$key])) $envelope[$key] = $field[$key];
                }
                $envelope += ['textarea' => '', 'writer' => '', 'markdown' => ''];
                $envelope[$mode] = (string) $value;
                return self::json($envelope);

            case 'structure':
                return is_array($value) ? array_values($value) : [];

            case 'blocks':
                return is_array($value) ? $this->build($value) : [];

            default:
                return is_scalar($value) ? (string) $value : '';
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
