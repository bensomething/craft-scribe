<?php

namespace bensomething\scribe\fields;

use bensomething\scribe\models\ReadmeValue;
use bensomething\scribe\Plugin;
use bensomething\scribe\web\assets\field\ScribeFieldAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\db\Schema;

/**
 * Scribe field: a GitHub source (repo README or a specific .md) plus an
 * optional Start From / End Before heading range.
 */
class Readme extends Field implements PreviewableFieldInterface
{
    public bool $showPreview = false;
    public bool $hideImages = false;

    public static function displayName(): string
    {
        return Craft::t('scribe', 'Scribe');
    }

    public static function icon(): string
    {
        return 'feather';
    }

    public static function dbType(): array
    {
        return [
            'url' => Schema::TYPE_STRING,
            'startFrom' => Schema::TYPE_STRING,
            'endBefore' => Schema::TYPE_STRING,
        ];
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof ReadmeValue) {
            // Re-stamp, in case the field's setting has changed since.
            $value->hideImages = $this->hideImages;
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }
        $value = is_array($value) ? $value : [];

        return new ReadmeValue([
            'url' => $value['url'] ?? null,
            'startFrom' => $value['startFrom'] ?? null,
            'endBefore' => $value['endBefore'] ?? null,
            'hideImages' => $this->hideImages,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof ReadmeValue) {
            return $value;
        }
        return [
            'url' => $value->url ?: null,
            'startFrom' => $value->startFrom ?: null,
            'endBefore' => $value->endBefore ?: null,
        ];
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('scribe/_field/settings.twig', [
            'field' => $this,
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        return $this->fieldHtml($value, false);
    }

    /**
     * Craft renders static fields (revisions, read-only elements) with the JS
     * buffer discarded, so selectize never initialises — and since it's what
     * replaces the select it hides on setup, the readme menu would render
     * invisible. Fall back to a plain menu that stands on its own.
     */
    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        return Html::disableInputs(fn() => $this->fieldHtml($value, true)) ?? '';
    }

    private function fieldHtml(mixed $value, bool $isStatic): string
    {
        /** @var ReadmeValue $value */
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ScribeFieldAsset::class);

        $id = $this->getInputId();
        // Keys the remembered open/closed state of the preview pane, on the
        // input's namespaced id rather than the field's handle: a field can be
        // on the page more than once — in each block of a Matrix, say — and each
        // of those is opened and closed on its own.
        $previewKey = 'scribe-preview-' . $view->namespaceInputId($id);
        $service = Plugin::getInstance()->getReadme();
        $headings = (!$value->isEmpty()) ? $service->headings($value->url) : [];
        // Only asked so the note standing in for the heading menus can say which
        // of the two empty cases it is. Free: the payload is already loaded.
        $readmeExists = !$value->isEmpty() && $service->exists($value->url);
        // The range's own headings, where the readme it was saved against no
        // longer has them.
        $missing = $service->missingHeadings($value->url, $value->startFrom, $value->endBefore);
        $previewHtml = ($this->showPreview && !$value->isEmpty())
            ? $service->render($value->url, $value->startFrom, $value->endBefore, null, $this->hideImages)
            : null;

        if (!$isStatic) {
            $view->registerJs(sprintf(
                'new Craft.ScribeField(%s, %s);',
                Json::encode($view->namespaceInputId($id)),
                Json::encode([
                    'headingsAction' => 'scribe/headings',
                    'previewAction' => 'scribe/preview',
                    'reposAction' => 'scribe/repos',
                    'refreshAction' => 'scribe/refresh',
                    'preview' => $this->showPreview,
                    'hideImages' => $this->hideImages,
                    'previewKey' => $previewKey,
                    'headings' => $headings,
                    'missing' => $missing,
                    // The mark shown against every repository in the readme
                    // menu, rendered here because only the server can turn a
                    // system icon's name into one — the menu's saved option gets
                    // it by name in the template, and the repositories fetched
                    // over Ajax are given this.
                    'repoIcon' => Cp::iconSvg('github'),
                    // Placeholders for the heading menus' blank option, which stands
                    // in for the labels the field doesn't show.
                    'startPlaceholder' => Craft::t('scribe', 'Start from…'),
                    'endPlaceholder' => Craft::t('scribe', 'End before…'),
                    // The note that stands in for those menus when there's no
                    // range to pick, in each of its two cases.
                    'noHeadingsText' => Craft::t('scribe', 'No headings found'),
                    'loadFailedText' => Craft::t('scribe', 'Couldn’t load this readme'),
                    // The warning under the field, in each of the three ways a
                    // saved range can outlive the headings it was set to. The
                    // template words the one it renders with; these are for the
                    // JS, which takes the warning over as the editor answers it.
                    'staleStartText' => Craft::t('scribe', 'Start From is set to a heading this readme no longer has, so the whole of it renders.'),
                    'staleEndText' => Craft::t('scribe', 'End Before is set to a heading this readme no longer has, so the section runs to the end of it.'),
                    'staleBothText' => Craft::t('scribe', 'Start From and End Before are set to headings this readme no longer has, so the whole of it renders.'),
                ])
            ));
        }

        return $view->renderTemplate('scribe/_field/input.twig', [
            'id' => $id,
            'name' => $this->handle,
            'value' => $value,
            'hasToken' => $service->hasToken(),
            'showPreview' => $this->showPreview,
            'previewHtml' => $previewHtml,
            'headings' => $headings,
            'missing' => $missing,
            'readmeExists' => $readmeExists,
            'previewOpen' => $this->previewWasOpen($previewKey),
            'isStatic' => $isStatic,
        ]);
    }

