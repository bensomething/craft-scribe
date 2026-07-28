<?php

namespace bensomething\scribe\controllers;

use bensomething\scribe\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Returns a source's headings as JSON, for the field's Start From / End Before
 * menus.
 */
class HeadingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $url = (string)$this->request->getParam('url', '');

        return $this->asJson([
            'headings' => Plugin::getInstance()->getReadme()->headings($url),
        ]);
    }
}
