<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Export;

use App\Enums\ExportFormat;
use App\Models\Member;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\LocalizationService;
use App\Service\ReportExport\TimeEntriesDetailedCsvExport;
use App\Service\ReportExport\TimeEntriesDetailedExport;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(TimeEntriesDetailedCsvExport::class)]
#[CoversClass(TimeEntriesDetailedExport::class)]
class TimeEntriesDetailedExportTest extends TestCaseWithDatabase
{
    /**
     * @return array{0: TimeEntry, 1: Organization}
     */
    private function createFullyLoadedTimeEntry(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->create();
        $timeEntry = TimeEntry::factory()
            ->forOrganization($organization)
            ->forMember($member)
            ->withTags($organization)
            ->create();

        // Reload with the relations mapRow()/map() touch, eager loaded, since
        // Model::preventLazyLoading() is active outside production and would
        // otherwise throw when mapRow()/map() access unloaded relations.
        $loaded = TimeEntry::query()
            ->with(['task', 'project', 'client', 'user', 'tagsRelation'])
            ->where('id', $timeEntry->getKey())
            ->firstOrFail();

        return [$loaded, $organization];
    }

    public function test_csv_export_header_does_not_contain_tags_column(): void
    {
        // Assert
        $this->assertNotContains('Tags', TimeEntriesDetailedCsvExport::HEADER);
    }

    public function test_csv_export_map_row_returns_a_row_matching_the_header_and_does_not_throw(): void
    {
        // Arrange
        [$timeEntry] = $this->createFullyLoadedTimeEntry();
        $export = new TimeEntriesDetailedCsvExport(
            config('filesystems.private'),
            'exports',
            'test-export.csv',
            TimeEntry::query(),
            1000,
            'UTC'
        );

        // Act
        $row = $export->mapRow($timeEntry);

        // Assert
        $this->assertSame(TimeEntriesDetailedCsvExport::HEADER, array_keys($row));
        $this->assertArrayNotHasKey('Tags', $row);
    }

    public function test_csv_export_writes_a_file_without_a_tags_column_and_does_not_throw(): void
    {
        // Arrange
        $this->mockPrivateStorage();
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->create();
        TimeEntry::factory()->forOrganization($organization)->forMember($member)->withTags($organization)->create();
        $filename = 'test-export.csv';
        $export = new TimeEntriesDetailedCsvExport(
            config('filesystems.private'),
            'exports',
            $filename,
            TimeEntry::query()->whereBelongsTo($organization, 'organization')->with(['task', 'project', 'client', 'user', 'tagsRelation']),
            1000,
            'UTC'
        );

        // Act
        $export->export();

        // Assert
        Storage::disk(config('filesystems.private'))->assertExists('exports/'.$filename);
        $content = Storage::disk(config('filesystems.private'))->get('exports/'.$filename);
        $headerLine = strtok((string) $content, "\n");
        $this->assertStringNotContainsString('Tags', (string) $headerLine);
    }

    public function test_excel_export_headings_do_not_contain_tags_column(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            ExportFormat::XLSX,
            'UTC',
            LocalizationService::forOrganization($organization)
        );

        // Act
        $headings = $export->headings();

        // Assert
        $this->assertNotContains('Tags', $headings);
    }

    public function test_excel_export_map_returns_a_row_matching_the_headings_count_and_does_not_throw(): void
    {
        // Arrange
        [$timeEntry, $organization] = $this->createFullyLoadedTimeEntry();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            ExportFormat::XLSX,
            'UTC',
            LocalizationService::forOrganization($organization)
        );

        // Act
        $row = $export->map($timeEntry);
        $headings = $export->headings();

        // Assert
        $this->assertCount(count($headings), $row);
    }

    public function test_ods_export_map_returns_a_row_matching_the_headings_count_and_does_not_throw(): void
    {
        // Arrange
        [$timeEntry, $organization] = $this->createFullyLoadedTimeEntry();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            ExportFormat::ODS,
            'UTC',
            LocalizationService::forOrganization($organization)
        );

        // Act
        $row = $export->map($timeEntry);
        $headings = $export->headings();

        // Assert
        $this->assertCount(count($headings), $row);
    }
}
