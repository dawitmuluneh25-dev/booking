<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\View;

final class AdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin'));
        }

        $this->gate->authorize(Permission::SystemMaintain);

        return Response::html($this->view->render('admin/index', [
            'title' => 'Administration',
            'currentUser' => $this->auth->user(),
        ]));
    }
}
