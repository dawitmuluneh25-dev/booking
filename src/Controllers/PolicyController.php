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

    /**
     * The three pages HR-WEB-CONTENT-R1-001 defines.
     *
     * D-12 (25 November 2026) asks for a holding page instead of a 404 while a policy is approved but
     * not yet in force. The list is written out rather than derived because the point of the exemption
     * is that it is narrow: a key that is not one of these three still 404s exactly as before, so the
     * 404 discipline is preserved for everything else.
     */
    private const POLICY_KEYS = ['policy.privacy', 'policy.booking', 'policy.referral'];

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly ContentRepository $content,
        private readonly ?string $today = null,
    ) {
    }

    public const SHOW = 'show';

    public const PENDING = 'pending';

    public const NOT_FOUND = 'not-found';

    /**
     * What a request for this key should do today.
     *
     * Extracted from `show()` so the rule can be tested without a session, a view or a database. It is
     * three lines of policy that three separate decisions argued over, and the arguments are the
     * reason it is not inlined: D-12 narrows the 404 discipline to everything except the three policy
     * keys, and D-01's effective date is what turns "approved" into "still held".
     *
     * @param list<string> $policyKeys
     */
    public static function disposition(
        ?PublishedContent $page,
        string $contentKey,
        string $today,
        array $policyKeys = self::POLICY_KEYS,
    ): string {
        if ($page !== null && $page->isServableOn($today)) {
            return self::SHOW;
        }

        return in_array($contentKey, $policyKeys, true) ? self::PENDING : self::NOT_FOUND;
    }

    public function show(Request $request, string $contentKey): Response
    {
        $page = $this->content->published($contentKey, self::LANGUAGE);
        $disposition = self::disposition($page, $contentKey, $this->today());

        if ($disposition === self::SHOW && $page !== null) {
            return Response::html($this->view->render('pages/show', [
                'title' => $page->title,
                'page' => $page,
                'currentUser' => $this->auth->user(),
            ]));
        }

        if ($disposition === self::NOT_FOUND) {
            throw new HttpException(404);
        }

        // D-12. A published-but-not-yet-effective policy is the normal state between approval and its
        // effective date, and a draft is the state before approval. Both used to 404, which turned the
        // footer's "Privacy" link into a dead end on a site that takes referral data. The holding page
        // carries no legal content, no approver and no document control block: it says the page is not
        // published, and names the effective date only when a record supplies one.
        return Response::html($this->view->render('pages/pending', [
            'title' => $page !== null ? $page->title : 'Page in preparation',
            'effectiveOn' => $page !== null ? $page->effectiveOn : null,
            'currentUser' => $this->auth->user(),
        ]));
    }

    private function today(): string
    {
        return $this->today ?? gmdate('Y-m-d');
    }
}
