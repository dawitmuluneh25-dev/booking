<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\SqlScript;
use PHPUnit\Framework\TestCase;

final class SqlScriptTest extends TestCase
{
    public function testSplitsStatementsOnSemicolons(): void
    {
        $statements = SqlScript::split("SELECT 1;\nSELECT 2;\n");

        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testKeepsSemicolonsInsideQuotedValues(): void
    {
        $sql = "INSERT INTO bookings (notes) VALUES ('call back; after lunch');";

        self::assertSame(
            ["INSERT INTO bookings (notes) VALUES ('call back; after lunch')"],
            SqlScript::split($sql),
        );
    }

    public function testHandlesEscapedQuotesInsideValues(): void
    {
        $sql = "INSERT INTO bookings (patient_name) VALUES ('O\\'Brien; Jr');";

        self::assertSame(
            ["INSERT INTO bookings (patient_name) VALUES ('O\\'Brien; Jr')"],
            SqlScript::split($sql),
        );
    }

    public function testIgnoresLineAndBlockComments(): void
    {
        $sql = "-- header; not a statement\n/* also; not one */\nSELECT 1;\n";

        self::assertSame(['SELECT 1'], SqlScript::split($sql));
    }

    public function testKeepsBacktickedIdentifiersIntact(): void
    {
        $sql = 'CREATE TABLE `weird;name` (`id` INT);';

        self::assertSame(['CREATE TABLE `weird;name` (`id` INT)'], SqlScript::split($sql));
    }

    public function testReturnsNothingForCommentsOnly(): void
    {
        self::assertSame([], SqlScript::split("-- nothing here\n"));
    }
}
