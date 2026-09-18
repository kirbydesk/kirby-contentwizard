<?php

use Kirby\Cms\App;
use Kirby\Cms\Find;
use Kirby\Cms\Page;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirbydesk\Contentwizard\BlockBuilder;
use Kirbydesk\Contentwizard\BlockCatalog;
use Kirbydesk\Contentwizard\Generator;
use Kirbydesk\Contentwizard\Pexels;

@include_once __DIR__ . '/vendor/autoload.php';
// PSR-4 fallback for local (unlinked) install
spl_autoload_register(function (string $class): void {
    $prefix = 'Kirbydesk\\Contentwizard\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

/**
 * Page models the generator can fill: pages whose blueprint has
 * pagewizard's `blocks` field.
 */
function _contentwizard_supports($model): bool
{
    return $model instanceof Page && $model->blueprint()->field('blocks') !== null;
}

Kirby::plugin('kirbydesk/contentwizard', [
    'options' => [
        'anthropic.apiKey' => null,
        'model'            => 'claude-opus-5',

        // Optional: Pexels API key. With it, media blocks get stock photos.
        'pexels.apiKey' => null,

        // Block types the generator never uses: column layouts and media
        // blocks are better arranged by hand, a standalone heading adds
        // nothing to a generated page (every block has its own heading).
        'exclude' => ['pwmulticolumn', 'pwheading', 'pwmedia'],

        // Themes the generated sections alternate between (pagewizard
        // theme values). An empty list keeps every block on its default.
        'themes' => ['default', 'variant'],

        // Block height (pagewizard `height` value) when the generator puts
        // a video or photo behind a block, e.g. the hero. null keeps the default.
        'backgroundHeight' => 'large',

        // Optional description of the website (topic, audience, voice)
        // that is sent along with every request.
        'project' => null,

        // Entry for pagewizard's shared "AI" view button. Pages are
        // written in the default language; translatewizard handles the rest.
        'aiActions' => function ($model): array {
            $kirby   = App::instance();
            $default = $kirby->defaultLanguage();
            $current = $kirby->language();

            if ($default !== null && $current !== null && $current->code() !== $default->code()) return [];
            if (!_contentwizard_supports($model)) return [];

            return [
                [
                    'label'  => t('contentwizard.action.generate', 'Create page with AI'),
                    'icon'   => 'wand',
                    'dialog' => 'contentwizard/' . ($model->panel()?->path() ?? ''),
                ],
            ];
        },
    ],

    'areas' => [
        'site' => function () {
            return [
                'dialogs' => [
                    'contentwizard/(:all)' => [
                        'load' => function (string $path) {
                            $model     = Find::parent($path);
                            $hasBlocks = $model->content()->get('blocks')->toBlocks()->isNotEmpty();

                            $fields = [
                                'brief' => [
                                    'type'        => 'textarea',
                                    'label'       => t('contentwizard.dialog.brief', 'What should the page be about?'),
                                    'help'        => t('contentwizard.dialog.brief.help', 'Topic, audience, sections you want, tone …'),
                                    'buttons'     => false,
                                    'size'        => 'medium',
                                    'required'    => true,
                                ],
                            ];

                            if ($hasBlocks) {
                                $fields['mode'] = [
                                    'type'     => 'toggles',
                                    'label'    => t('contentwizard.dialog.mode', 'Existing content'),
                                    'required' => true,
                                    'options'  => [
                                        ['value' => 'append',  'text' => t('contentwizard.dialog.mode.append', 'Add below')],
                                        ['value' => 'replace', 'text' => t('contentwizard.dialog.mode.replace', 'Replace')],
                                    ],
                                ];
                            }

                            return [
                                'component' => 'k-form-dialog',
                                'props'     => [
                                    'fields'       => $fields,
                                    'value'        => ['brief' => '', 'mode' => 'append'],
                                    'size'         => 'large',
                                    'submitButton' => [
                                        'text'  => t('contentwizard.dialog.submit', 'Create'),
                                        'icon'  => 'wand',
                                        'theme' => 'positive',
                                    ],
                                ],
                            ];
                        },
                        'submit' => function (string $path) {
                            $kirby = App::instance();
                            $model = Find::parent($path);

                            if (!_contentwizard_supports($model)) {
                                throw new InvalidArgumentException(message: 'This page has no blocks field.');
                            }
                            if ($model->permissions()->can('update') === false) {
                                throw new PermissionException(message: 'Not allowed.');
                            }

                            $apiKey = $kirby->option('kirbydesk.contentwizard.anthropic.apiKey') ?: getenv('ANTHROPIC_API_KEY');
                            if (!is_string($apiKey) || $apiKey === '') {
                                throw new InvalidArgumentException(message: 'No Anthropic API key configured (kirbydesk.contentwizard.anthropic.apiKey).');
                            }

                            $request = $kirby->request();
                            $brief   = trim((string) $request->get('brief'));
                            $mode    = $request->get('mode') === 'replace' ? 'replace' : 'append';
                            if ($brief === '') {
                                throw new InvalidArgumentException(message: t('contentwizard.error.brief', 'Please describe the page.'));
                            }

                            // Writing a page takes a while.
                            @set_time_limit(300);

                            $language = $kirby->defaultLanguage() ?? $kirby->language();
                            $pexelsKey = $kirby->option('kirbydesk.contentwizard.pexels.apiKey') ?: getenv('PEXELS_API_KEY');
                            $pexels    = is_string($pexelsKey) && $pexelsKey !== '' ? new Pexels($pexelsKey) : null;
                            $catalog   = new BlockCatalog($model, (array) $kirby->option('kirbydesk.contentwizard.exclude', []), $pexels !== null);

                            $result = (new Generator($apiKey, (string) $kirby->option('kirbydesk.contentwizard.model', 'claude-opus-5')))
                                ->generate($catalog->blocks(), [
                                    'language' => $language?->name() ?? 'English',
                                    'title'    => $model->title()->value(),
                                    'path'     => $model->parents()->flip()->pluck('title'),
                                    'project'  => $kirby->option('kirbydesk.contentwizard.project'),
                                ], $brief);

                            $themes = (array) $kirby->option('kirbydesk.contentwizard.themes', []);
                            $blocks = $generatedBlocks = (new BlockBuilder($model, $catalog, $pexels, $language?->code(), $themes, $kirby->option('kirbydesk.contentwizard.backgroundHeight')))->build($result['blocks']);

                            if ($mode === 'append') {
                                $existing = $model->content()->get('blocks')->toBlocks()->toArray();
                                $blocks   = array_merge(array_values($existing), $blocks);
                            }

                            $update = ['blocks' => $blocks];
                            $meta   = trim((string) ($result['metadescription'] ?? ''));
                            if ($meta !== '' && $model->content()->get('metadescription')->isEmpty()
                                && $model->blueprint()->field('metadescription') !== null) {
                                $update['metadescription'] = $meta;
                            }

                            $model->update($update, $language?->code());

                            return [
                                'event'   => 'model.update',
                                'message' => tt('contentwizard.result.done', '{count} block(s) created.', [
                                    'count' => count($generatedBlocks),
                                ]),
                            ];
                        },
                    ],
                ],
            ];
        },
    ],

    'translations' => require_once __DIR__ . '/src/extensions/translations.php',
]);
