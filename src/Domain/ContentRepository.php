<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Patient-facing content, read by the public site and written by governance.
 *
 * There is no delete. A page that is no longer wanted is withdrawn, because the
 * question a withdrawn page answers is "what did we show, and until when?" — and
 * a deleted row answers nothing.
 */
interface ContentRepository
{
    /**
     * The version of a page that is live right now, if any.
     *
     * Resolves to a published row regardless of whether its review date has
     * passed; the caller asks the row whether it may be served. Separating the
     * two keeps "which version is live" a question about the record and "may I
     * show it today" a question about the calendar.
     */
    public function published(string $contentKey, string $language): ?PublishedContent;

    public function find(int $id): ?PublishedContent;

    public function findVersion(string $contentKey, string $version, string $language): ?PublishedContent;

    /** @return list<PublishedContent> Every version, newest first, for the admin screen. */
    public function all(): array;

    /**
     * Everything the public site may serve on the given day (YYYY-MM-DD).
     *
     * The date filtering happens here so that a page whose review date has passed
     * is never assembled by the controller in the first place.
     *
     * @return list<PublishedContent>
     */
    public function servable(string $today): array;

    /**
     * Import a version as a draft.
     *
     * Idempotent on (content key, version, language): re-running an import must
     * not produce a second copy of the same version, because two copies of one
     * version make the audit trail ambiguous about which was reviewed.
     */
    public function importDraft(PublishedContent $content, string $occurredAtUtc): int;

    /**
     * Bring an already-imported draft in line with the source it came from.
     *
     * Without this, supplying a reviewer or a date to an existing draft would do
     * nothing: the import is idempotent on (key, version, language), so a second
     * run finds the row and stops. Re-running the import has to be able to fill in
     * §17.5's fields as they arrive, or the only way to record them would be to
     * delete the row and start again — which would lose the fact that it was
     * imported once already.
     *
     * Returns false for a published or withdrawn row and changes nothing. An
     * import must never rewrite the words, or the approval, underneath a page
     * somebody has already signed off.
     */
    public function reconcileDraft(int $id, PublishedContent $content, string $occurredAtUtc): bool;

    /**
     * Make a version live, and record who did it and when.
     *
     * The CHECK in migration 0035 refuses this unless §17.5 is complete, so the
     * database is the last line of defence rather than the form.
     */
    public function publish(int $id, int $actorUserId, string $occurredAtUtc): void;

    public function withdraw(int $id, int $actorUserId, string $occurredAtUtc): void;
}
