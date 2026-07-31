<?php

namespace bensomething\scribe\fields;

use bensomething\scribe\models\ReadmeValue;
use bensomething\scribe\Plugin;
use bensomething\scribe\web\assets\field\ScribeFieldAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;
use yii\db\Schema;

/**
 * Scribe field: a GitHub source (repo README or a specific .md) plus an
 * optional Start From / End Before heading range.
 */
class Readme extends Field
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
        $service = Plugin::getInstance()->getReadme();
        $headings = (!$value->isEmpty()) ? $service->headings($value->url) : [];
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
                    'preview' => $this->showPreview,
                    'hideImages' => $this->hideImages,
                    // Keys the remembered open/closed state of the preview pane.
                    'handle' => $this->handle,
                    'headings' => $headings,
                    // Placeholders for the heading menus' blank option, which stands
                    // in for the labels the field doesn't show.
                    'startPlaceholder' => Craft::t('scribe', 'Start from…'),
                    'endPlaceholder' => Craft::t('scribe', 'End before…'),
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
            'isStatic' => $isStatic,
        ]);
    }

    public function getElementValidationRules(): array
    {
        return [];
    }
}
