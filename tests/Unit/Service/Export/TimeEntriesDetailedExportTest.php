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
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function makeCsvExport(string $filename, bool $includeTags, ?Organization $organization = null): TimeEntriesDetailedCsvExport
    {
        $query = TimeEntry::query()->with(['task', 'project', 'client', 'user', 'tagsRelation']);
        if ($organization !== null) {
            $query->whereBelongsTo($organization, 'organization');
        }

        return new TimeEntriesDetailedCsvExport(
            config('filesystems.private'),
            'exports',
            $filename,
            $query,
            1000,
            'UTC',
            $includeTags,
        );
    }

    public function test_csv_export_header_contains_tags_column_when_tags_are_included(): void
    {
        // Act
        $header = $this->makeCsvExport('test-export.csv', includeTags: true)->header();

        // Assert
        $this->assertContains('Tags', $header);
        $this->assertSame('Tags', $header[array_key_last($header)]);
    }

    public function test_csv_export_header_does_not_contain_tags_column_when_tags_are_excluded(): void
    {
        // Act
        $header = $this->makeCsvExport('test-export.csv', includeTags: false)->header();

        // Assert
        $this->assertNotContains('Tags', $header);
    }

    public function test_csv_export_map_row_keys_match_the_header_with_tags(): void
    {
        // Arrange
        [$timeEntry] = $this->createFullyLoadedTimeEntry();
        $export = $this->makeCsvExport('test-export.csv', includeTags: true);

        // Act
        $row = $export->mapRow($timeEntry);

        // Assert
        $this->assertSame($export->header(), array_keys($row));
        $this->assertArrayHasKey('Tags', $row);
    }

    public function test_csv_export_map_row_keys_match_the_header_without_tags(): void
    {
        // Arrange
        [$timeEntry] = $this->createFullyLoadedTimeEntry();
        $export = $this->makeCsvExport('test-export.csv', includeTags: false);

        // Act
        $row = $export->mapRow($timeEntry);

        // Assert
        $this->assertSame($export->header(), array_keys($row));
        $this->assertArrayNotHasKey('Tags', $row);
    }

    public function test_csv_export_writes_a_file_with_a_tags_column_and_tag_names(): void
    {
        // Arrange
        $this->mockPrivateStorage();
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->create();
        $timeEntry = TimeEntry::factory()->forOrganization($organization)->forMember($member)->withTags($organization)->create();
        $tagNames = TimeEntry::query()->with('tagsRelation')->findOrFail($timeEntry->getKey())->tagsRelation->pluck('name');
        $filename = 'test-export.csv';

        // Act
        $this->makeCsvExport($filename, includeTags: true, organization: $organization)->export();

        // Assert
        $content = (string) Storage::disk(config('filesystems.private'))->get('exports/'.$filename);
        $headerLine = (string) strtok($content, "\n");
        $this->assertStringContainsString('Tags', $headerLine);
        foreach ($tagNames as $tagName) {
            $this->assertStringContainsString($tagName, $content);
        }
    }

    public function test_csv_export_writes_a_file_without_a_tags_column(): void
    {
        // Arrange
        $this->mockPrivateStorage();
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->create();
        TimeEntry::factory()->forOrganization($organization)->forMember($member)->withTags($organization)->create();
        $filename = 'test-export.csv';

        // Act
        $this->makeCsvExport($filename, includeTags: false, organization: $organization)->export();

        // Assert
        Storage::disk(config('filesystems.private'))->assertExists('exports/'.$filename);
        $content = (string) Storage::disk(config('filesystems.private'))->get('exports/'.$filename);
        $headerLine = (string) strtok($content, "\n");
        $this->assertStringNotContainsString('Tags', $headerLine);
    }

    public function test_excel_export_headings_contain_tags_column_when_tags_are_included(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            ExportFormat::XLSX,
            'UTC',
            LocalizationService::forOrganization($organization),
            includeTags: true,
        );

        // Act
        $headings = $export->headings();

        // Assert
        $this->assertContains('Tags', $headings);
        $this->assertSame('Tags', $headings[array_key_last($headings)]);
    }

    public function test_excel_export_headings_do_not_contain_tags_column_when_tags_are_excluded(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            ExportFormat::XLSX,
            'UTC',
            LocalizationService::forOrganization($organization),
            includeTags: false,
        );

        // Act
        $headings = $export->headings();

        // Assert
        $this->assertNotContains('Tags', $headings);
    }

    /**
     * @return array<string, array{0: ExportFormat, 1: bool}>
     */
    public static function formatAndTagsProvider(): array
    {
        return [
            'xlsx with tags' => [ExportFormat::XLSX, true],
            'xlsx without tags' => [ExportFormat::XLSX, false],
            'ods with tags' => [ExportFormat::ODS, true],
            'ods without tags' => [ExportFormat::ODS, false],
        ];
    }

    #[DataProvider('formatAndTagsProvider')]
    public function test_excel_export_map_returns_a_row_matching_the_headings_count(ExportFormat $format, bool $includeTags): void
    {
        // Arrange
        [$timeEntry, $organization] = $this->createFullyLoadedTimeEntry();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            $format,
            'UTC',
            LocalizationService::forOrganization($organization),
            includeTags: $includeTags,
        );

        // Act
        $row = $export->map($timeEntry);
        $headings = $export->headings();

        // Assert
        $this->assertCount(count($headings), $row);
    }

    public function test_excel_export_map_includes_tag_names_when_tags_are_included(): void
    {
        // Arrange
        [$timeEntry, $organization] = $this->createFullyLoadedTimeEntry();
        $export = new TimeEntriesDetailedExport(
            TimeEntry::query(),
            ExportFormat::XLSX,
            'UTC',
            LocalizationService::forOrganization($organization),
            includeTags: true,
        );

        // Act
        $row = $export->map($timeEntry);

        // Assert
        $expected = $timeEntry->tagsRelation->pluck('name')->implode(', ');
        $this->assertContains($expected, $row);
    }
}
