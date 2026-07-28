<?php

namespace bensomething\scribe\tests\Unit;

use bensomething\scribe\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    /**
     * A stripped-down version of the HTML GitHub returns for a rendered README:
     * a .markdown-body container whose direct children are .markdown-heading
     * wrappers (each holding a heading and a user-content anchor) and content.
     */
    private function fixture(): string
    {
        return <<<'HTML'
<div class="markdown-body">
<div class="markdown-heading"><h1 class="heading-element">My Plugin</h1><a id="user-content-my-plugin" class="anchor" href="#my-plugin"></a></div>
<p>Intro paragraph.</p>
<div class="markdown-heading"><h2 class="heading-element">Requirements</h2><a id="user-content-requirements" class="anchor" href="#requirements"></a></div>
<ul><li>Craft CMS 5</li></ul>
<div class="markdown-heading"><h2 class="heading-element">Installation</h2><a id="user-content-installation" class="anchor" href="#installation"></a></div>
<p>Install steps.</p>
<div class="markdown-heading"><h3 class="heading-element">From source</h3><a id="user-content-from-source" class="anchor" href="#from-source"></a></div>
<p>Source detail.</p>
<div class="markdown-heading"><h2 class="heading-element">Usage</h2><a id="user-content-usage" class="anchor" href="#usage"></a></div>
<p>Usage info.</p>
</div>
HTML;
    }

    /**
     * @dataProvider repoProvider
     */
    public function testNormalizeRepo(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->normalizeRepo($input));
    }

    public static function repoProvider(): array
    {
        return [
            'owner/repo shorthand' => ['owner/repo', 'owner/repo'],
            'https url' => ['https://github.com/owner/repo', 'owner/repo'],
            'blob url' => ['https://github.com/owner/repo/blob/main/README.md', 'owner/repo'],
            'raw url' => ['https://raw.githubusercontent.com/owner/repo/main/README.md', 'owner/repo'],
            'trailing .git' => ['https://github.com/owner/repo.git', 'owner/repo'],
            'surrounding whitespace' => ['  owner/repo  ', 'owner/repo'],
            'empty string' => ['', null],
            'null' => [null, null],
            'non-github url' => ['https://example.com/some/path', null],
            'plain text' => ['not a repo', null],
        ];
    }

    public function testHeadingsReadsEveryHeading(): void
    {
        $headings = $this->parser->headings($this->fixture());

        $this->assertCount(5, $headings);
        $this->assertSame(
            ['my-plugin', 'requirements', 'installation', 'from-source', 'usage'],
            array_column($headings, 'value')
        );
        $this->assertSame('My Plugin', $headings[0]['label']);
        $this->assertSame(1, $headings[0]['level']);
        $this->assertSame(3, $headings[3]['level']); // "From source" is an h3
    }

    public function testHeadingsOnEmptyHtml(): void
    {
        $this->assertSame([], $this->parser->headings(''));
    }

    public function testSliceBetweenTwoHeadings(): void
    {
        $html = $this->parser->slice($this->fixture(), 'requirements', 'installation');

        $this->assertNotNull($html);
        $this->assertStringContainsString('Requirements', $html);
        $this->assertStringContainsString('Craft CMS 5', $html);
        // End Before is exclusive, so nothing from Installation onward.
        $this->assertStringNotContainsString('Installation', $html);
        $this->assertStringNotContainsString('Install steps', $html);
    }

    public function testSliceFromHeadingToEnd(): void
    {
        $html = $this->parser->slice($this->fixture(), 'installation', null);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Installation', $html);
        $this->assertStringContainsString('From source', $html);
        $this->assertStringContainsString('Usage', $html);
        $this->assertStringNotContainsString('Requirements', $html);
    }

    public function testSliceFromTopToHeading(): void
    {
        $html = $this->parser->slice($this->fixture(), null, 'requirements');

        $this->assertNotNull($html);
        $this->assertStringContainsString('My Plugin', $html);
        $this->assertStringContainsString('Intro paragraph', $html);
        $this->assertStringNotContainsString('Requirements', $html);
    }

    public function testSliceReturnsNullWhenStartMissing(): void
    {
        $this->assertNull($this->parser->slice($this->fixture(), 'no-such-heading', null));
    }

    public function testSliceReturnsNullWithNoBounds(): void
    {
        $this->assertNull($this->parser->slice($this->fixture(), null, null));
    }

    public function testDropLeadingHeadingRemovesMatch(): void
    {
        $section = $this->parser->slice($this->fixture(), 'requirements', 'installation');
        $this->assertNotNull($section);

        $dropped = $this->parser->dropLeadingHeading($section, 'Requirements');
        $this->assertStringNotContainsString('Requirements', $dropped);
        $this->assertStringContainsString('Craft CMS 5', $dropped);
    }

    public function testDropLeadingHeadingLeavesNonMatchAlone(): void
    {
        $section = $this->parser->slice($this->fixture(), 'requirements', 'installation');
        $this->assertNotNull($section);

        $unchanged = $this->parser->dropLeadingHeading($section, 'Something Else');
        $this->assertStringContainsString('Requirements', $unchanged);
    }
}
