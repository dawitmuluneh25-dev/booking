<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testItAcceptsValidInput(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'Ada Lovelace', 'service' => 'consultation'],
            ['name' => 'required|min:2|max:120', 'service' => 'required|in:consultation,other'],
        );

        self::assertSame([], $errors);
    }

    public function testItReportsMissingRequiredField(): void
    {
        $errors = $this->validator->validate([], ['name' => 'required']);

        self::assertArrayHasKey('name', $errors);
        self::assertSame('Name is required.', $errors['name']);
    }

    public function testItReportsFirstFailurePerField(): void
    {
        $errors = $this->validator->validate(
            ['name' => str_repeat('a', 130)],
            ['name' => 'required|max:120|min:2'],
        );

        self::assertStringContainsString('at most 120', $errors['name']);
    }

    public function testItRejectsUnknownEnumeratedValue(): void
    {
        $errors = $this->validator->validate(['service' => 'surgery'], ['service' => 'in:consultation,other']);

        self::assertArrayHasKey('service', $errors);
    }

    public function testItValidatesLocalDateTimeFormat(): void
    {
        self::assertSame([], $this->validator->validate(
            ['at' => '2030-05-01T09:30'],
            ['at' => 'datetime'],
        ));

        self::assertArrayHasKey('at', $this->validator->validate(
            ['at' => '01/05/2030 09:30'],
            ['at' => 'datetime'],
        ));
    }

    public function testItValidatesPhoneNumbers(): void
    {
        self::assertSame([], $this->validator->validate(['phone' => '+251 906 373154'], ['phone' => 'phone']));
        self::assertArrayHasKey('phone', $this->validator->validate(['phone' => 'abc'], ['phone' => 'phone']));
    }
}
