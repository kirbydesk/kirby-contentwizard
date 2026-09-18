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

**Photos and videos from Pexels.** With a [Pexels API key](https://www.pexels.com/api/),
the hero gets a short background video and cards (cardlets) get photos:
Claude describes a fitting scene, the plugin searches Pexels, adds the
file to the page and fills alt text / title and the credit fields
(creator, credit line, source, Pexels License). Media blocks are
excluded by default (see `exclude`); remove `pwmedia` from the list to
let the generator add photo blocks too.

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

API keys and the model go into `site/config/config.php` — they belong to
the server:

```php
return [
    'kirbydesk.contentwizard' => [
        'anthropic' => ['apiKey' => 'sk-ant-…'],
        'pexels'    => ['apiKey' => '…'],   // optional: background videos, card photos
        'model'     => 'claude-opus-5',      // optional, default
    ],
];
```

Without the keys, the environment variables `ANTHROPIC_API_KEY` /
`PEXELS_API_KEY` are used. Keep them out of version control (e.g. in a
git-ignored `.env`).

With `claude-opus-5`, server-side refusal fallbacks are enabled: if the
model declines a request, the API retries it on a fallback model.

### AI defaults per project

Everything else is set per project in the **Project Wizard → AI** tab
(requires kirby-projectwizard 1.0.85+) and stored in
`content/.projectwizard/contentwizard.json`:

- **Project and voice** — what the website is about, who it addresses,
  how it speaks. Sent along with every page.
- **Writing rules** — one per line.
- **Blocks for the AI** — default: all enabled blocks except multi-column,
  heading and media.
- **Sections** — two themes the generated sections alternate between.
- **Background video / photo (hero)** — height, overlay and theme. The
  theme is chosen **automatically** by default: the plugin measures the
  brightness of the video (preview image) or photo (Pexels' average
  colour), darkens it by the black overlay and picks the theme whose
  heading colour has the best contrast. Themes and colours are read from
  the Project Wizard, so this works with every project's variants.

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
- `src/Pexels.php` — finds a photo/video, adds it with credit fields, measures its brightness
- `src/Settings.php` — the project's AI defaults (Project Wizard → AI)
- `src/ThemeContrast.php` — picks the theme with the best contrast on a background