    /**
     * Whether the preview pane was last left open, as the field's own JS
     * recorded it — open being the default until an editor closes it. Read
     * here rather than applied by that JS, so the pane is painted in the state
     * it's going to stay in: applying it afterwards flashed a closed pane open.
     *
     * Read straight from $_COOKIE, since the request's own cookie collection
     * holds only the signed ones Craft wrote itself.
     */
    private function previewWasOpen(string $key): bool
    {
        // Matched on the end of the name rather than rebuilt in full: Craft's
        // JS helper prefixes whatever it's given with the system UID, joined by
        // an underscore as of 5.10 and by a colon in earlier releases. One
        // instance's key can't end another's: the marker in the middle of it
        // would have to line up with the end of the other's namespace.
        foreach ($_COOKIE as $name => $value) {
            if (str_ends_with($name, $key)) {
                return $value !== '0';
            }
        }

        // Open until an editor closes it themselves: a pane with something in
        // it is worth seeing by default.
        return true;
    }

    public function getElementValidationRules(): array
    {
        return [];
    }

    /**
     * What the field amounts to in a column or on a card: the repository, and
     * the range read off it — "craft-dub (Requirements → Usage)". Plain text
     * rather than the readme itself, which is a page of prose and belongs
     * nowhere near a table row.
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return $this->previewHtml($value);
    }

    /**
     * Stands in for the field where there's no element to read one from — the
     * card designer, say. Craft's own default hands back the value itself, which
     * for this field is a whole rendered readme.
     */
    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return $this->previewHtml($value) ?: Html::encode($this->getUiLabel());
    }

    private function previewHtml(mixed $value): string
    {
        if (!$value instanceof ReadmeValue || $value->isEmpty()) {
            return '';
        }

        // Named by its repository, as the field's own menu names it.
        $parts = explode('/', $value->url);
        $repo = Html::encode(end($parts) ?: $value->url);

        // Only what's already held: an index draws a row per element, and none
        // of them is worth a trip to GitHub. Null where nothing is held, which
        // leaves the headings named by the anchors they were saved as and
        // nothing said about whether the readme still has them — an answer this
        // can't reach for without becoming the request it's avoiding.
        $headings = Plugin::getInstance()->getReadme()->cachedHeadings($value->url);

        $start = $this->headingHtml($value->startFrom, $headings);
        $end = $this->headingHtml($value->endBefore, $headings);
        if ($start === '' && $end === '') {
            return $repo;
        }

        // The arrow belongs to the end it points at, so a range that runs on to
        // the bottom of the readme goes without one — "Requirements" rather than
        // "Requirements →". One that starts at the top keeps it, since "→ Usage"
        // is what says the reading is up to Usage rather than from it.
        $range = $end !== '' ? trim("$start → $end") : $start;

        // Fainter than the repository: the range qualifies it rather than
        // standing alongside it.
        return $repo . ' ' . Html::tag('span', "($range)", ['class' => 'light']);
    }

    /**
     * One end of the range, named by its heading — or by the anchor it was saved
     * as, where the readme's headings aren't at hand to name it any better.
     * Marked with an alert where they are and it isn't among them.
     *
     * @param array<int, array{value: string, label: string, level: int}>|null $headings
     */
    private function headingHtml(?string $slug, ?array $headings): string
    {
        if (($slug ?? '') === '') {
            return '';
        }

        $labels = array_column($headings ?? [], 'label', 'value');
        if (isset($labels[$slug])) {
            return Html::encode($labels[$slug]);
        }

        // Nothing to name it by, so the anchor stands in — read back towards the
        // heading it was made from, since that's how it was made: lowercased,
        // with its spaces turned to hyphens. The capitals inside a heading are
        // past recovering ("Getting started" for "Getting Started"), which is a
        // fair trade against a column that reads in slugs every time Scribe's
        // caches are cleared.
        $name = Html::encode(StringHelper::upperCaseFirst(str_replace('-', ' ', $slug)));

        if ($headings === null) {
            return $name;
        }

        // The Craft icon font rather than an SVG: the field's own stylesheet
        // isn't loaded on an index, and this asks nothing of it.
        $alt = Craft::t('scribe', 'This heading is no longer in the readme');
        return Html::tag('span', '', [
                'class' => 'warning',
                'data-icon' => 'alert',
                'title' => $alt,
                'role' => 'img',
                'aria' => ['label' => $alt],
            ]) . $name;
    }
}
