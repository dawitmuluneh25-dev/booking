<?php

declare(strict_types=1);

namespace App\Controllers;

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
            // D-05's headline and positioning, used as the document title and the description a
            // search engine or a link preview shows. The previous description said "request a
            // booking online", which was the E2 defect: the page does not offer that.
            'title' => 'Specialist Image-Guided Interventional Radiology Care',
            'description' => 'HakimeRAD Radiologic Consultancy coordinates referrals, specialist review '
                . 'and procedure planning for minimally invasive image-guided interventions.',
            'currentUser' => $this->auth->user(),
        ]));
    }
}
