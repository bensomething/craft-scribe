<?php

namespace bensomething\scribe\services;

use bensomething\scribe\Parser;
use bensomething\scribe\Plugin;
use Craft;
use craft\helpers\App;
use craft\web\View;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Fetches a GitHub repo's README, renders it for display, and reports its
 * headings. The engine behind the Scribe field.
 *
 * Accepts any of:
 *   - "owner/repo"
 *   - "https://github.com/owner/repo"
 *   - a blob/tree URL ("https://github.com/owner/repo/blob/main/README.md")
 *   - a raw URL ("https://raw.githubusercontent.com/owner/repo/main/README.md")
 */
class Readme extends Component
{
    // Tag on every cached item so they can be flushed as a group.
    public const CACHE_TAG = 'scribe:github';

    private ?Parser $parser = null;

    private function parser(): Parser
    {
        return $this->parser ??= new Parser();
    }

    /**
     * Render the README (optionally sliced to a section) as display HTML, or
     * null on failure so callers can fall back.
     *
     * @param string|null $startFrom heading slug to start at (inclusive)
     * @param string|null $endBefore heading slug to stop before (exclusive)
     * @param string|null $hideHeading drop a leading heading matching this text
     */
    public function render(?string $source, ?string $startFrom = null, ?string $endBefore = null, ?string $hideHeading = null): ?string
    {
        $data = $this->data($source);
        if ($data === null) {
            return null;
        }

        $html = $data['html'];

        // Slicing operates on the placeholder HTML (no rendered code cards to mangle).
        if ($startFrom || $endBefore) {
            $html = $this->parser()->slice($html, $startFrom, $endBefore) ?? $html;
        }

        if ($hideHeading) {
            $html = $this->parser()->dropLeadingHeading($html, $hideHeading);
        }

        return $this->restoreCodeBlocks($html, $data['cards']);
    }

    /**
     * All headings in the README, for populating Start From / End Before menus.
     *
     * @return array<int, array{value: string, label: string, level: int}>
     */
    public function headings(?string $source): array
    {
        $data = $this->data($source);
        if ($data === null) {
            return [];
        }

        return $this->parser()->headings($data['html']);
    }

