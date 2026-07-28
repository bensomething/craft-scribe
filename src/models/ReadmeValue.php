<?php

namespace bensomething\scribe\models;

use bensomething\scribe\Plugin;
use craft\base\Model;
use Twig\Markup;

/**
 * The value of a Scribe field: a GitHub source plus an optional heading range.
 */
class ReadmeValue extends Model
{
    public ?string $url = null;
    public ?string $startFrom = null;
    public ?string $endBefore = null;

    public function isEmpty(): bool
    {
        return trim((string)$this->url) === '';
    }

    /**
     * The rendered README (sliced to the configured range), or null.
     *
     * @param string|null $hideHeading drop a leading heading matching this text
     *                                  (e.g. the page title, to avoid a duplicate)
     */
    public function render(?string $hideHeading = null): ?Markup
    {
        if ($this->isEmpty()) {
            return null;
        }
        $html = Plugin::getInstance()->getReadme()->render($this->url, $this->startFrom, $this->endBefore, $hideHeading);
        return $html !== null ? new Markup($html, 'UTF-8') : null;
    }

    /**
     * The source README's headings.
     *
     * @return array<int, array{value: string, label: string, level: int}>
     */
    public function headings(): array
    {
        return $this->isEmpty() ? [] : Plugin::getInstance()->getReadme()->headings($this->url);
    }

    public function __toString(): string
    {
        return (string)($this->render() ?? '');
    }
}
