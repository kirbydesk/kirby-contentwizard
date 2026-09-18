# kirby-contentwizard

AI page generator for [kirby-pagewizard](https://github.com/kirbydesk/kirby-pagewizard).
Describe what a page should be about, and Claude writes it — built from
the blocks that are enabled in the project.

- **Knows the project's blocks.** The generator reads the blocks activated
  in the Project Wizard and their content fields straight from the
  blueprints. A newly installed kirbyblock plugin is picked up
  automatically — nothing to configure.
- **Only valid content.** The block catalog is turned into a JSON schema
  (structured output), so Claude can only answer with blocks and fields
  that exist on the website.
- **Project defaults.** Every block starts with the defaults of its
  blueprint, exactly like adding a block in the panel. Layout and style
  follow the project settings; only the text is generated.
- **Default language.** Pages are written in the site's default language.
  Use [kirby-translatewizard](https://github.com/kirbydesk/kirby-translatewizard)
  for the other languages.

**Photos from Pexels.** With a [Pexels API key](https://www.pexels.com/api/),
media blocks are included: Claude describes a fitting scene, the plugin
searches Pexels, adds the photo to the page and fills alt text and the
credit fields (photographer, credit line, source, Pexels License).
Without a key, media blocks are left out.

Blocks that need links or other files (e.g. buttons, videos) are left
out — Claude cannot provide them. Add those in the panel afterwards.

## Requirements

- Kirby 5
- kirby-pagewizard 1.1.51+ (provides the shared **AI** view button)
- An [Anthropic API key](https://console.anthropic.com/)

## Installation

```bash
composer require kirbydesk/kirby-contentwizard
```

## Configuration

```php
// site/config/config.php
return [
    'kirbydesk.contentwizard' => [
        'anthropic' => [
            'apiKey' => 'sk-ant-…',
        ],

        // optional — stock photos for media blocks
        'pexels' => [
            'apiKey' => '…',
        ],

        // optional — default: claude-opus-5
        'model' => 'claude-opus-5',

        // optional — block types the generator never uses
        // default: ['pwmulticolumn', 'pwheading'] (column layouts are
        // arranged by hand; every block has its own heading)
        'exclude' => ['pwmulticolumn', 'pwheading'],

        // optional — themes the generated sections alternate between
        // default: ['default', 'variant']; [] keeps the block defaults
        'themes' => ['default', 'variant'],

        // optional — block height when a background video/photo is set
        // default: 'large' (auto|small|medium|large|fullscreen); null keeps the default
        'backgroundHeight' => 'large',

        // optional — describes the website (topic, audience, voice);
        // sent along with every request
        'project' => 'Ergotherapy practice in Saarbrücken. We address parents and use a warm, professional tone.',
    ],
];
```

Without `anthropic.apiKey` / `pexels.apiKey`, the environment variables
`ANTHROPIC_API_KEY` / `PEXELS_API_KEY` are used. Keep the key out of version control (e.g. in an env file or a
git-ignored config).

With `claude-opus-5`, server-side refusal fallbacks are enabled: if the
model declines a request, the API retries it on a fallback model.

### Panel button

The action appears in the **AI** view button of kirby-pagewizard — in the
default language, on pages with pagewizard's blocks field. Add the button
to `panel.viewButtons`:

```php
return [
    'panel' => [
        'viewButtons' => [
            'page' => ['open', 'preview', '-', 'settings', 'ai', 'languages', 'status'],
        ],
    ],
];
```

## Block hints for plugin authors

Each block is described to Claude by its name and field labels. A block
plugin can add a short hint on *when* to use it with an i18n key next to
its name key — `<plugin>.name` → `<plugin>.ai`:

```php
'kirbyblock-steplist.ai' => 'A numbered sequence of steps, e.g. how a process works. Use it whenever the content describes steps in order.',
```

## Development

```bash
composer install   # installs the Anthropic PHP SDK into vendor/
```

- `src/BlockCatalog.php` — reads enabled blocks and their content fields
- `src/Generator.php` — builds prompt + JSON schema, calls Claude (streaming)
- `src/BlockBuilder.php` — turns the answer into Kirby block data with blueprint defaults
- `src/Pexels.php` — finds a photo and adds it to the page with credit fields
