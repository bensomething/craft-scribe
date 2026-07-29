# Scribe for Craft CMS

Pull a GitHub README into your content, sliced by heading, so your docs pages mirror a repo and stay in sync with a single source of truth.

Scribe adds one field: pick a readme file, then optionally choose a **Start From** and **End Before** heading to show just a section. In your templates, render it as HTML that matches your site.

> [!NOTE]
> **Scribe is in beta.** It's feature-complete and safe to try, but the API, settings, and stored-value formats may still change before 1.0.0. Please [report anything you hit](https://github.com/bensomething/craft-scribe/issues).

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later
- A GitHub [personal access token](https://github.com/settings/personal-access-tokens)

## Installation

```bash
composer require bensomething/craft-scribe:^1.0.0-beta
php craft plugin/install scribe
```

The `-beta` in the constraint is what lets Composer install it under a project's default `stable` minimum stability.

## Setup

Scribe talks to the GitHub API, so it needs a token.

1. Create a **fine-grained personal access token** (GitHub → Settings → Developer settings → [Fine-grained tokens](https://github.com/settings/personal-access-tokens)).
   - **Repository access:** All repositories owned by you.
   - **Permissions:** Repository → **Contents: Read-only** (this includes Metadata: Read).
2. In **Settings → Plugins → Scribe**, paste the token into **GitHub Token**, or store it in an environment variable and reference it (e.g. `$GITHUB_TOKEN`).

The field then lists the readme files in your own repositories (public **and** private) — repos without one are left out — and Scribe will only ever fetch repos owned by that token account.

## Usage

Add a **Scribe** field to an entry type. When editing, choose a readme file — the menu lists each repository of yours that has one, with its filename alongside. The **Start From** / **End Before** menus then populate from that readme's headings (End Before only offers headings after Start From).

The field has two settings of its own. **Show Preview** renders the selected section right in the editor. **Hide Images** leaves images out of the rendered README, taking any link or paragraph they emptied with them — useful for dropping a badge row, and for private repositories, whose images your visitors can't load (GitHub serves them only to authenticated requests, so they'd show as broken).

Render it in a template:

```twig
{# The full README (or the chosen section) as HTML #}
{{ entry.myField.render() }}

{# Drop a leading heading that duplicates the page title #}
{{ entry.myField.render(entry.title) }}

{# Raw values #}
{{ entry.myField.url }}
{{ entry.myField.startFrom }}
{{ entry.myField.endBefore }}

{# All of the source's headings: [{ value, label, level }] #}
{% for h in entry.myField.headings %}{{ h.label }}{% endfor %}
```

`render()` returns GitHub-rendered HTML with repo-relative image/link URLs absolutized. Code blocks are emitted as a minimal `<pre><code class="language-…">` by default. Point the **Code Block Template** setting at a site template (given `code` and `language`) to use your own themed markup.

## Settings

| Setting | Description |
| --- | --- |
| **GitHub Token** | Required. Authenticates the API and scopes the field to your repositories. Supports env vars. |
| **Cache Duration** | How long (seconds) to cache fetched READMEs. Default `1800`. The list of your repositories is cached for six hours regardless, since it's slower to build and changes far less often. |
| **Code Block Template** | Optional site template used to render each code block. |

Fetched content is cached and can be flushed on its own via **Utilities → Caches → GitHub READMEs (Scribe)** or `php craft clear-caches scribe-readmes`.

## License

MIT
