<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Export;

use App\Service\ReportExport\DetailedExportColumn;
use App\Service\ReportExport\DetailedExportColumns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DetailedExportColumns::class)]
#[CoversClass(DetailedExportColumn::class)]
class DetailedExportColumnsTest extends TestCase
{
    public function test_for_without_tags_returns_the_base_columns_in_order(): void
    {
        // Act
        $columns = DetailedExportColumns::for(includeTags: false);

        // Assert
        $this->assertSame([
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
        ], $columns);
    }

    public function test_for_with_tags_appends_tags_as_the_last_column(): void
    {
        // Act
        $columns = DetailedExportColumns::for(includeTags: true);

        // Assert
        $this->assertContains(DetailedExportColumn::Tags, $columns);
        $this->assertSame(DetailedExportColumn::Tags, $columns[array_key_last($columns)]);
    }

    public function test_labels_without_tags_are_the_human_readable_headers(): void
    {
        // Act
        $labels = DetailedExportColumns::labels(includeTags: false);

        // Assert
        $this->assertSame([
            'Description',
            'Task',
            'Project',
            'Client',
            'User',
            'Start',
            'End',
            'Duration',
            'Duration (decimal)',
            'Billable',
        ], $labels);
    }

    public function test_labels_with_tags_ends_with_the_tags_header(): void
    {
        // Act
        $labels = DetailedExportColumns::labels(includeTags: true);

        // Assert
        $this->assertSame('Tags', $labels[array_key_last($labels)]);
    }
}
