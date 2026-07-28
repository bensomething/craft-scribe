<?php

namespace bensomething\scribe\controllers;

use bensomething\scribe\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Returns the rendered README section as HTML, for the field's preview pane.
 */
class PreviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $html = Plugin::getInstance()->getReadme()->render(
            (string)$this->request->getParam('url', ''),
            $this->request->getParam('startFrom') ?: null,
            $this->request->getParam('endBefore') ?: null,
        );

        return $this->asJson(['html' => $html]);
    }
}
