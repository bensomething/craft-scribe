<?php

namespace bensomething\scribe;

use bensomething\scribe\fields\Readme as ReadmeField;
use bensomething\scribe\models\Settings;
use bensomething\scribe\services\Readme as ReadmeService;
use Craft;
use craft\base\Model;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\utilities\ClearCaches;
use yii\base\Event;
use yii\caching\TagDependency;

/**
 * Scribe — a field that pulls a GitHub README into your content, sliced by heading.
 *
 * @property-read ReadmeService $readme
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'readme' => ReadmeService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register the field type.
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = ReadmeField::class;
            }
        );

        // A dedicated "GitHub READMEs" option in Utilities → Caches (and the
        // clear-caches console command) so fetched content can be flushed on
        // its own instead of nuking the whole data cache.
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            static function(RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => 'scribe-readmes',
                    'label' => Craft::t('scribe', 'GitHub READMEs (Scribe)'),
                    'action' => static function() {
                        TagDependency::invalidate(Craft::$app->getCache(), ReadmeService::CACHE_TAG);
                    },
                ];
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('scribe/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Convenience accessor: Plugin::getInstance()->readme.
     */
    public function getReadme(): ReadmeService
    {
        return $this->get('readme');
    }
}
