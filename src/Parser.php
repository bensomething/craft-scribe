<?php

namespace bensomething\scribe;

/**
 * Pure HTML/URL parsing for the Scribe field: normalizing a repo reference,
 * reading a README's headings, and slicing its rendered HTML by heading anchor.
 *
 * Deliberately free of Craft and Yii, so the fiddly DOM logic can be unit
 * tested on its own. The Readme service handles fetching, caching, and code
 * block rendering, and leans on this for the parsing.
 */
class Parser
{
    /**
     * Normalize a repo reference to "owner/repo", accepting any of:
     *   - "owner/repo"
     *   - "https://github.com/owner/repo"
     *   - a blob/tree URL ("https://github.com/owner/repo/blob/main/README.md")
     *   - a raw URL ("https://raw.githubusercontent.com/owner/repo/main/README.md")
     *
     * Any of these resolves to "owner/repo". The repo's canonical README is
     * fetched, so a URL pointing at a specific file still yields the README.
     * Returns null if nothing repo-shaped is found.
     */
    public function normalizeRepo(?string $source): ?string
    {
        $source = trim((string)$source);
        if ($source === '') {
            return null;
        }

        if (preg_match('~(?:github\.com|raw\.githubusercontent\.com)/([^/\s]+)/([^/\s]+)~i', $source, $m)) {
            return $m[1] . '/' . preg_replace('/\.git$/', '', $m[2]);
        }
        if (preg_match('~^([^/\s]+)/([^/\s]+)$~', $source, $m)) {
            return $m[1] . '/' . preg_replace('/\.git$/', '', $m[2]);
        }

        return null;
    }

    /**
     * Every heading in the README, for populating Start From / End Before menus.
     *
     * @return array<int, array{value: string, label: string, level: int}>
     */
    public function headings(string $html): array
    {
        $doc = $this->loadDoc($html);
        $xpath = new \DOMXPath($doc);

        $out = [];
        $wrappers = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' markdown-heading ')]"
        );
        foreach ($wrappers as $wrapper) {
            $heading = $xpath->query(
                './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][1]',
                $wrapper
            )->item(0);
            $anchor = $xpath->query(".//a[starts-with(@id, 'user-content-')][1]", $wrapper)->item(0);
            if (!$heading || !$anchor instanceof \DOMElement) {
                continue;
            }

            $out[] = [
                'value' => substr($anchor->getAttribute('id'), 13), // strip "user-content-"
                'label' => trim(preg_replace('/\s+/', ' ', $heading->textContent)),
                'level' => (int)substr(strtolower($heading->nodeName), 1),
            ];
        }

        return $out;
    }

    /**
     * README HTML between two heading anchors: from $startAnchor (inclusive, or
     * the top if null) up to $endAnchor (exclusive, or the end if null). Null if
     * a named start anchor isn't found, so callers can fall back to the full README.
     */
    public function slice(string $html, ?string $startAnchor, ?string $endAnchor): ?string
    {
        $startAnchor = $startAnchor ? ltrim(trim($startAnchor), '#') : null;
        $endAnchor = $endAnchor ? ltrim(trim($endAnchor), '#') : null;
        if ($startAnchor === '') {
            $startAnchor = null;
        }
        if ($endAnchor === '') {
            $endAnchor = null;
        }
        if ($startAnchor === null && $endAnchor === null) {
            return null;
        }

        $doc = $this->loadDoc($html);
        $xpath = new \DOMXPath($doc);
        $container = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' markdown-body ')]"
        )->item(0);

        if ($startAnchor !== null) {
            $startAnchorNode = $this->findAnchorNode($xpath, $startAnchor);
            if (!$startAnchorNode) {
                return null;
            }
            $start = $this->sectionStart($startAnchorNode, $container);
        } else {
            $start = $this->firstFlowNode($xpath, $container);
        }
        if (!$start) {
            return null;
        }

        $end = null;
        if ($endAnchor !== null) {
            $endAnchorNode = $this->findAnchorNode($xpath, $endAnchor);
            if ($endAnchorNode) {
                $end = $this->sectionStart($endAnchorNode, $container);
            }
        }

        $result = '';
        for ($node = $start; $node !== null && $node !== $end; $node = $node->nextSibling) {
            $result .= $doc->saveHTML($node);
        }

        return trim($result) !== '' ? $result : null;
    }

    /**
     * Remove the first heading if its text matches $title, along with its GitHub
     * markdown-heading wrapper (so the octicon anchor goes too).
     */
    public function dropLeadingHeading(string $html, string $title): string
    {
        $title = $this->normalizeHeading($title);
        if ($title === '') {
            return $html;
        }

        $doc = $this->loadDoc($html);
        $xpath = new \DOMXPath($doc);
        $heading = $xpath->query('(//h1|//h2|//h3|//h4|//h5|//h6)[1]')->item(0);
        if (!$heading || $this->normalizeHeading($heading->textContent) !== $title) {
            return $html;
        }

        $container = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' markdown-body ')]"
        )->item(0);
        $block = $this->sectionStart($heading, $container);
        if ($block->parentNode !== null) {
            $block->parentNode->removeChild($block);
        }

        return $this->innerHtml($doc);
    }

    /**
     * Parse README HTML into a DOMDocument, wrapped in a known root so inner
     * fragments have a stable container. Silences libxml's HTML5 gripes.
     */
    public function loadDoc(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    /**
     * Serialize everything inside the __root wrapper back to an HTML string.
     */
    public function innerHtml(\DOMDocument $doc): string
    {
        $root = (new \DOMXPath($doc))->query("//*[@id='__root']")->item(0);
        if ($root === null) {
            return '';
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * Find the heading anchor for a slug. GitHub gives each heading a permalink
     * <a id="user-content-slug">. Match that id specifically, NOT arbitrary
     * <a href="#slug">, which also matches inline cross-reference links and
     * would move a slice boundary to the wrong, earlier place.
     */
    private function findAnchorNode(\DOMXPath $xpath, string $anchor): ?\DOMNode
    {
        return $xpath->query("//a[@id=" . $this->xpathLiteral('user-content-' . $anchor) . "]")->item(0)
            ?? $xpath->query(
                "//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]"
                . "[@id=" . $this->xpathLiteral($anchor) . "]"
            )->item(0);
    }

    private function firstFlowNode(\DOMXPath $xpath, ?\DOMNode $container): ?\DOMNode
    {
        $parent = $container ?? $xpath->query("//*[@id='__root']")->item(0);
        if ($parent === null) {
            return null;
        }
        for ($node = $parent->firstChild; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof \DOMElement) {
                return $node;
            }
        }
        return null;
    }

    /**
     * The top-level flow block (child of the markdown-body container) that holds
     * the given node, the correct slice boundary rather than the inner heading.
     */
    private function sectionStart(\DOMNode $node, ?\DOMNode $container): \DOMNode
    {
        if ($container !== null) {
            $cursor = $node;
            while ($cursor->parentNode !== null && $cursor->parentNode !== $container) {
                $cursor = $cursor->parentNode;
            }
            if ($cursor->parentNode === $container) {
                return $cursor;
            }
        }

        if ($node->parentNode instanceof \DOMElement) {
            return $node->parentNode;
        }
        return $node;
    }

    private function normalizeHeading(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }
}
