<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Stop showing a page, and keep the record that it was shown (§17.5).
 *
 * §17.5 says to hide withdrawn content, which is a display instruction, not a
 * statement that the content never existed. So the row stays, keeps its approver
 * and its dates, and gains the time and the person who took it down. A patient
 * asking "you told me this in March, why" needs the March version to still exist.
 *
 * Withdrawing something that is not published is refused rather than ignored: the
 * only record of a withdrawal is the state change, and a withdrawal of a draft
 * would write a date and a person for an act that did not happen.
 */
final class WithdrawContent
{
    public function __construct(private readonly ContentRepository $content)
    {
    }

    /**
     * Withdraw whichever version of a page is live right now, addressed by its content key.
     *
     * The console needs this because an operator taking a page down at 3am knows the key — the
     * thing printed in the URL and in the decision record — not a numeric row id. Resolving the key
     * here rather than in the shell script keeps "which version is live" a question about the
     * record, asked of the repository that answers it for the public site.
     *
     * The permission check is repeated rather than left to `execute()`, because the lookup comes
     * first: without it an unauthorised caller could tell a live page from an absent one by reading
     * the error message, which is a small but real disclosure about unpublished work.
     *
     * @throws AuthorisationException the actor may not publish content
     * @throws ValidationException     no version of the page is live
     */
    public function executeByKey(
        Actor $actor,
        string $contentKey,
        string $language = ContentSource::LANGUAGE,
        ?string $nowUtc = null,
    ): PublishedContent {
        if (!$actor->can(Permission::ContentPublish)) {
            throw new AuthorisationException($actor->role, Permission::ContentPublish);
        }

        $live = $this->content->published($contentKey, $language);

        if ($live === null) {
            throw new ValidationException([
                'key' => 'Nothing is published at "' . $contentKey . '" (' . $language . '), so there is '
                    . 'nothing to withdraw. A draft is taken down by never publishing it.',
            ]);
        }

        return $this->execute($actor, $live->id, $nowUtc);
    }

    /**
     * @throws AuthorisationException the actor may not publish content
     * @throws ContentNotFoundException no such record
     * @throws ValidationException     the version is not live
     */
    public function execute(Actor $actor, int $contentId, ?string $nowUtc = null): PublishedContent
    {
        if (!$actor->can(Permission::ContentPublish)) {
            throw new AuthorisationException($actor->role, Permission::ContentPublish);
        }

        $content = $this->content->find($contentId);

        if ($content === null) {
            throw new ContentNotFoundException($contentId);
        }

        if ($content->status !== ContentStatus::Published) {
            throw new ValidationException([
                'status' => 'Only a published version can be withdrawn, and this one is '
                    . strtolower($content->status->label()) . '.',
            ]);
        }

        $this->content->withdraw($contentId, $actor->userId, $nowUtc ?? gmdate('Y-m-d H:i:s'));

        $withdrawn = $this->content->find($contentId);

        if ($withdrawn === null) {
            throw new ContentNotFoundException($contentId);
        }

        return $withdrawn;
    }
}
