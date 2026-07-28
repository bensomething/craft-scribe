<?php

namespace bensomething\scribe\web\assets\field;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\assets\selectize\SelectizeAsset;

class ScribeFieldAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CpAsset::class, SelectizeAsset::class];
    public $js = ['scribe.js'];
    public $css = ['scribe.css'];
}