    /**
     * The token account's own repositories (public and private), for the field's
     * repo menu.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function repos(): array
    {
        $token = $this->token();
        if (!$token) {
            return [];
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'scribe:repos:' . md5($token);

        $repos = $cache->get($cacheKey);
        if ($repos === false) {
            $repos = $this->fetchRepos();
            $cache->set($cacheKey, $repos, $this->cacheDuration(), new TagDependency(['tags' => self::CACHE_TAG]));
        }

        return $repos;
    }

    private function fetchRepos(): array
    {
        $out = [];
        $perPage = 100;
        try {
            // Page through /user/repos until a short page signals the end.
            for ($page = 1; $page <= 20; $page++) {
                $response = Craft::createGuzzleClient()->get('https://api.github.com/user/repos', [
                    'headers' => $this->apiHeaders('application/vnd.github+json'),
                    'query' => [
                        'per_page' => $perPage,
                        'page' => $page,
                        'sort' => 'full_name',
                        'affiliation' => 'owner',
                    ],
                    'timeout' => 8,
                ]);
                $data = json_decode((string)$response->getBody(), true) ?: [];
                foreach ($data as $repo) {
                    if (!empty($repo['full_name'])) {
                        $out[] = [
                            'value' => $repo['full_name'],
                            'label' => $repo['name'] ?? $repo['full_name'],
                        ];
                    }
                }
                if (count($data) < $perPage) {
                    break; // last page
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('Scribe repo list failed: ' . $e->getMessage(), __METHOD__);
        }
        return $out;
    }


    // =========================================================================
    // Fetch + cache
    // =========================================================================

    /**
     * The cached, transformed README payload for a source, or null if it can't
     * be fetched. `html` keeps code blocks as <!--CODEBLOCK:n--> placeholders
     * with the rendered cards held in `cards`, so their markup never round-trips
     * through DOMDocument.
     *
     * @return array{html: string, cards: string[]}|null
     */
    private function data(?string $source): ?array
    {
        $repo = $this->parser()->normalizeRepo($source);
        if ($repo === null || !$this->repoAllowed($repo)) {
            return null;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'scribe:readme:' . $repo;

        $data = $cache->get($cacheKey);
        if ($data === false) {
            $raw = $this->fetch($repo);
            $data = $raw !== null
                ? $this->transform($raw, $repo, $this->fetchDefaultBranch($repo))
                : ['html' => '', 'cards' => []];
            // Cache successes for the full duration. Cache misses briefly so a
            // transient failure or rate-limit doesn't hammer the API.
            $cache->set(
                $cacheKey,
                $data,
                $raw !== null ? $this->cacheDuration() : 120,
                new TagDependency(['tags' => self::CACHE_TAG])
            );
        }

        return ($data['html'] ?? '') !== '' ? $data : null;
    }

    private function fetch(string $repo): ?string
    {
        try {
            $response = Craft::createGuzzleClient()->get("https://api.github.com/repos/{$repo}/readme", [
                'headers' => $this->apiHeaders('application/vnd.github.html+json'),
                'timeout' => 8,
            ]);
            $html = (string)$response->getBody();
            return $html !== '' ? $html : null;
        } catch (\Throwable $e) {
            Craft::warning("Scribe README fetch failed for {$repo}: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * The repo's default branch (cached), so relative asset URLs resolve to the
     * branch GitHub rendered the README from. Falls back to "main".
     */
    private function fetchDefaultBranch(string $repo): string
    {
        $cache = Craft::$app->getCache();
        $cacheKey = 'scribe:branch:' . $repo;

        $branch = $cache->get($cacheKey);
        if ($branch !== false && $branch !== '') {
            return $branch;
        }

        $branch = 'main';
        try {
            $response = Craft::createGuzzleClient()->get("https://api.github.com/repos/{$repo}", [
                'headers' => $this->apiHeaders('application/vnd.github+json'),
                'timeout' => 8,
            ]);
            $data = json_decode((string)$response->getBody(), true);
            if (!empty($data['default_branch'])) {
                $branch = $data['default_branch'];
            }
        } catch (\Throwable $e) {
            Craft::warning("Scribe branch lookup failed for {$repo}: " . $e->getMessage(), __METHOD__);
        }

        $cache->set($cacheKey, $branch, $this->cacheDuration(), new TagDependency(['tags' => self::CACHE_TAG]));
        return $branch;
    }

    private function apiHeaders(string $accept): array
    {
        $headers = [
            'User-Agent' => 'craft-scribe',
            'Accept' => $accept,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = $this->token();
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * Only the token account's own repositories (those in its repo list) may be
     * fetched, needing just the Contents/Metadata read the token already grants.
     */
    private function repoAllowed(string $repo): bool
    {
        if (!$this->token()) {
            return false;
        }
        $repo = strtolower($repo);
        foreach ($this->repos() as $r) {
            if (strtolower($r['value']) === $repo) {
                return true;
            }
        }
        return false;
    }

    private function cacheDuration(): int
    {
        return Plugin::getInstance()->getSettings()->cacheDuration;
    }

    public function hasToken(): bool
    {
        return (bool)$this->token();
    }

    private function token(): ?string
    {
        return App::parseEnv(Plugin::getInstance()->getSettings()->token) ?: App::env('GITHUB_TOKEN');
    }

    // =========================================================================
    // Transform (absolutize URLs + extract code blocks)
    // =========================================================================

    /**
     * Prepare fetched README HTML for display in a single DOM pass:
     *  - absolutize repo-relative image/link URLs to their GitHub source
     *  - swap code blocks for <!--CODEBLOCK:n--> placeholders, holding the
     *    rendered cards separately so their markup never round-trips DOMDocument.
     *
     * @return array{html: string, cards: string[]}
     */
    private function transform(string $html, string $repo, string $branch): array
    {
        $doc = $this->parser()->loadDoc($html);
        $xpath = new \DOMXPath($doc);

        $this->absolutizeUrls($xpath, $repo, $branch);

        // GitHub wraps code blocks either as <div class="highlight highlight-source-xxx">
        // (language fences) or <div class="snippet-clipboard-content"> (plain
        // fences). Replace the whole outermost wrapper. Bare <pre> covers the rest.
        $isCodeWrap = "contains(concat(' ', normalize-space(@class), ' '), ' highlight ')"
            . " or contains(concat(' ', normalize-space(@class), ' '), ' snippet-clipboard-content ')";
        $blocks = $xpath->query(
            "//div[({$isCodeWrap}) and not(ancestor::div[{$isCodeWrap}])]"
            . " | //pre[not(ancestor::div[{$isCodeWrap}])]"
        );

        // Snapshot the node list first, since replacing nodes mutates the live list.
        $nodes = [];
        foreach ($blocks as $node) {
            $nodes[] = $node;
        }

        $cards = [];
        foreach ($nodes as $node) {
            $code = $this->extractCode($node);
            if ($code === '' || $node->parentNode === null) {
                continue;
            }

            $index = count($cards);
            $cards[] = $this->renderCodeBlock($code, $this->detectLanguage($node));
            $node->parentNode->replaceChild($doc->createComment("CODEBLOCK:{$index}"), $node);
        }

        return ['html' => $this->parser()->innerHtml($doc), 'cards' => $cards];
    }

    /**
     * Render one code block. Uses the configured site template if set (given
     * `code` + `language`), otherwise a minimal Prism-ready <pre><code>.
     */
    private function renderCodeBlock(string $code, string $language): string
    {
        $template = Plugin::getInstance()->getSettings()->codeBlockTemplate;
        if ($template) {
            try {
                return Craft::$app->getView()->renderTemplate(
                    $template,
                    ['code' => $code, 'language' => $language],
                    View::TEMPLATE_MODE_SITE
                );
            } catch (\Throwable $e) {
                Craft::warning('Scribe code block template failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        return '<pre><code class="language-' . htmlspecialchars($language, ENT_QUOTES) . '">'
            . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE)
            . '</code></pre>';
    }

    private function extractCode(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement && $node->hasAttribute('data-snippet-clipboard-copy-content')) {
            return rtrim($node->getAttribute('data-snippet-clipboard-copy-content'), "\n");
        }
        return rtrim($node->textContent, "\n");
    }

    /**
     * Derive a language slug from a GitHub highlight class such as
     * "highlight-source-shell" or "highlight-text-html-twig".
     */
    private function detectLanguage(\DOMNode $node): string
    {
        $class = $node instanceof \DOMElement ? $node->getAttribute('class') : '';
        if ($class === '' && $node->parentNode instanceof \DOMElement) {
            $class = $node->parentNode->getAttribute('class');
        }

        if (preg_match('/highlight-(?:source|text)-(?:[a-z0-9]+-)*([a-z0-9]+)/i', $class, $m)) {
            return strtolower($m[1]);
        }

        return 'plaintext';
    }

    private function absolutizeUrls(\DOMXPath $xpath, string $repo, string $branch): void
    {
        foreach ($xpath->query('//img[@src]') as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }
            $abs = $this->absoluteUrl($img->getAttribute('src'), $repo, $branch, true);
            if ($abs !== null) {
                $img->setAttribute('src', $abs);
            }
        }
        foreach ($xpath->query('//a[@href]') as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $abs = $this->absoluteUrl($a->getAttribute('href'), $repo, $branch, false);
            if ($abs !== null) {
                $a->setAttribute('href', $abs);
            }
        }
    }

    private function absoluteUrl(string $url, string $repo, string $branch, bool $isImage): ?string
    {
        $url = trim($url);
        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with($url, '//')
            || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $url) // has a scheme
        ) {
            return null;
        }

        $path = ltrim($url, '/');
        $base = $isImage
            ? "https://raw.githubusercontent.com/{$repo}/{$branch}/"
            : "https://github.com/{$repo}/blob/{$branch}/";

        return $base . $path;
    }

    private function restoreCodeBlocks(string $html, array $cards): string
    {
        if ($cards === []) {
            return $html;
        }
        return preg_replace_callback('/<!--CODEBLOCK:(\d+)-->/', static function($m) use ($cards) {
            return $cards[(int)$m[1]] ?? '';
        }, $html);
    }
}
