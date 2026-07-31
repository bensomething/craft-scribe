<?php

namespace bensomething\scribe\controllers;

use bensomething\scribe\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Returns the token account's repositories as JSON, for the field's readme
 * menu. Kept off the field's own render so a cold cache doesn't hold the page
 * up building a list the editor may never open.
 */
class ReposController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        return $this->asJson([
            'repos' => Plugin::getInstance()->getReadme()->repos(),
        ]);
    }
}
