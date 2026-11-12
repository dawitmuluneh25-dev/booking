<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Role;
use App\Domain\User;
use App\Support\View;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * The narrow header.
 *
 * At 320px the link bar totals 345px for an administrator inside a 280px box, so
 * the links and the account actions sit behind one disclosure button rather than
 * merely wrapping. Two properties matter here and neither is visible in a
 * screenshot: the button exists only when there is something to open, and the
 * stylesheet never hides the panel without also showing the control that reopens
 * it — otherwise a phone gets a header with no route into the app at all.
 */
final class HeaderDisclosureTest extends TestCase
{
    private const BREAKPOINT = '@media (max-width: 40rem)';

    public function testTheSignedInHeaderPutsItsLinksAndAccountBehindOneControl(): void
    {
        $xpath = $this->render(new User(7, 'admin@example.test', 'Administrator', Role::Admin, true));

        $button = $this->one($xpath, '//button[contains(concat(" ", normalize-space(@class), " "), " nav-toggle ")]');

        self::assertSame('false', $button->getAttribute('aria-expanded'));
        self::assertSame('site-nav-panel', $button->getAttribute('aria-controls'));
    }

    public function testEveryNavigationLinkAndTheAccountActionsLiveInsideThePanel(): void
    {
        $xpath = $this->render(new User(7, 'admin@example.test', 'Administrator', Role::Admin, true));

        $panel = $this->one($xpath, '//*[@id="site-nav-panel"]');

        $allLinks = $xpath->query('//nav[@aria-label="Main"]//a');
        self::assertNotFalse($allLinks);
        self::assertGreaterThan(1, $allLinks->length, 'The served-in header has no links to collapse.');

        $insidePanel = $xpath->query('.//nav[@aria-label="Main"]//a', $panel);
        self::assertNotFalse($insidePanel);
        self::assertSame(
            $allLinks->length,
            $insidePanel->length,
            'A navigation link is outside the collapsible panel.',
        );

        $account = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " header-account ")]',
            $panel,
        );
        self::assertNotFalse($account);
        self::assertSame(1, $account->length, 'The account actions would stay on the header row.');
    }

    /**
     * A visitor with no session has no links, so a button opening nothing would
     * be a control that does the opposite of what it says.
     */
    public function testAnAnonymousHeaderHasNoDisclosureControlAndKeepsItsSignInLink(): void
    {
        $xpath = $this->render(null);

        $buttons = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " nav-toggle ")]');
        self::assertNotFalse($buttons);
        self::assertSame(0, $buttons->length);

        $panel = $this->one($xpath, '//*[@id="site-nav-panel"]');

        // Nothing to fold away: the panel stays inline, so the public header is
        // one row on a phone rather than two.
        self::assertStringContainsString('header-nav-panel--inline', $panel->getAttribute('class'));

        // The link is inside the panel, so it is only reachable if the panel is
        // visible; an anonymous page must therefore never get the collapsing class.
        $signIn = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " nav-signin ")]', $panel);
        self::assertNotFalse($signIn);
        self::assertSame(1, $signIn->length);
    }

    public function testTheStylesheetOnlyHidesThePanelWhereItAlsoShowsTheButton(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');

        $breakpoint = strripos($css, self::BREAKPOINT);
        self::assertNotFalse($breakpoint, 'The narrow breakpoint has disappeared from the stylesheet.');

        $narrow = substr($css, $breakpoint);

        foreach (['html.nav-collapsible .header-nav-panel', 'html.nav-collapsible .nav-toggle'] as $selector) {
            self::assertStringContainsString($selector, $narrow);
        }

        self::assertStringContainsString('.header-nav-panel.is-open', $narrow);
    }

    public function testTheScriptOwnsTheOpenStateAndCanReleaseFocus(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

        // The class that gates the collapse is added by the script, never in the
        // markup: a page whose script fails must keep a usable link bar.
        self::assertStringContainsString("classList.add('nav-collapsible')", $script);
        self::assertStringContainsString('aria-expanded', $script);
        self::assertStringContainsString("event.key === 'Escape'", $script);
    }

    public function testTheCollapsingClassIsNotInTheRenderedMarkup(): void
    {
        $html = $this->html(new User(7, 'admin@example.test', 'Administrator', Role::Admin, true));

        self::assertStringNotContainsString('nav-collapsible', $html);
    }

    private function render(?User $currentUser): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // HTML5 sectioning elements are unknown to libxml; its complaints are about
        // the parser, not about the markup under test.
        $document->loadHTML(
            '<?xml encoding="utf-8"?>' . $this->html($currentUser),
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function html(?User $currentUser): string
    {
        /** @var array<string, mixed> $data */
        $data = ['title' => 'Home', 'services' => []];

        if ($currentUser !== null) {
            $data['currentUser'] = $currentUser;
        }

        return (new View(dirname(__DIR__, 2) . '/templates'))->render('home', $data);
    }

    private function one(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, sprintf('Expected exactly one node for %s.', $query));

        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }
}
