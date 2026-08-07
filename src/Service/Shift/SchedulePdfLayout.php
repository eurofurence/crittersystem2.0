<?php

namespace App\Service\Shift;

use App\Entity\User;

/**
 * Chooses the page for a schedule export: which way round, how big, and where the columns have to
 * be split.
 *
 * A department with four people wants a portrait page and a department with forty does not, so the
 * page is picked from the schedule rather than fixed. Columns are narrowed first, down to a width
 * that is still legible, and only past that are the people split across pages - a page of unreadable
 * slivers is worse than two pages.
 *
 * PDF rendering cannot continue a table sideways: a table wider than the page is cut off rather
 * than carried over. Splitting the people into a table per page is what keeps the time column and
 * the names on every page, which is the whole point of the export.
 */
final class SchedulePdfLayout
{
    /** Wide enough for "Mon 18 Aug" above "08:00", which the time column carries on every page. */
    private const TIME_COLUMN_PT = 78;

    /** A name column at its most readable, and the narrowest one still worth printing. */
    private const ROOMY_COLUMN_PT = 62;
    private const MIN_COLUMN_PT = 40;

    /**
     * Printable width per page, in points, after dompdf's margins. Ordered from the page we would
     * rather use to the page we fall back to.
     *
     * @var list<array{paper: string, orientation: string, width: int}>
     */
    private const PAGES = [
        ['paper' => 'a4', 'orientation' => 'portrait', 'width' => 523],
        ['paper' => 'a4', 'orientation' => 'landscape', 'width' => 770],
        ['paper' => 'a3', 'orientation' => 'landscape', 'width' => 1120],
    ];

    /**
     * @param list<User> $users
     *
     * @return array{paper: string, orientation: string, columnPt: int, fontPt: int, chunks: list<list<User>>}
     */
    public function forUsers(array $users): array
    {
        $count = max(1, \count($users));

        foreach (self::PAGES as $page) {
            $available = $page['width'] - self::TIME_COLUMN_PT;
            if ($count * self::MIN_COLUMN_PT > $available) {
                continue;
            }

            $column = (int) min(self::ROOMY_COLUMN_PT, intdiv($available, $count));

            return $this->layout($page, $column, [$users]);
        }

        $widest = self::PAGES[\count(self::PAGES) - 1];
        $perPage = max(1, intdiv($widest['width'] - self::TIME_COLUMN_PT, self::MIN_COLUMN_PT));

        return $this->layout($widest, self::MIN_COLUMN_PT, array_chunk($users, $perPage));
    }

    /**
     * @param array{paper: string, orientation: string, width: int} $page
     * @param list<list<User>>                                      $chunks
     *
     * @return array{paper: string, orientation: string, columnPt: int, fontPt: int, chunks: list<list<User>>}
     */
    private function layout(array $page, int $column, array $chunks): array
    {
        return [
            'paper' => $page['paper'],
            'orientation' => $page['orientation'],
            'columnPt' => $column,
            'fontPt' => $column >= 52 ? 9 : 8,
            'chunks' => $chunks === [[]] ? [[]] : $chunks,
        ];
    }
}
