<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Put a version of a page live (§17.5).
 *
 * The command exists to turn an incomplete record into a sentence rather than a
 * database error. The CHECK constraint in migration 0035 is the real rule and
 * refuses the same cases; what this adds is naming *which* of §17.5's inputs is
 * missing, because "the record is incomplete" tells the person holding the form
 * nothing they can act on.
 *
 * It refuses a page that is already published rather than treating a second press
 * as harmless. Publishing is a dated, attributed act; running it twice would
 * overwrite the name and time of the first one, which is the only record of who
 * put the words in front of patients.
 */
final class PublishContent
{
    public function __construct(private readonly ContentRepository $content)
    {
    }

    /**
     * @throws AuthorisationException the actor may not publish content
     * @throws ContentNotFoundException no such record
     * @throws ValidationException     §17.5 is not satisfied, or the version is live
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

        if ($content->status === ContentStatus::Published) {
            throw new ValidationException([
                'status' => 'This version is already published. Withdraw it first if it should '
                    . 'stop being shown.',
            ]);
        }

        // A withdrawn version is not reinstated by publishing it again. Re-publishing
        // would overwrite the withdrawal — the person and the date — which is the only
        // record that the page was taken down. Wanting the words back is a new version
        // that names this one as superseded.
        if ($content->status !== ContentStatus::Draft) {
            throw new ValidationException([
                'status' => 'Only a draft can be published. Version ' . $content->version
                    . ' was withdrawn; import a new version that names it as superseded if the '
                    . 'copy is wanted again.',
            ]);
        }

        $missing = $content->missingForPublication();

        if ($missing !== []) {
            throw new ValidationException([
                'status' => '§17.5 is not satisfied: this record has no ' . self::readable($missing)
                    . '. A page is not published on the strength of an approved name alone — it '
                    . 'needs the reviewer, the approver and the dates that say how long it stands.',
            ]);
        }

        if (!$content->hasBody()) {
            throw new ValidationException([
                'status' => 'This record has no sections to show. Import the page copy before '
                    . 'publishing it.',
            ]);
        }

        $live = $this->content->published($content->contentKey, $content->language);

        if ($live !== null) {
            throw new ValidationException([
                'status' => 'Version ' . $live->version . ' of this page is already live. '
                    . 'Withdraw it before publishing ' . $content->version . '.',
            ]);
        }

        $this->content->publish($contentId, $actor->userId, $nowUtc ?? gmdate('Y-m-d H:i:s'));

        $published = $this->content->find($contentId);

        if ($published === null) {
            throw new ContentNotFoundException($contentId);
        }

        return $published;
    }

    /**
     * "a, b and c" — because a list of six missing inputs read as "a, b, c, d, e, f"
     * invites the eye to skip the last two, and the last two are dates.
     *
     * @param list<string> $items
     */
    private static function readable(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
