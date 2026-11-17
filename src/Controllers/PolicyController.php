<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ContentRepository;
use App\Domain\PublishedContent;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Support\View;

/**
 * The public policy pages of HR-WEB-CONTENT-R1-001: privacy notice, booking policy
 * and referral guidance.
 *
 * These are prose rather than service descriptions, so they render through their
 * own template. The service page ends with a sentence about consent to a procedure,
 * which is the wrong way to close a privacy notice.
 *
 * The 404 discipline is the same as the service pages, for the same reason: a draft,
 * a withdrawn version and a key that never existed are identical from outside,
 * because the difference between them is governance and not something a visitor can
 * act on.
 */
final class PolicyController
{
    private const LANGUAGE = 'en';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly ContentRepository $content,
        private readonly ?string $today = null,
    ) {
    }

    public function show(Request $request, string $contentKey): Response
    {
        $page = $this->servable($contentKey);

        if ($page === null) {
            throw new HttpException(404);
        }

        return Response::html($this->view->render('pages/show', [
            'title' => $page->title,
            'page' => $page,
            'currentUser' => $this->auth->user(),
        ]));
    }

    /** The page, if it may be shown today; `published()` decides what is live. */
    private function servable(string $contentKey): ?PublishedContent
    {
        $page = $this->content->published($contentKey, self::LANGUAGE);

        if ($page === null || !$page->isServableOn($this->today())) {
            return null;
        }

        return $page;
    }

    private function today(): string
    {
        return $this->today ?? gmdate('Y-m-d');
    }
}
