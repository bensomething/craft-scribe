<?php

namespace bensomething\scribe\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * A GitHub personal access token. Required to authenticate the API (so the
     * field lists your own public and private repositories) and lift the rate
     * limit. Falls back to the GITHUB_TOKEN environment variable.
     */
    public ?string $token = null;

    /**
     * How long, in seconds, to cache fetched data.
     */
    public int $cacheDuration = 1800;

    /**
     * Optional site template used to render each code block, given `code` and
     * `language` variables. When empty, Scribe emits a minimal Prism-ready
     * `<pre><code class="language-…">`.
     */
    public ?string $codeBlockTemplate = null;

    public function rules(): array
    {
        return [
            [['cacheDuration'], 'integer', 'min' => 0],
            [['token', 'codeBlockTemplate'], 'string'],
        ];
    }
}
