<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Permission;
use App\Domain\UserRepository;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\View;
use Throwable;

final class AdminUserController
{
    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/users'));
        }

        $this->gate->authorize(Permission::UsersManage);

        try {
            $users = $this->users->all();
        } catch (Throwable) {
            return Response::html($this->view->render('admin/users', [
                'title' => 'Users',
                'users' => [],
                'unavailable' => true,
                'currentUser' => $this->auth->user(),
            ]), 503);
        }

        return Response::html($this->view->render('admin/users', [
            'title' => 'Users',
            'users' => $users,
            'unavailable' => false,
            'currentUser' => $this->auth->user(),
        ]));
    }
}
