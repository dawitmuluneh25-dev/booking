<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotificationFeedRepository;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\View;

/**
 * The in-app notification list.
 *
 * It answers one question — "has anything happened that I should look at?" — and
 * deliberately answers no others. Each line is a reference and a time. Following a
 * reference takes the reader to the referral or the booking, where the existing
 * object-level rules decide what they may see next. Building the content into this
 * page would have created a shortcut around those rules.
 */
final class NotificationController
{
    private const PATH = '/notifications';

    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly NotificationFeedRepository $feed,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::PATH));
        }

        $this->gate->authorize(Permission::NotificationRead);

        return Response::html($this->view->render('notifications/index', [
            'title' => 'Notifications',
            'notifications' => $this->feed->recent(self::PAGE_SIZE),
            'currentUser' => $this->gate->authenticate(),
        ]));
    }
}
