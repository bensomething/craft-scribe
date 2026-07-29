<?php

namespace bensomething\scribe\fields;

use bensomething\scribe\models\ReadmeValue;
use bensomething\scribe\Plugin;
use bensomething\scribe\web\assets\field\ScribeFieldAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Json;
use yii\db\Schema;

/**
 * Scribe field: a GitHub source (repo README or a specific .md) plus an
 * optional Start From / End Before heading range.
 */
class Readme extends Field
{
    public bool $showPreview = false;

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
        /** @var ReadmeValue $value */
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ScribeFieldAsset::class);

        $id = $this->getInputId();
        $service = Plugin::getInstance()->getReadme();
        $headings = (!$value->isEmpty()) ? $service->headings($value->url) : [];
        $previewHtml = ($this->showPreview && !$value->isEmpty())
            ? $service->render($value->url, $value->startFrom, $value->endBefore)
            : null;

        $view->registerJs(sprintf(
            'new Craft.ScribeField(%s, %s);',
            Json::encode($view->namespaceInputId($id)),
            Json::encode([
                'headingsAction' => 'scribe/headings',
                'previewAction' => 'scribe/preview',
                'preview' => $this->showPreview,
                'headings' => $headings,
                // Placeholders for the heading menus' blank option, which stands
                // in for the labels the field doesn't show.
                'startPlaceholder' => Craft::t('scribe', 'Start from…'),
                'endPlaceholder' => Craft::t('scribe', 'End before…'),
            ])
        ));

        return $view->renderTemplate('scribe/_field/input.twig', [
            'id' => $id,
            'name' => $this->handle,
            'value' => $value,
            'hasToken' => $service->hasToken(),
            'showPreview' => $this->showPreview,
            'previewHtml' => $previewHtml,
            'repos' => $service->repos(),
            'headings' => $headings,
        ]);
    }

    public function getElementValidationRules(): array
    {
        return [];
    }
}
