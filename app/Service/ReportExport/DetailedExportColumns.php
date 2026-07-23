<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

/**
 * Single source of truth for which columns the detailed export renders and in
 * what order. Today the set is toggled by a single `includeTags` flag; the
 * descriptor list is the seam a future field-selection feature plugs into.
 */
final class DetailedExportColumns
{
    /**
     * @return list<DetailedExportColumn>
     */
    public static function for(bool $includeTags): array
    {
        $columns = [
            DetailedExportColumn::Description,
            DetailedExportColumn::Task,
            DetailedExportColumn::Project,
            DetailedExportColumn::Client,
            DetailedExportColumn::User,
            DetailedExportColumn::Start,
            DetailedExportColumn::End,
            DetailedExportColumn::Duration,
            DetailedExportColumn::DurationDecimal,
            DetailedExportColumn::Billable,
        ];

        if ($includeTags) {
            $columns[] = DetailedExportColumn::Tags;
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    public static function labels(bool $includeTags): array
    {
        return array_map(
            static fn (DetailedExportColumn $column): string => $column->value,
            self::for($includeTags),
        );
    }
}
