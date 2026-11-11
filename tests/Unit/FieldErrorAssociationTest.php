<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Procedure;
use App\Domain\Role;
use App\Domain\User;
use App\Support\View;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * A validation message has to belong to a field, not merely sit under it.
 *
 * Visually the old markup was fine: the sentence appeared beneath the input. To a
 * screen reader it was a loose paragraph, announced after the field or not at all,
 * and unusable as a correction. Association is `aria-describedby`, and it is the
 * only thing that survives someone tabbing rather than looking.
 *
 * The template is rendered with errors injected, so this tests the markup that a
 * person actually receives rather than the source that produces it.
 */
final class FieldErrorAssociationTest extends TestCase
{
    private const ERRORS = [
        'patient_name' => 'Enter the patient name.',
        'patient_phone' => 'Enter a phone number we can reach.',
        'procedure_code' => 'Choose a procedure.',
    ];

    public function testEveryErrorMessagesIsReachableFromItsField(): void
    {
        $xpath = $this->rendered();

        $errors = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' field-error ')]");

        self::assertNotFalse($errors);
        self::assertSame(count(self::ERRORS), $errors->length, 'The form did not render every error it was given.');

        foreach ($errors as $error) {
            self::assertInstanceOf(DOMElement::class, $error);

            $id = $error->getAttribute('id');

            self::assertNotSame('', $id, 'An error message has no id, so nothing can point at it.');

            $referencing = $xpath->query('//*[@aria-describedby]');

            self::assertNotFalse($referencing);

            $found = false;

            foreach ($referencing as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                if (in_array($id, preg_split('/\s+/', $element->getAttribute('aria-describedby')) ?: [], true)) {
                    $found = true;
                    break;
                }
            }

            self::assertTrue($found, sprintf('No field is described by "%s".', $id));
        }
    }

    public function testEveryDescribedByReferenceExists(): void
    {
        $xpath = $this->rendered();

        $referencing = $xpath->query('//*[@aria-describedby]');

        self::assertNotFalse($referencing);
        self::assertGreaterThan(0, $referencing->length, 'No field carries aria-describedby at all.');

        foreach ($referencing as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            foreach (preg_split('/\s+/', $element->getAttribute('aria-describedby')) ?: [] as $id) {
                if ($id === '') {
                    continue;
                }

                $target = $xpath->query(sprintf('//*[@id="%s"]', $id));

                self::assertNotFalse($target);
                self::assertSame(
                    1,
                    $target->length,
                    sprintf('aria-describedby points at "%s", which does not exist.', $id),
                );
            }
        }
    }

    public function testAnErroredFieldIsMarkedInvalid(): void
    {
        $xpath = $this->rendered();

        foreach (array_keys(self::ERRORS) as $field) {
            $input = $xpath->query(sprintf('//*[@id="%s"]', $field));

            self::assertNotFalse($input);
            self::assertSame(1, $input->length, sprintf('Field "%s" is missing.', $field));

            $element = $input->item(0);

            self::assertInstanceOf(DOMElement::class, $element);
            self::assertSame(
                'true',
                $element->getAttribute('aria-invalid'),
                sprintf('Field "%s" is not marked invalid.', $field),
            );
        }
    }

    private function rendered(): DOMXPath
    {
        $view = new View(dirname(__DIR__, 2) . '/templates');

        $html = $view->render('referrals/new', [
            'title' => 'New referral',
            'procedures' => [new Procedure('image_guided_fnac', 'FNAC', 'elective', 30, null, 0, null, '', true)],
            'errors' => self::ERRORS,
            'values' => [
                'requested_urgency' => 'routine',
            ],
            'urgencies' => ['routine', 'urgent'],
            'idempotencyKey' => 'test-key',
            'currentUser' => new User(1, 'clerk@example.test', 'Clerk', Role::Staff, true),
        ]);

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // HTML5 sectioning elements are unknown to libxml; its complaints are about
        // the parser, not about the markup under test.
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
