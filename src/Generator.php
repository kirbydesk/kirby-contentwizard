<?php

namespace Kirbydesk\Contentwizard;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use RuntimeException;

/**
 * Asks Claude for a page built from the project's blocks. The block
 * catalog becomes a JSON schema (structured output), so the answer can
 * only contain blocks and fields that exist in this project.
 *
 * Result shape:
 *   [
 *     'metadescription' => '…',
 *     'blocks' => [['type' => 'pwsteplist', 'content' => [...]], …],
 *   ]
 */
final class Generator
{
    /** Token usage of the last request: input, output (for logging/cost). */
    public array $usage = [];

    /** Models that support server-side refusal fallbacks ("default" routing). */
    private const FALLBACK_MODELS = ['claude-opus-5', 'claude-fable-5-1'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    /**
     * @param list<array> $catalog BlockCatalog::blocks()
     * @param array{language: string, title: string, path: list<string>, project: ?string} $page
     */
    public function generate(array $catalog, array $page, string $brief): array
    {
        $client = new Client(apiKey: $this->apiKey);

        $params = [
            'model'        => $this->model,
            'maxTokens'    => 32000,
            'system'       => $this->systemPrompt($page),
            'messages'     => [['role' => 'user', 'content' => $brief]],
            'outputConfig' => [
                'format' => [
                    'type'   => 'json_schema',
                    'schema' => $this->schema($catalog),
                ],
            ],
        ];

        if (in_array($this->model, self::FALLBACK_MODELS, true)) {
            $params['betas']     = ['server-side-fallback-2026-07-01'];
            $params['fallbacks'] = 'default';
        }

        // Streaming: a full page can take a while to write, and a plain
        // request would run into HTTP timeouts.
        $stream = $client->beta->messages->createStream(...$params);

        $json       = '';
        $stopReason = null;
        foreach ($stream as $event) {
            if ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                $json .= $event->delta->text;
            } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                $stopReason  = $event->delta->stopReason ?? $stopReason;
                $this->usage = [
                    'input'  => ($event->usage->inputTokens ?? 0) + ($event->usage->cacheReadInputTokens ?? 0) + ($event->usage->cacheCreationInputTokens ?? 0),
                    'output' => $event->usage->outputTokens,
                ];
            }
        }

        if ($stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to write this page. Please rephrase the description.');
        }
        if ($stopReason === 'max_tokens') {
            throw new RuntimeException('The generated page was too long. Please ask for fewer sections.');
        }

        $result = json_decode($json, true);
        if (!is_array($result) || !is_array($result['blocks'] ?? null)) {
            throw new RuntimeException('Claude returned an unexpected answer.');
        }

        return $result;
    }

    private function systemPrompt(array $page): string
    {
        $location = $page['path'] === []
            ? 'It is a top-level page.'
            : 'It sits below: ' . implode(' › ', $page['path']) . '.';

        $project = $page['project'] !== null && trim($page['project']) !== ''
            ? "\n\n<project>\n" . trim($page['project']) . "\n</project>\nWrite in line with this description of the website and its voice."
            : '';

        return <<<PROMPT
        You write the content of a web page for a website built with Kirby CMS and the pagewizard block system.

        The page is titled "{$page['title']}". {$location}
        Write all text in this language: {$page['language']}.{$project}

        The editor describes what the page should be about. Turn that into a well-structured page made of the available blocks — the response schema lists every block type and field that exists on this website, each with a description of what it is for. Pick the block that fits the content: a sequence of steps belongs in a step list, a set of benefits or features in a feature list, and so on. Plain text blocks are for running text. Use as many blocks as the topic needs, typically four to eight, and vary them where it helps the reader.

        Writing:
        - Clear, friendly, concrete web copy; short paragraphs.
        - Headings are short. Taglines are a few words that lead into the heading.
        - Leave a field empty ("") when the block reads better without it.
        - Do not invent facts that only the website owner can know — prices, opening hours, addresses, names, phone numbers, statistics. Write around them or phrase them generally.
        - Fields described as HTML may only use <p>, <strong>, <em>, <ul>, <ol> and <li>. Plain-text fields contain no markup; separate paragraphs there with a blank line.
        - Where a block takes a photo, a stock photo is searched with the terms you give. Describe a plausible, concrete scene that supports the text (people, hands, objects, setting) rather than an abstract idea. Use one or two photo blocks on a page, not more.

        Also write a meta description for search engines: one or two sentences, at most 155 characters.
        PROMPT;
    }

    /**
     * @param list<array> $catalog
     */
    private function schema(array $catalog): array
    {
        return self::object([
            'metadescription' => [
                'type'        => 'string',
                'description' => 'Meta description for search engines, at most 155 characters.',
            ],
            'blocks' => [
                'type'        => 'array',
                'description' => 'The blocks of the page, top to bottom.',
                'items'       => ['anyOf' => array_map(fn ($entry) => $this->blockSchema($entry), $catalog)],
            ],
        ]);
    }

    private function blockSchema(array $entry): array
    {
        $properties = [];
        foreach ($entry['fields'] as $name => $spec) {
            $properties[$name] = $this->fieldSchema($spec);
        }

        $description = $entry['label'];
        if (!empty($entry['hint'])) $description .= ' — ' . $entry['hint'];

        return self::object([
            'type'    => ['type' => 'string', 'const' => $entry['type']],
            'content' => self::object($properties),
        ], $description);
    }

    private function fieldSchema(array $spec): array
    {
        $label = $spec['label'] ?? '';

        return match ($spec['kind']) {
            'pwtext', 'text' => ['type' => 'string', 'description' => trim($label . '. Plain text, no markup.', ' .')],
            'textarea'       => ['type' => 'string', 'description' => $label . '. Plain text.'],
            'html'           => ['type' => 'string', 'description' => $label . '. HTML.'],
            'pweditor'       => ['type' => 'string', 'description' => $label . match ($spec['mode']) {
                'writer'   => '. HTML.',
                'markdown' => '. Markdown.',
                default    => '. Plain text.',
            }],
            'image' => self::object([
                'query' => ['type' => 'string', 'description' => 'English search terms for a stock photo, 2 to 5 words, describing a concrete, realistic scene.'],
                'alt'   => ['type' => 'string', 'description' => 'Alt text for the photo in the page language: what the image shows, one sentence.'],
            ], $label !== '' ? $label . ' (a stock photo is searched for it)' : null),
            'structure' => [
                'type'        => 'array',
                'description' => $label,
                'items'       => self::object(array_map(
                    fn ($column) => ['type' => 'string', 'description' => (string) $column],
                    $spec['columns']
                )),
            ],
            'blocks' => [
                'type'        => 'array',
                'description' => $label,
                'items'       => ['anyOf' => array_map(fn ($entry) => $this->blockSchema($entry), $spec['blocks'])],
            ],
        };
    }

    /** Strict object: every property required, nothing else allowed. */
    private static function object(array $properties, ?string $description = null): array
    {
        $schema = [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => array_keys($properties),
            'additionalProperties' => false,
        ];
        if ($description !== null) $schema['description'] = $description;
        return $schema;
    }
}
