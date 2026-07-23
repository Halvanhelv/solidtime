<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

use App\Models\TimeEntry;
use App\Service\IntervalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends CsvExport<TimeEntry>
 */
class TimeEntriesDetailedCsvExport extends CsvExport
{
    protected const string CARBON_FORMAT = 'Y-m-d H:i:s';

    private string $timezone;

    private bool $includeTags;

    public function __construct(string $disk, string $folderPath, string $filename, Builder $builder, int $chunk, string $timezone, bool $includeTags)
    {
        parent::__construct($disk, $folderPath, $filename, $builder, $chunk);

        $this->timezone = $timezone;
        $this->includeTags = $includeTags;
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        return DetailedExportColumns::labels($this->includeTags);
    }

    /**
     * @param  TimeEntry  $model
     */
    public function mapRow(Model $model): array
    {
        $interval = app(IntervalService::class);
        $duration = $model->getDuration();

        $row = [];
        foreach (DetailedExportColumns::for($this->includeTags) as $column) {
            $row[$column->value] = match ($column) {
                DetailedExportColumn::Description => $model->description,
                DetailedExportColumn::Task => $model->task?->name,
                DetailedExportColumn::Project => $model->project?->name,
                DetailedExportColumn::Client => $model->client?->name,
                DetailedExportColumn::User => $model->user->name,
                DetailedExportColumn::Start => $model->start->timezone($this->timezone),
                DetailedExportColumn::End => $model->end?->timezone($this->timezone),
                DetailedExportColumn::Duration => $duration !== null ? $interval->format($duration) : null,
                DetailedExportColumn::DurationDecimal => $duration?->totalHours,
                DetailedExportColumn::Billable => $model->billable ? 'Yes' : 'No',
                DetailedExportColumn::Tags => $model->tagsRelation->pluck('name')->implode(', '),
            };
        }

        return $row;
    }
}
