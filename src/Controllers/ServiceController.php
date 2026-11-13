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
 * The public service pages (§17).
 *
 * Nothing here needs a session. These are the only pages in the product written
 * for somebody who has not signed in, and the content they show is the approved
 * copy — not a summary of it, and not a template's idea of it.
 *
 * The route parameter is the part of the content key after `service.`, so
 * `/services/fnac` resolves `service.fnac`. Keeping the key in one place means a
 * page's URL and its content record cannot drift apart the way a hand-written
 * slug column would allow.
 *
 * Every refusal is a 404. A draft, a withdrawn version, an expired one and a key
 * that never existed all look the same from outside, because the difference
 * between them is governance rather than something a visitor has any use for.
 */
final class ServiceController
{
    private const PREFIX = 'service.';

    private const LANGUAGE = 'en';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly ContentRepository $content,
        private readonly ?string $today = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $pages = $this->content->servable($this->today());

        return Response::html($this->view->render('services/index', [
            'title' => 'Services',
            'pages' => $pages,
            'currentUser' => $this->auth->user(),
        ]));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $slug = $params['slug'] ?? '';
        $page = $this->servable(self::PREFIX . $slug);

        if ($page === null) {
            throw new HttpException(404);
        }

        return Response::html($this->view->render('services/show', [
            'title' => $page->title,
            'page' => $page,
            'currentUser' => $this->auth->user(),
        ]));
    }

    /**
     * The page, if it may be shown today.
     *
     * `published()` answers "which version is live"; this asks the row whether the
     * calendar allows it. Both have to be true, and they are asked in that order
     * because a version that is not live has no dates worth consulting.
     */
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
