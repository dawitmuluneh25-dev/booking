<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AuthorisationException;
use App\Domain\DocumentAction;
use App\Domain\DocumentKind;
use App\Domain\DocumentNotFoundException;
use App\Domain\DocumentQuarantinedException;
use App\Domain\DocumentReviewException;
use App\Domain\DocumentScanStatus;
use App\Domain\DocumentWorkspace;
use App\Domain\Permission;
use App\Domain\Referral;
use App\Domain\UploadedFile;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\Logger;
use App\Support\View;
use Closure;
use Throwable;

/**
 * The document surface: list, upload, stream, review, and status link.
 *
 * Thin by design. Every decision that matters - may this actor see this referral,
 * is this file the type it claims, may these bytes leave - is made in the domain
 * and surfaced here as a status code. Nothing in this class reads a role, a path
 * or a filename from the request.
 */
final class DocumentController
{
    /**
     * @param Closure(): DocumentWorkspace $workspace
     * @param (Closure(): string)|null   $now
     */
    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly Logger $logger,
        private readonly Closure $workspace,
        private readonly string $timezone,
        private readonly string $baseUrl,
        private readonly int $maxBytes,
        private readonly ?Closure $now = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();
        $workspace = $this->workspace();
        $referral = $this->referral($workspace, $params);

        if (!$workspace->access->canView($user, $referral)) {
            throw HttpException::notFound();
        }

