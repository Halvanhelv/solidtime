<?php

declare(strict_types=1);

namespace Tests\Unit\ReportExport;

use App\Enums\ExportFormat;
use App\Enums\TimeEntryAggregationType;
use Tests\TestCase;

class TimeEntriesReportExportThreeLevelTest extends TestCase
{
    /**
     * @return array{
     *      grouped_type: string|null,
     *      grouped_data: array<array{
     *          key: string|null,
     *          description: string|null,
     *          color: string|null,
     *          seconds: int,
     *          cost: int|null,
     *          grouped_type: string|null,
     *          grouped_data: array<array{
     *              key: string|null,
     *              description: string|null,
     *              color: string|null,
     *              seconds: int,
     *              cost: int|null,
     *              grouped_type: string|null,
     *              grouped_data: array<array{
     *                  key: string|null,
     *                  description: string|null,
     *                  color: string|null,
     *                  seconds: int,
     *                  cost: int|null,
     *                  grouped_type: null,
     *                  grouped_data: null
     *              }>
     *          }>
     *      }>,
     *      seconds: int,
     *      cost: int|null
     * }
     */
    private function threeLevelData(): array
    {
        return [
            'grouped_type' => TimeEntryAggregationType::Client->value,
            'grouped_data' => [
                [
                    'key' => 'client-1',
                    'description' => 'Client One',
                    'color' => null,
                    'seconds' => 300,
                    'cost' => 500,
                    'grouped_type' => TimeEntryAggregationType::Project->value,
                    'grouped_data' => [
                        [
                            'key' => 'project-1',
                            'description' => 'Project One',
                            'color' => '#fff000',
                            'seconds' => 300,
                            'cost' => 500,
                            'grouped_type' => TimeEntryAggregationType::Task->value,
                            'grouped_data' => [
                                [
                                    'key' => 'task-1',
                                    'description' => 'Task One',
                                    'color' => null,
                                    'seconds' => 300,
                                    'cost' => 500,
                                    'grouped_type' => null,
                                    'grouped_data' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'seconds' => 300,
            'cost' => 500,
        ];
    }

    /**
     * @return array{
     *      grouped_type: string|null,
     *      grouped_data: array<array{
     *          key: string|null,
     *          description: string|null,
     *          color: string|null,
     *          seconds: int,
     *          cost: int|null,
     *          grouped_type: string|null,
     *          grouped_data: array<array{
     *              key: string|null,
     *              description: string|null,
     *              color: string|null,
     *              seconds: int,
     *              cost: int|null,
     *              grouped_type: null,
     *              grouped_data: null
     *          }>
     *      }>,
     *      seconds: int,
     *      cost: int|null
     * }
     */
    private function twoLevelData(): array
    {
        return [
            'grouped_type' => TimeEntryAggregationType::Client->value,
            'grouped_data' => [
                [
                    'key' => 'client-1',
                    'description' => 'Client One',
                    'color' => null,
                    'seconds' => 300,
                    'cost' => 500,
                    'grouped_type' => TimeEntryAggregationType::Project->value,
                    'grouped_data' => [
                        [
                            'key' => 'project-1',
                            'description' => 'Project One',
                            'color' => '#fff000',
                            'seconds' => 300,
                            'cost' => 500,
                            'grouped_type' => null,
                            'grouped_data' => null,
                        ],
                    ],
                ],
            ],
            'seconds' => 300,
            'cost' => 500,
        ];
    }

    public function test_three_level_xlsx_export_contains_third_header_and_row_and_shifted_formulas(): void
    {
        // Arrange
        $data = $this->threeLevelData();

        // Act
        $html = view('reports.time-entry-aggregate.spreadsheet', [
            'data' => $data,
            'currency' => 'usd',
            'group' => TimeEntryAggregationType::Client,
            'subGroup' => TimeEntryAggregationType::Project,
            'subSubGroup' => TimeEntryAggregationType::Task,
            'exportFormat' => ExportFormat::XLSX,
            'showBillableRate' => true,
        ])->render();

        // Assert
        $this->assertStringContainsString(TimeEntryAggregationType::Task->description(), $html);
        $this->assertStringContainsString('Task One', $html);
        $this->assertStringContainsString('=SUM(D2:D2)', $html);
        $this->assertStringContainsString('=SUM(E2:E2)', $html);
        $this->assertStringContainsString('=SUM(F2:F2)', $html);
    }

    public function test_three_level_csv_export_contains_third_header_and_row(): void
    {
        // Arrange
        $data = $this->threeLevelData();

        // Act
        $html = view('reports.time-entry-aggregate.spreadsheet', [
            'data' => $data,
            'currency' => 'usd',
            'group' => TimeEntryAggregationType::Client,
            'subGroup' => TimeEntryAggregationType::Project,
            'subSubGroup' => TimeEntryAggregationType::Task,
            'exportFormat' => ExportFormat::CSV,
            'showBillableRate' => true,
        ])->render();

        // Assert
        $this->assertStringContainsString(TimeEntryAggregationType::Task->description(), $html);
        $this->assertStringContainsString('Task One', $html);
    }

    public function test_two_level_xlsx_export_still_uses_unshifted_formulas_when_sub_sub_group_is_null(): void
    {
        // Arrange
        $data = $this->twoLevelData();

        // Act
        $html = view('reports.time-entry-aggregate.spreadsheet', [
            'data' => $data,
            'currency' => 'usd',
            'group' => TimeEntryAggregationType::Client,
            'subGroup' => TimeEntryAggregationType::Project,
            'subSubGroup' => null,
            'exportFormat' => ExportFormat::XLSX,
            'showBillableRate' => true,
        ])->render();

        // Assert
        $this->assertStringNotContainsString(TimeEntryAggregationType::Task->description(), $html);
        $this->assertStringContainsString('=SUM(C2:C2)', $html);
        $this->assertStringContainsString('=SUM(D2:D2)', $html);
        $this->assertStringContainsString('=SUM(E2:E2)', $html);
        $this->assertStringNotContainsString('=SUM(F2:F2)', $html);
    }
}
