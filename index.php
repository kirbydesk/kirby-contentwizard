<?php

use Kirby\Cms\App;
use Kirby\Cms\Blueprint;
use Kirby\Cms\Find;
use Kirby\Cms\Page;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirbydesk\Contentwizard\BlockBuilder;
use Kirbydesk\Contentwizard\BlockCatalog;
use Kirbydesk\Contentwizard\Generator;
use Kirbydesk\Contentwizard\Pexels;
use Kirbydesk\Contentwizard\Settings;
use Kirbydesk\Contentwizard\ThemeContrast;

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

function _contentwizard_key(string $option, string $env): ?string
{
    $key = App::instance()->option('kirbydesk.contentwizard.' . $option) ?: getenv($env);
    return is_string($key) && $key !== '' ? $key : null;
}

/**
 * Form for the Project Wizard's "AI" tab: Kirby field definitions plus
 * the stored values. The Project Wizard renders it with <k-form>.
 */
function _contentwizard_settings_form(): array
{
    $settings = (new Settings())->all();

    $blocks = [];
    foreach (pwConfig::projectConfig('blocks') as $type) {
        try {
            $name = Blueprint::find('blocks/' . $type)['name'] ?? $type;
        } catch (Throwable) {
            continue;
        }
        $blocks[] = ['value' => $type, 'text' => t($name, $type)];
    }

    $themes = array_map(fn ($theme) => ['value' => $theme, 'text' => t('pw.option.' . $theme, $theme)], ThemeContrast::THEMES);

    $fields = [
        'headlineContent' => ['type' => 'headline', 'label' => t('contentwizard.settings.content', 'Content')],
        'project' => [
            'type'    => 'textarea',
            'label'   => t('contentwizard.settings.project', 'Project and voice'),
            'help'    => t('contentwizard.settings.project.help', 'What the website is about, who it addresses and how it speaks. Sent along with every generated page.'),
            'buttons' => false,
            'size'    => 'small',
        ],
        'rules' => [
            'type'    => 'textarea',
            'label'   => t('contentwizard.settings.rules', 'Writing rules'),
            'help'    => t('contentwizard.settings.rules.help', 'One rule per line, e.g. “Explain technical terms briefly.”'),
            'buttons' => false,
            'size'    => 'small',
        ],
        'blocks' => [
            'type'    => 'checkboxes',
            'label'   => t('contentwizard.settings.blocks', 'Blocks for the AI'),
            'help'    => t('contentwizard.settings.blocks.help', 'Blocks that need links or files the AI cannot provide are left out automatically.'),
            'options' => $blocks,
            'columns' => 3,
        ],

        'headlineThemes' => ['type' => 'headline', 'label' => t('contentwizard.settings.themes', 'Sections')],
        'themeA' => [
            'type'    => 'select',
            'label'   => t('contentwizard.settings.themeA', 'Theme of the sections'),
            'options' => $themes,
            'empty'   => false,
            'width'   => '1/2',
        ],
        'themeB' => [
            'type'    => 'select',
            'label'   => t('contentwizard.settings.themeB', 'Alternating with'),
            'help'    => t('contentwizard.settings.themeB.help', 'Leave empty to keep all sections on one theme.'),
            'options' => $themes,
            'width'   => '1/2',
        ],

        'headlineBackground' => ['type' => 'headline', 'label' => t('contentwizard.settings.background', 'Background video / photo (hero)')],
        'backgroundHeight' => [
            'type'    => 'toggles',
            'label'   => t('contentwizard.settings.backgroundHeight', 'Height'),
            'options' => array_map(fn ($v) => ['value' => $v, 'text' => t('pw.option.' . $v, $v)], ['auto', 'small', 'medium', 'large', 'fullscreen']),
        ],
        'backgroundOverlay' => [
            'type'    => 'toggles',
            'label'   => t('contentwizard.settings.backgroundOverlay', 'Overlay'),
            'options' => array_map(fn ($v) => ['value' => $v, 'text' => t('pw.option.' . $v, $v)], ['none', 'solid', 'gradient']),
            'width'   => '1/2',
        ],
        'backgroundOverlayIntensity' => [
            'type'  => 'range',
            'label' => t('contentwizard.settings.backgroundOverlayIntensity', 'Overlay intensity'),
            'min'   => 0,
            'max'   => 100,
            'step'  => 5,
            'after' => '%',
            'width' => '1/2',
        ],
        'backgroundTheme' => [
            'type'    => 'select',
            'label'   => t('contentwizard.settings.backgroundTheme', 'Theme on the background'),
            'help'    => t('contentwizard.settings.backgroundTheme.help', 'Automatic: the theme whose text reads best on the video or photo (its measured brightness, darkened by the overlay).'),
            'options' => array_merge([['value' => 'auto', 'text' => t('contentwizard.settings.auto', 'Automatic (best contrast)')]], $themes),
            'empty'   => false,
        ],
    ];

    $value = $settings;
    $value['blocks'] = array_values(array_diff(array_column($blocks, 'value'), $settings['exclude']));
    unset($value['exclude']);

    return ['fields' => $fields, 'value' => $value];
}

