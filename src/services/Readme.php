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
 *
 * Any of these resolves to "owner/repo". The repo's canonical README is
 * fetched, so a URL pointing at a specific file still yields the README.
 */
class Readme extends Component
{
    // Tag on every cached item so they can be flushed as a group.
    public const CACHE_TAG = 'scribe:github';

    /**
     * Your own repositories, each with its root tree so the README can be
     * spotted without a second request per repo.
     */
    private const REPOS_QUERY = <<<'GRAPHQL'
    query($cursor: String) {
      viewer {
        repositories(first: 100, after: $cursor, ownerAffiliations: [OWNER], orderBy: {field: NAME, direction: ASC}) {
          pageInfo { hasNextPage endCursor }
          nodes {
            name
            nameWithOwner
            root: object(expression: "HEAD:") {
              ... on Tree { entries { name type } }
            }
          }
        }
      }
    }
    GRAPHQL;

    /**
     * Who the token belongs to, who owns one repo, and the branch its README
     * renders from — everything a page render needs before fetching.
     */
    private const REPO_META_QUERY = <<<'GRAPHQL'
    query($owner: String!, $name: String!) {
      viewer { login }
      repository(owner: $owner, name: $name) {
        owner { login }
        defaultBranchRef { name }
      }
    }
    GRAPHQL;

