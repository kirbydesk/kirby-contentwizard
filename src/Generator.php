<?php

namespace Kirbydesk\Contentwizard;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use RuntimeException;

/**
 * Asks Claude for a page built from the project's blocks.
 *
 * The response schema is deliberately flat: one block object with a
 * `type` enum and the union of all fields (tagline, heading, editor,
 * items, image, background, …). Which block uses which field — and what
 * each block is for — is described in the system prompt. A schema with
 * one variant per block type grows with every block and quickly exceeds
 * the size limit of structured outputs; the flat one stays small.
 * BlockBuilder takes from each block only the fields its type has.
 *
 * Result shape:
 *   [
 *     'metadescription' => '…',
 *     'blocks' => [['type' => 'pwsteplist', 'heading' => '…', 'items' => [...], …], …],
 *   ]
 */
final class Generator
{
    /** Keys of the flat block object for list, image and background fields. */
    public const ITEMS      = 'items';
    public const IMAGE      = 'image';
    public const BACKGROUND = 'background';

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
     * @param array{language: string, title: string, path: list<string>, project: ?string, rules?: list<string>} $page
     */
    public function generate(array $catalog, array $page, string $brief): array
    {
        $client = new Client(apiKey: $this->apiKey);

        $params = [
            'model'        => $this->model,
            'maxTokens'    => 32000,
            'system'       => $this->systemPrompt($catalog, $page),
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
                    'stop'   => $stopReason,
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

    /* ---------------------------------------------------------------- */
    /*  Prompt                                                          */
    /* ---------------------------------------------------------------- */

    private function systemPrompt(array $catalog, array $page): string
    {
        $location = $page['path'] === []
            ? 'It is a top-level page.'
            : 'It sits below: ' . implode(' › ', $page['path']) . '.';

        $project = $page['project'] !== null && trim($page['project']) !== ''
            ? "\n\n<project>\n" . trim($page['project']) . "\n</project>\nWrite in line with this description of the website and its voice."
            : '';

        $blocks = implode("\n", array_map(fn ($entry) => $this->describeBlock($entry), $catalog));

        $rules = implode('', array_map(fn ($rule) => "\n        - " . $rule, $page['rules'] ?? []));

        return <<<PROMPT
        You write the content of a web page for a website built with Kirby CMS and the pagewizard block system.

        The page is titled "{$page['title']}". {$location}
        Write all text in this language: {$page['language']}.{$project}

        The editor describes what the page should be about. Turn that into a well-structured page made of the blocks below. Pick the block that fits the content: a sequence of steps belongs in a step list, a set of benefits or features in a feature list, and so on. Use as many blocks as the topic needs, typically four to eight, and vary them where it helps the reader. A page of text only looks plain: where a block type takes photos (e.g. cards with an image each) and the content has several parallel topics, prefer it over a text-only list, so that most pages carry some imagery.

        <blocks>
        {$blocks}
        </blocks>

        Every block in your answer has all fields of the response format. Fill only the fields its block type lists; set every other field to an empty string, an empty list, or an image/background with an empty query.

        Writing:
        - Clear, friendly, concrete web copy; short paragraphs.
        - Headings are short. Taglines are a few words that lead into the heading.
        - Leave a listed field empty when the block reads better without it.
        - Short fields (marked "short") are plain text without markup. Rich-text fields (marked "rich text") are HTML using only <p>, <strong>, <em>, <ul>, <ol> and <li>.
        - Do not invent facts that only the website owner can know — prices, opening hours, addresses, names, phone numbers, statistics. Write around them or phrase them generally.
        - Never invent quotes, testimonials or reviews. Use a quote block only for a quote the editor provides in the description.
        - For photos, give English search terms for a stock photo: a plausible, concrete scene that supports the text (people, hands, objects, setting), a different scene for each photo on the page. Use at most one or two media blocks per page, if media blocks are available. The photos are generic stock images: the text around them must not claim that they show the website owner, their team or their premises.
        - A background is a short, calm stock video behind the opening block's text; give English search terms for footage that sets the mood of the topic.{$rules}

        Also write a meta description for search engines: one or two sentences, at most 155 characters.
        PROMPT;
    }

    private function describeBlock(array $entry): string
    {
        $line = '- ' . $entry['type'] . ' (' . $entry['label'] . ')';
        if (!empty($entry['hint'])) $line .= ': ' . rtrim($entry['hint'], '.') . '.';

        return $line . "\n  Fields: " . implode('; ', $this->describeFields($entry)) . '.';
    }

    /** @return list<string> */
    private function describeFields(array $entry, bool $nested = false): array
    {
        $parts = [];
        foreach ($entry['fields'] as $name => $spec) {
            $label = $spec['label'] !== null && strcasecmp($spec['label'], $name) !== 0 ? ' — ' . $spec['label'] : '';

            $parts[] = match ($spec['kind']) {
                'pwtext', 'text' => $name . ' (short' . $label . ')',
                'pweditor', 'html', 'textarea' => $name . ' (rich text' . $label . ')',
                'structure'  => ($nested ? $name : self::ITEMS) . ' (list of entries with ' . implode(', ', array_keys($spec['columns'])) . ')',
                'blocks'     => self::ITEMS . ' (list of ' . implode(' or ', array_map(
                    fn ($sub) => $sub['label'] . ' entries with ' . implode(', ', $this->describeFields($sub, true)),
                    $spec['blocks']
                )) . ')',
                'image'      => self::IMAGE . ' (photo' . (!empty($spec['optional']) ? ', optional' : '') . ')',
                'background' => self::BACKGROUND . ' (video behind the text)',
                default      => $name,
            };
        }
        return $parts;
    }

    /* ---------------------------------------------------------------- */
    /*  Schema                                                          */
    /* ---------------------------------------------------------------- */

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
                'items'       => $this->flatObject($catalog, true),
            ],
        ]);
    }

    /**
     * One object with the union of all fields of the given entries.
     */
    private function flatObject(array $entries, bool $withType): array
    {
        $properties = [];
        if ($withType) {
            $properties['type'] = ['type' => 'string', 'enum' => array_values(array_unique(array_column($entries, 'type')))];
        }

        $items = [];
        $columns = [];
        foreach ($entries as $entry) {
            foreach ($entry['fields'] as $name => $spec) {
                switch ($spec['kind']) {
                    case 'image':
                        $properties[self::IMAGE] = self::media('photo');
                        break;
                    case 'background':
                        $properties[self::BACKGROUND] = self::media('background video');
                        break;
                    case 'blocks':
                        array_push($items, ...$spec['blocks']);
                        break;
                    case 'structure':
                        $columns += $spec['columns'];
                        break;
                    default:
                        $properties[$name] ??= ['type' => 'string'];
                }
            }
        }

        if ($items !== []) {
            $types = array_unique(array_column($items, 'type'));
            $properties[self::ITEMS] = ['type' => 'array', 'items' => $this->flatObject($items, count($types) > 1)];
        } elseif ($columns !== []) {
            $properties[self::ITEMS] = ['type' => 'array', 'items' => self::object(array_map(fn () => ['type' => 'string'], $columns))];
        }

        return self::object($properties);
    }

    private static function media(string $what): array
    {
        return self::object([
            'query' => ['type' => 'string', 'description' => 'English search terms for a stock ' . $what . ', 2 to 5 words; empty if none.'],
            'alt'   => ['type' => 'string', 'description' => 'What it shows, one sentence in the page language.'],
        ]);
    }

    /** Strict object: every property required, nothing else allowed. */
    private static function object(array $properties): array
    {
        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