Kirby::plugin('kirbydesk/contentwizard', [
    'options' => [
        'anthropic.apiKey' => null,
        'model'            => 'claude-opus-5',

        // Optional: Pexels API key for the hero's background video and
        // photos on cards.
        'pexels.apiKey' => null,

        // Everything else — voice, rules, blocks, themes, background —
        // is set per project in the Project Wizard ("AI" tab).

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

    'api' => [
        'routes' => [
            [
                'pattern' => 'contentwizard/settings',
                'method'  => 'GET',
                'action'  => fn () => _contentwizard_settings_form(),
            ],
            [
                'pattern' => 'contentwizard/settings',
                'method'  => 'POST',
                'action'  => function () {
                    $kirby = App::instance();
                    if ($kirby->user()?->role()->permissions()->for('site', 'update') !== true) {
                        throw new PermissionException(message: 'Not allowed.');
                    }

                    $input = $kirby->request()->body()->toArray();
                    $all   = array_column(_contentwizard_settings_form()['fields']['blocks']['options'], 'value');
                    $input['exclude'] = array_values(array_diff($all, (array) ($input['blocks'] ?? [])));

                    Settings::write($input);
                    return _contentwizard_settings_form();
                },
            ],
        ],
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
                                    'type'     => 'textarea',
                                    'label'    => t('contentwizard.dialog.brief', 'What should the page be about?'),
                                    'help'     => t('contentwizard.dialog.brief.help', 'Topic, audience, sections you want, tone …'),
                                    'buttons'  => false,
                                    'size'     => 'medium',
                                    'required' => true,
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

                            $apiKey = _contentwizard_key('anthropic.apiKey', 'ANTHROPIC_API_KEY');
                            if ($apiKey === null) {
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

                            $settings  = new Settings();
                            $language  = $kirby->defaultLanguage() ?? $kirby->language();
                            $pexelsKey = _contentwizard_key('pexels.apiKey', 'PEXELS_API_KEY');
                            $pexels    = $pexelsKey !== null ? new Pexels($pexelsKey) : null;
                            $catalog   = new BlockCatalog($model, $settings->exclude(), $pexels !== null);

                            $result = (new Generator($apiKey, (string) $kirby->option('kirbydesk.contentwizard.model', 'claude-opus-5')))
                                ->generate($catalog->blocks(), [
                                    'language' => $language?->name() ?? 'English',
                                    'title'    => $model->title()->value(),
                                    'path'     => $model->parents()->flip()->pluck('title'),
                                    'project'  => $settings->get('project'),
                                    'rules'    => $settings->rules(),
                                ], $brief);

                            $blocks = $generated = (new BlockBuilder($model, $catalog, $pexels, $language?->code(), $settings))
                                ->build($result['blocks']);

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

                            $model = $model->update($update, $language?->code());

                            // Replacing drops the previous content: remove its
                            // Pexels files unless the new blocks still use them.
                            if ($mode === 'replace') {
                                $used = json_encode($model->content($language?->code())->toArray());
                                foreach ($model->files() as $file) {
                                    if (!str_contains($file->filename(), '-pexels-')) continue;
                                    if (str_contains($used, $file->filename()) || str_contains($used, (string) $file->uuid()?->toString())) continue;
                                    $file->delete();
                                }
                            }

                            return [
                                'event'   => 'model.update',
                                'message' => tt('contentwizard.result.done', '{count} block(s) created.', [
                                    'count' => count($generated),
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