        return Response::html($this->view->render('documents/index', [
            'title' => 'Documents · ' . $referral->reference,
            'referral' => $referral,
            'documents' => $workspace->documents->listForReferral($referral->id),
            'kinds' => DocumentKind::cases(),
            'accepted' => $workspace->types->extensions(),
            'maxMegabytes' => max(1, intdiv($this->maxBytes, 1024 * 1024)),
            'canUpload' => $this->gate->allows(Permission::DocumentUpload) && !$referral->status->isTerminal(),
            'canReview' => $this->gate->allows(Permission::DocumentQuarantineManage),
            'canIssueLink' => $this->gate->allows(Permission::ReferralTriage),
            'statusLink' => Session::pullFlash('status_link'),
            'notice' => Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'fieldErrors' => $this->pullFieldErrors(),
            'currentUser' => $user,
        ]));
    }

    /** @param array<string, string> $params */
    public function store(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();
        $this->assertCsrf($request);

        $workspace = $this->workspace();
        $referral = $this->referral($workspace, $params);

        $kind = DocumentKind::tryFrom((string) $request->input('kind', '')) ?? DocumentKind::Other;

        try {
            $document = $workspace->upload->execute(
                $user,
                $referral,
                $kind,
                UploadedFile::fromArray($request->file('document')),
                gmdate('Y-m-d H:i:s'),
                $request->ip(),
                $request->header('User-Agent'),
            );
        } catch (ValidationException $exception) {
            $this->rememberFieldErrors($exception->errors);

            return Response::redirect($this->referralPath($referral));
        } catch (AuthorisationException) {
            throw HttpException::notFound();
        }

        // The audit line names the document by its opaque reference, never by its
        // filename or the patient it belongs to (Phase 4 criterion 3).
        $this->logger->info('document.uploaded', [
            'document_ref' => $document->documentRef,
            'outcome' => 'accepted',
            'scan_status' => $document->scanStatus->value,
        ]);

        Session::flash('notice', 'Document stored. It becomes available once it has been reviewed.');

        return Response::redirect($this->referralPath($referral));
    }

    /** @param array<string, string> $params */
    public function download(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();
        $workspace = $this->workspace();

        try {
            $document = $workspace->documentAccess->open(
                $user,
                $params['ref'] ?? '',
                DocumentAction::Download,
                gmdate('Y-m-d H:i:s'),
                $request->ip(),
                $request->header('User-Agent'),
            );
        } catch (DocumentNotFoundException) {
            throw HttpException::notFound();
        } catch (DocumentQuarantinedException $exception) {
            throw new HttpException(409, $exception->getMessage());
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not download documents.');
        }

        try {
            $bytes = $workspace->storage->read($document->storageKey);
        } catch (Throwable) {
            $this->logger->error('document.unreadable', ['document_ref' => $document->documentRef]);

            throw new HttpException(503, 'That document is temporarily unavailable.');
        }

        $this->logger->info('document.download', [
            'document_ref' => $document->documentRef,
            'outcome' => 'allowed',
        ]);

        return Response::download($bytes, $document->mimeType, $document->originalName);
    }

    /** @param array<string, string> $params */
    public function review(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();
        $this->assertCsrf($request);

        $workspace = $this->workspace();
        $verdict = DocumentScanStatus::tryFrom((string) $request->input('verdict', ''));
        $document = $workspace->documents->findByRef($params['ref'] ?? '');

        if ($document === null) {
            throw HttpException::notFound();
        }

        $back = $this->referralPathById($document->referralId);

        try {
            if ($verdict === null) {
                throw new ValidationException(['verdict' => 'Choose a review outcome.']);
            }

            $workspace->review->execute(
                $user,
                $document->documentRef,
                $verdict,
                (string) $request->input('note', ''),
                gmdate('Y-m-d H:i:s'),
                $request->ip(),
                $request->header('User-Agent'),
            );
        } catch (DocumentNotFoundException) {
            throw HttpException::notFound();
        } catch (DocumentReviewException | ValidationException $exception) {
            Session::flash('error', $exception instanceof DocumentReviewException
                ? $exception->getMessage()
                : implode(' ', $exception->errors));

            return Response::redirect($back);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not review documents.');
        }

        Session::flash('notice', 'Review recorded.');

        return Response::redirect($back);
    }

    /** @param array<string, string> $params */
    public function issueLink(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();
        $this->assertCsrf($request);

        $workspace = $this->workspace();
        $referral = $this->referral($workspace, $params);
        $back = $this->referralPath($referral);

        try {
            $token = $workspace->tracking->issue($user, $referral, gmdate('Y-m-d H:i:s'));
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors));

            return Response::redirect($back);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not create a status link.');
        }

        // Shown once: only the hash is stored, so this is the only moment the
        // clinic can copy the link.
        Session::flash('status_link', $this->baseUrl . '/r/' . $token);

        return Response::redirect($back);
    }

    private function now(): string
    {
        return $this->now === null ? gmdate('Y-m-d H:i:s') : ($this->now)();
    }

    /** @param array<string, string> $params */
    public function showStatus(Request $request, array $params): Response
    {
        // The clock is a dependency, not an ambient fact: a token's lifetime is
        // measured against it, and a page that reads the wall clock directly
        // cannot be tested without waiting for the token to expire.
        $referral = $this->workspace()->tracking->redeem(
            $params['token'] ?? '',
            $this->now(),
        );

        if ($referral === null) {
            throw HttpException::notFound();
        }

        return Response::html($this->view->render('documents/status', [
            'title' => 'Request status',
            'referral' => $referral,
            'timezone' => $this->timezone,
        ]))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** @param array<string, string> $params */
    private function referral(DocumentWorkspace $workspace, array $params): Referral
    {
        $referral = $workspace->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw HttpException::notFound();
        }

        return $referral;
    }

    private function referralPath(Referral $referral): string
    {
        return '/referrals/' . rawurlencode($referral->reference) . '/documents';
    }

    private function referralPathById(int $referralId): string
    {
        $referral = $this->workspace()->referrals->findById($referralId);

        return $referral === null ? '/bookings' : $this->referralPath($referral);
    }

    private function assertCsrf(Request $request): void
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }
    }

    /** @param array<string, string> $errors */
    private function rememberFieldErrors(array $errors): void
    {
        Session::flash('document_errors', (string) json_encode($errors, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    private function pullFieldErrors(): array
    {
        $raw = Session::pullFlash('document_errors');

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $errors = [];
        foreach ($decoded as $field => $message) {
            if (is_string($field) && is_string($message)) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    private function workspace(): DocumentWorkspace
    {
        return ($this->workspace)();
    }
}