    /**
     * The repo list is held far longer than a README, and clearing Scribe's
     * caches flushes it. Building it makes GitHub resolve a git object for
     * every repository you own, which is slow enough that even the control
     * panel shouldn't rebuild it on the half hour.
     */
    private const REPOS_CACHE_DURATION = 21600; // 6 hours

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
     * @param bool $hideImages leave images out altogether
     */
    public function render(
        ?string $source,
        ?string $startFrom = null,
        ?string $endBefore = null,
        ?string $hideHeading = null,
        bool $hideImages = false,
    ): ?string {
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

        // Stripped here rather than before caching, so the cached payload stays
        // whole: the cache is keyed by repo alone, and two fields on the same
        // README can disagree about this.
        if ($hideImages) {
            $html = $this->parser()->dropImages($html);
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
     * The token account's own repositories (public and private) that have a
     * README, for the field's menu. `hint` is the README's filename, shown
     * faintly beside the repo name.
     *
     * @return array<int, array{value: string, label: string, data: array{hint: string}}>
     */
    public function repos(): array
    {
        $token = $this->token();
        if (!$token) {
            return [];
        }

        $cache = Craft::$app->getCache();
        // Versioned, so an entry cached under an older shape of this list is
        // passed over rather than served without its readme filenames.
        $cacheKey = 'scribe:repos:2:' . md5($token);

        $repos = $cache->get($cacheKey);
        if ($repos === false) {
            $repos = $this->fetchRepos();
            $cache->set($cacheKey, $repos, self::REPOS_CACHE_DURATION, new TagDependency(['tags' => self::CACHE_TAG]));
        }

        return $repos;
    }

    /**
     * Repos with a README, one GraphQL request per 100. The REST list endpoint
     * doesn't report whether a repo has a README, and checking each one costs a
     * request per repo — expensive enough that the field asks for this list over
     * Ajax when its menu is opened, rather than on the page render. GraphQL
     * returns each repo's root tree alongside it, so the check is free.
     */
    private function fetchRepos(): array
    {
        $out = [];
        $cursor = null;
        try {
            // Page until GraphQL says there's nothing after the cursor.
            for ($page = 1; $page <= 20; $page++) {
                $response = Craft::createGuzzleClient()->post('https://api.github.com/graphql', [
                    'headers' => $this->apiHeaders('application/json'),
                    'json' => ['query' => self::REPOS_QUERY, 'variables' => ['cursor' => $cursor]],
                    'timeout' => 15,
                ]);
                $data = json_decode((string)$response->getBody(), true) ?: [];
                if (!empty($data['errors'][0]['message'])) {
                    throw new \RuntimeException($data['errors'][0]['message']);
                }

                $repos = $data['data']['viewer']['repositories'] ?? [];
                foreach ($repos['nodes'] ?? [] as $repo) {
                    $readme = $this->readmeName($repo['root']['entries'] ?? []);
                    if ($readme === null || empty($repo['nameWithOwner'])) {
                        continue;
                    }
                    $out[] = [
                        'value' => $repo['nameWithOwner'],
                        'label' => $repo['name'] ?? $repo['nameWithOwner'],
                        'data' => ['hint' => $readme],
                    ];
                }

                if (empty($repos['pageInfo']['hasNextPage'])) {
                    break;
                }
                $cursor = $repos['pageInfo']['endCursor'] ?? null;
            }
        } catch (\Throwable $e) {
            Craft::warning('Scribe repo list failed: ' . $e->getMessage(), __METHOD__);
        }
        return $out;
    }

    /**
     * The README filename in a repo's root tree, or null if there isn't one.
     * Matched the way GitHub resolves it: any case, richest markup extension
     * first — so the name shown in the menu is the file that will be fetched.
     *
     * @param array<int, array{name?: string, type?: string}> $entries
     */
    private function readmeName(array $entries): ?string
    {
        // github/markup's preference order, most preferred first.
        $extensions = ['md', 'markdown', 'mdown', 'mkdn', 'rst', 'textile', 'rdoc', 'org', 'creole', 'pod', 'txt', ''];

        $name = null;
        $rank = count($extensions);
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') !== 'blob'
                || !preg_match('/^readme(?:\.([a-z0-9]+))?$/i', $entry['name'] ?? '', $m)
            ) {
                continue;
            }
            $entryRank = array_search(strtolower($m[1] ?? ''), $extensions, true);
            if ($entryRank !== false && $entryRank < $rank) {
                $name = $entry['name'];
                $rank = $entryRank;
            }
        }
        return $name;
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
        if ($repo === null) {
            return null;
        }

        // Only repos the token account owns may be fetched — answered by the
        // same lookup that reports the branch, so rendering never waits on the
        // repo list, which is far more expensive to build.
        $meta = $this->repoMeta($repo);
        if (!$meta['owned']) {
            return null;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'scribe:readme:' . $repo;

        $data = $cache->get($cacheKey);
        if ($data === false) {
            $raw = $this->fetch($repo);
            $data = $raw !== null
                ? $this->transform($raw, $repo, $meta['branch'])
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
     * What rendering needs to know about a repo, cached: whether the token
     * account owns it (only those may be fetched) and the branch GitHub
     * rendered its README from, so relative asset URLs resolve. One request
     * answers both, and it stays clear of the repo list — a page render should
     * never wait on that.
     *
     * @return array{owned: bool, branch: string}
     */
    private function repoMeta(string $repo): array
    {
        $cache = Craft::$app->getCache();
        $cacheKey = 'scribe:meta:' . md5((string)$this->token()) . ':' . $repo;

        $meta = $cache->get($cacheKey);
        if ($meta === false) {
            $meta = $this->fetchRepoMeta($repo);
            // A refusal is held briefly, so a transient failure or rate limit
            // can't lock a repo out for the full duration.
            $cache->set(
                $cacheKey,
                $meta,
                $meta['owned'] ? $this->cacheDuration() : 120,
                new TagDependency(['tags' => self::CACHE_TAG])
            );
        }

        return $meta;
    }

    /**
     * @return array{owned: bool, branch: string}
     */
    private function fetchRepoMeta(string $repo): array
    {
        $unknown = ['owned' => false, 'branch' => 'main'];
        if (!$this->token()) {
            return $unknown;
        }

        [$owner, $name] = array_pad(explode('/', $repo, 2), 2, '');

        try {
            $response = Craft::createGuzzleClient()->post('https://api.github.com/graphql', [
                'headers' => $this->apiHeaders('application/json'),
                'json' => [
                    'query' => self::REPO_META_QUERY,
                    'variables' => ['owner' => $owner, 'name' => $name],
                ],
                'timeout' => 8,
            ]);
            $data = json_decode((string)$response->getBody(), true)['data'] ?? [];

            // A repo the token can't see comes back null (with a NOT_FOUND
            // alongside it). That isn't a failure, just one Scribe won't touch.
            $repository = $data['repository'] ?? null;
            if (!is_array($repository)) {
                return $unknown;
            }

            $viewer = strtolower((string)($data['viewer']['login'] ?? ''));
            $ownedBy = strtolower((string)($repository['owner']['login'] ?? ''));

            return [
                'owned' => $viewer !== '' && $viewer === $ownedBy,
                'branch' => $repository['defaultBranchRef']['name'] ?? 'main',
            ];
        } catch (\Throwable $e) {
            Craft::warning("Scribe repo lookup failed for {$repo}: " . $e->getMessage(), __METHOD__);
            return $unknown;
        }
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
     *  - defer image loading
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
        $this->deferImages($xpath);

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

    /**
     * Let images load off the critical path — a README's images are typically
     * screenshots and badges, none of them worth blocking on. GitHub emits
     * neither attribute, but anything the README set itself is left alone.
     */
    private function deferImages(\DOMXPath $xpath): void
    {
        foreach ($xpath->query('//img') as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }
            if (!$img->hasAttribute('loading')) {
                $img->setAttribute('loading', 'lazy');
            }
            if (!$img->hasAttribute('decoding')) {
                $img->setAttribute('decoding', 'async');
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
