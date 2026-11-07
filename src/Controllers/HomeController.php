<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\BookingService;
use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('home', [
            'title' => 'Book an appointment',
            'services' => BookingService::SERVICES,
        ]));
    }
}
