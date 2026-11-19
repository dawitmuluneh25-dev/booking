<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The control inventory quotes numbers that end up in a privacy notice.
 *
 * `docs/operations/control-inventory.md` is the document the system administrator
 * writes Privacy §5 and §10 from, and every number it quotes — five years, five
 * attempts, fifteen minutes, fifteen megabytes — is a constant in this codebase.
 * Nothing held the two together, and this project has already paid for that once:
 * the retention period was written as 1825 in one place and 1827 in another.
 *
 * So each row of the inventory's fact table names the constant it came from, and
 * this test resolves that constant and compares it. The table is not allowed to be
 * a restatement of itself — the value on the left and the symbol on the right have
 * to be two independent routes to the same fact, and they have to agree.
 *
 * A row whose constant does not exist is a failure, not a skip: it means the code
 * that once justified the sentence has been renamed or removed, and the sentence is
 * now unsupported.
 */
final class ControlInventoryTest extends TestCase
{
    /** Fewer rows than this means the table moved or the parser stopped matching. */
    private const MINIMUM_ROWS = 9;

    public function testEveryQuotedNumberMatchesTheConstantItNames(): void
    {
        $rows = self::rows();
        $problems = [];

        foreach ($rows as $row) {
            $value = self::constantValue($row['constant'], $problems);

            if ($value === null) {
                continue;
            }

            if ((string) $value !== $row['value']) {
                $problems[] = $row['control'] . ': the inventory says ' . $row['value']
                    . ', ' . $row['constant'] . ' is ' . var_export($value, true);
            }
        }

        self::assertSame([], $problems, self::report($problems));
    }

    public function testEveryQuotedNumberNamesAConstantThatStillExists(): void
    {
        $problems = [];

        foreach (self::rows() as $row) {
            self::constantValue($row['constant'], $problems);
        }

        self::assertSame([], $problems, self::report($problems));
    }

    /**
     * Both tests above walk a list that could be empty, and an empty list passes.
     * This is the test that fails when the document moves, loses its heading, or
     * changes its column order.
     */
    public function testTheFactTableIsStillThereToBeChecked(): void
    {
        $rows = self::rows();

        self::assertGreaterThanOrEqual(
            self::MINIMUM_ROWS,
            count($rows),
            'The control inventory parsed to ' . count($rows) . ' rows. The table has moved, changed its '
            . 'columns, or lost its "## 11. Machine-checked facts" heading.',
        );
    }

    /**
     * Resolve `Fully\Qualified\Class::CONSTANT`, recording a problem instead of
     * throwing so one run reports every broken row.
     *
     * @param list<string> $problems
     */
    private static function constantValue(string $reference, array &$problems): int|string|float|bool|null
    {
        if (preg_match('/\A\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\z/', $reference, $m) !== 1) {
            $problems[] = 'not a class constant reference: "' . $reference . '"';

            return null;
        }

        $class = $m[1];
        $name = $m[2];

        if (!class_exists($class)) {
            $problems[] = 'no such class: ' . $class;

            return null;
        }

        if (!(new ReflectionClass($class))->hasConstant($name)) {
            $problems[] = $class . ' has no constant ' . $name;

            return null;
        }

        /** @var ReflectionClassConstant $constant */
        $constant = new ReflectionClassConstant($class, $name);
        $value = $constant->getValue();

        if (!is_scalar($value)) {
            $problems[] = $class . '::' . $name . ' is not a scalar, so it cannot appear in this table';

            return null;
        }

        return $value;
    }

    /** @param list<string> $problems */
    private static function report(array $problems): string
    {
        if ($problems === []) {
            return '';
        }

        return 'The control inventory and the code disagree in ' . count($problems) . " place(s):\n  - "
            . implode("\n  - ", $problems)
            . "\nChange the document or the constant — but decide which one was wrong first.";
    }

    /**
     * @return list<array{control: string, value: string, constant: string}>
     */
    private static function rows(): array
    {
        $file = dirname(__DIR__, 2) . '/docs/operations/control-inventory.md';
        self::assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'The control inventory could not be read.');

        $rows = [];
        $inTable = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '## 11.')) {
                $inTable = true;
                continue;
            }

            if (!$inTable) {
                continue;
            }

            if (str_starts_with($line, '#')) {
                break;
            }

            if (!str_starts_with($line, '|')) {
                continue;
            }

            // Header row and the `|---|` separator carry no data.
            if (preg_match('/^\|\s*Control\s*\|/', $line) === 1 || str_contains($line, '---')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            if (count($cells) !== 3) {
                self::fail('The control inventory has a row that is not three columns: ' . $line);
            }

            $rows[] = [
                'control' => $cells[0],
                'value' => trim($cells[1], '`'),
                'constant' => trim($cells[2], '`'),
            ];
        }

        return $rows;
    }
}
