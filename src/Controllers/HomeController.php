<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\BookingService;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Support\View;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('home', [
            'title' => 'Refer a patient',
            'description' => 'Refer a patient to the interventional radiology service. '
                . 'Referrals are sent by registered clinicians.',
            'services' => BookingService::SERVICES,
            'currentUser' => $this->auth->user(),
        ]));
    }
}
