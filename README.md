# Scribe for Craft CMS

Pull a GitHub README (or any Markdown file) into your content, sliced by heading — so your docs pages mirror a repo and stay in sync with a single source of truth.

Scribe adds one field: pick a repository, then optionally choose a **Start From** and **End Before** heading to show just a section. In your templates, render it as HTML that matches your site.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later
- A GitHub personal access token

## Installation

```sh
composer require bensomething/craft-scribe
php craft plugin/install scribe
```

## Setup

Scribe talks to the GitHub API, so it needs a token.

1. Create a **fine-grained personal access token** (GitHub → Settings → Developer settings → Fine-grained tokens).
   - **Repository access:** All repositories owned by you.
   - **Permissions:** Repository → **Contents: Read-only** (this includes Metadata: Read).
2. In **Settings → Plugins → Scribe**, paste the token into **GitHub Token** — or, better, store it in an environment variable and reference it (e.g. `$GITHUB_TOKEN`).

The field then lists your own repositories (public **and** private), and Scribe will only ever fetch repos owned by that token account.

## Usage

Add a **Scribe** field to an entry type. When editing, choose a repository; the **Start From** / **End Before** menus populate from that repo's README headings (End Before only offers headings after Start From).

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

`render()` returns GitHub-rendered HTML with repo-relative image/link URLs absolutized. Code blocks are emitted as a minimal `<pre><code class="language-…">` by default; point the **Code Block Template** setting at a site template (given `code` and `language`) to use your own themed markup.

## Settings

| Setting | Description |
| --- | --- |
| **GitHub Token** | Required. Authenticates the API and scopes the field to your repositories. Supports env vars. |
| **Cache Duration** | How long (seconds) to cache fetched data. Default `1800`. |
| **Code Block Template** | Optional site template used to render each code block. |

Fetched content is cached and can be flushed on its own via **Utilities → Caches → GitHub READMEs (Scribe)** or `php craft clear-caches scribe-readmes`.

## License

MIT
