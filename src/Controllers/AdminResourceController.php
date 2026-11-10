<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Permission;
use App\Domain\Resource;
use App\Domain\ResourceAdminRepository;
use App\Domain\ResourceBlock;
use App\Domain\ResourceBlockRepository;
use App\Domain\ScheduleRepository;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Closures: taking a suite or a room out of service without inventing a patient.
 *
 * The window is entered in the clinic's clock and stored in UTC, because that is
 * what the schedule compares against and what the booking guard reads. A block
 * created here is immediately invisible to the slot picker and refused by the
 * booking transaction — there is no second list to keep in step.
 */
final class AdminResourceController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly ScheduleRepository $schedule,
        private readonly ResourceBlockRepository $blocks,
        private readonly ResourceAdminRepository $admin,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/resources'));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        return $this->render([], 200, null);
    }

    public function update(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/resources'));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $action = $request->input('action', 'add_block');

        if ($action === 'remove_block') {
            return $this->remove($request);
        }

        return $this->add($request);
    }

    public function store(Request $request): Response
    {
        $this->authorise($request, '/admin/resources');

        try {
            $resource = $this->admin->create(
                code: (string) $request->input('code', ''),
                name: (string) $request->input('name', ''),
                kind: (string) $request->input('kind', 'room'),
                modality: $request->input('modality'),
                active: $request->input('active', '1') === '1',
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null);
        }

        Session::flash('notice', $resource->name . ' was added. Set its opening hours below.');

        return Response::redirect('/admin/resources', 303);
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params): Response
    {
        $this->authorise($request, '/admin/resources');

        $id = (int) ($params['id'] ?? '0');

        try {
            $resource = $this->admin->update(
                id: $id,
                name: (string) $request->input('name', ''),
                kind: (string) $request->input('kind', 'room'),
                modality: $request->input('modality'),
                // Deactivation, never deletion: appointments and closures point at
                // this row, and a room that disappears takes its history with it.
                active: $request->input('active', '1') === '1',
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null);
        }

        Session::flash('notice', $resource->name . ' was updated.');

        return Response::redirect('/admin/resources', 303);
    }

    /** @param array<string, string> $params */
    public function saveWeek(Request $request, array $params): Response
    {
        $this->authorise($request, '/admin/resources');

        $id = (int) ($params['id'] ?? '0');
        $week = [];

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $opens = (string) $request->input('week_' . $weekday . '_opens', '');
            $closes = (string) $request->input('week_' . $weekday . '_closes', '');

            $week[$weekday] = trim($opens) === '' || trim($closes) === ''
                ? null
                : ['opens' => $opens, 'closes' => $closes];
        }

        try {
            $this->admin->replaceDefaultWeek($id, $week);
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null);
        }

        Session::flash('notice', 'Opening hours were saved. The slot picker uses them immediately.');

        return Response::redirect('/admin/resources', 303);
    }

    private function authorise(Request $request, string $next): void
    {
        if (!$this->auth->check()) {
            throw new HttpException(401);
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }
    }
    private function add(Request $request): Response
    {
        $resourceId = (int) $request->input('resource_id', '0');
        $reason = trim((string) $request->input('reason', ''));

        $resource = $this->resource($resourceId);

        $errors = [];
        $zone = new DateTimeZone($this->timezone);
        $startsAt = $this->parseLocal((string) $request->input('starts_at', ''), $zone);
        $endsAt = $this->parseLocal((string) $request->input('ends_at', ''), $zone);

        if ($resource === null) {
            $errors['resource_id'] = 'Choose a room or suite.';
        }

        if ($startsAt === null) {
            $errors['starts_at'] = 'Give the start as a date and time.';
        }

        if ($endsAt === null) {
            $errors['ends_at'] = 'Give the end as a date and time.';
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors['ends_at'] = 'The closure has to end after it starts.';
        }

        if ($reason === '') {
            $errors['reason'] = 'Say why it is closed; the next person will ask.';
        }

        if ($errors !== []) {
            return $this->render($errors, 422, null);
        }

        $user = $this->auth->user();

        if ($resource === null || $startsAt === null || $endsAt === null || $user === null) {
            return $this->render(['resource_id' => 'The closure was not recorded.'], 422, null);
        }

        $utc = new DateTimeZone('UTC');

        $this->blocks->create(new ResourceBlock(
            id: 0,
            resourceId: $resource->id,
            startsAtUtc: $startsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            endsAtUtc: $endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            reason: mb_substr($reason, 0, 255),
            createdByUserId: $user->id,
            createdAtUtc: gmdate('Y-m-d H:i:s'),
        ));

        Session::flash('notice', $resource->name . ' is closed for that window.');

        return Response::redirect('/admin/resources', 303);
    }

    private function remove(Request $request): Response
    {
        $id = (int) $request->input('block_id', '0');
        $block = $id > 0 ? $this->blocks->find($id) : null;

        if ($block === null) {
            throw new HttpException(404);
        }

        $this->blocks->delete($block->id);
        Session::flash('notice', 'The closure was removed.');

        return Response::redirect('/admin/resources', 303);
    }

    /** @param array<string, string> $errors */
    private function render(array $errors, int $status, ?string $notice): Response
    {
        $zone = new DateTimeZone($this->timezone);
        $now = gmdate('Y-m-d H:i:s');

        /**
         * @var list<array{
         *     resource: Resource,
         *     blocks: list<array{block: ResourceBlock, label: string}>,
         *     week: array<int, array{opens: string, closes: string}|null>
         * }> $rows
         */
        $rows = [];

        // The admin list, not the scheduling list: an inactive room has to stay
        // visible here or it can never be brought back.
        foreach ($this->admin->all() as $resource) {
            $blocks = [];

            foreach ($this->blocks->upcoming($resource->id, $now) as $block) {
                $blocks[] = ['block' => $block, 'label' => $block->describe($zone)];
            }

            $rows[] = [
                'resource' => $resource,
                'blocks' => $blocks,
                'week' => $this->admin->defaultWeek($resource->id),
            ];
        }

        return Response::html($this->view->render('admin/resources', [
            'title' => 'Rooms and closures',
            'rows' => $rows,
            'errors' => $errors,
            'notice' => $notice ?? Session::pullFlash('notice'),
            'timezone' => $this->timezone,
            'currentUser' => $this->auth->user(),
        ]), $status);
    }

    private function resource(int $id): ?Resource
    {
        foreach ($this->schedule->resources() as $resource) {
            if ($resource->id === $id) {
                return $resource;
            }
        }

        return null;
    }

    /** A `datetime-local` value is wall-clock in the clinic zone, not UTC. */
    private function parseLocal(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $zone);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
