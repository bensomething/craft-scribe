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
        $service = Plugin::getInstance()->getReadme();

        return $this->asJson([
            'headings' => $service->headings($url),
            // So the field can tell a readme with no headings apart from one it
            // couldn't reach, which come back as the same empty list.
            'exists' => $service->exists($url),
        ]);
    }
}
