<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

use App\Enums\ExportFormat;
use App\Models\TimeEntry;
use App\Service\LocalizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use LogicException;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<TimeEntry>
 */
class TimeEntriesDetailedExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    /**
     * @var Builder<TimeEntry>
     */
    private Builder $builder;

    private ExportFormat $exportFormat;

    private string $timezone;

    private LocalizationService $localizationService;

    private bool $includeTags;

    /**
     * @param  Builder<TimeEntry>  $builder
     */
    public function __construct(Builder $builder, ExportFormat $exportFormat, string $timezone, LocalizationService $localizationService, bool $includeTags)
    {
        $this->builder = $builder;
        $this->exportFormat = $exportFormat;
        $this->timezone = $timezone;
        $this->localizationService = $localizationService;
        $this->includeTags = $includeTags;
    }

    /**
     * @return Builder<TimeEntry>
     */
    public function query(): Builder
    {
        return $this->builder;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        if ($this->exportFormat !== ExportFormat::XLSX && $this->exportFormat !== ExportFormat::ODS) {
            throw new LogicException('Unsupported export format.');
        }

        $formats = [];
        foreach (DetailedExportColumns::for($this->includeTags) as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $format = match ($column) {
                DetailedExportColumn::Start,
                DetailedExportColumn::End => $this->exportFormat === ExportFormat::XLSX ? 'yyyy-mm-dd hh:mm:ss' : null,
                DetailedExportColumn::DurationDecimal => NumberFormat::FORMAT_NUMBER_00,
                default => null,
            };
            if ($format !== null) {
                $formats[$letter] = $format;
            }
        }

        return $formats;
    }

    /**
     * @return array<int|string, array<string, array<string, bool>>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            // Style the first row as bold text.
            1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return DetailedExportColumns::labels($this->includeTags);
    }

    /**
     * @param  TimeEntry  $model
     * @return array<int, string|float|null>
     */
    public function map($model): array
    {
        if ($this->exportFormat !== ExportFormat::XLSX && $this->exportFormat !== ExportFormat::ODS) {
            throw new LogicException('Unsupported export format.');
        }

        $duration = $model->getDuration();

        $row = [];
        foreach (DetailedExportColumns::for($this->includeTags) as $column) {
            $row[] = match ($column) {
                DetailedExportColumn::Description => $model->description,
                DetailedExportColumn::Task => $model->task?->name,
                DetailedExportColumn::Project => $model->project?->name,
                DetailedExportColumn::Client => $model->client?->name,
                DetailedExportColumn::User => $model->user->name,
                DetailedExportColumn::Start => $this->formatDateTime($model->start->timezone($this->timezone)),
                DetailedExportColumn::End => $model->end !== null ? $this->formatDateTime($model->end->timezone($this->timezone)) : null,
                DetailedExportColumn::Duration => $duration !== null ? $this->localizationService->formatInterval($duration) : null,
                DetailedExportColumn::DurationDecimal => $duration?->totalHours,
                DetailedExportColumn::Billable => $model->billable ? 'Yes' : 'No',
                DetailedExportColumn::Tags => $model->tagsRelation->pluck('name')->implode(', '),
            };
        }

        return $row;
    }

    private function formatDateTime(Carbon $dateTime): string|float
    {
        return $this->exportFormat === ExportFormat::XLSX
            ? Date::dateTimeToExcel($dateTime)
            : $dateTime->format('Y-m-d H:i:s');
    }
}
