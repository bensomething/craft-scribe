<?php

namespace bensomething\scribe\controllers;

use bensomething\scribe\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Drops what's held for one source and fetches it again, for the field's
 * refresh button — so an editor who has just changed a readme on GitHub can see
 * it here without waiting out the cache or clearing every readme in the site.
 *
 * Answers with what the field would have rendered on a fresh page: the headings
 * as they now stand, and which of the saved range's own the readme has lost.
 */
class RefreshController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        // It clears something, so it isn't a GET.
        $this->requirePostRequest();

        $url = (string)$this->request->getParam('url', '');
        $service = Plugin::getInstance()->getReadme();
        $service->forget($url);

        return $this->asJson([
            'headings' => $service->headings($url),
            // So the field can tell a readme with no headings apart from one it
            // couldn't reach, which come back as the same empty list.
            'exists' => $service->exists($url),
            // The range is sent along so this can be answered for it: the readme
            // may have lost the headings it points at since the page was drawn,
            // which is exactly what a refresh is apt to turn up.
            'missing' => $service->missingHeadings(
                $url,
                (string)$this->request->getParam('startFrom', ''),
                (string)$this->request->getParam('endBefore', ''),
            ),
        ]);
    }
}
