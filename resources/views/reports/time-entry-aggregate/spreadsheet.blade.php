@use('App\Enums\ExportFormat')
@use('Brick\Math\BigDecimal')
@use('PhpOffice\PhpSpreadsheet\Cell\DataType')
@use('PhpOffice\PhpSpreadsheet\Style\NumberFormat')
@use('Carbon\CarbonInterval')
@use('App\Enums\TimeEntryAggregationType')
@inject('interval', 'App\Service\IntervalService')
<table>
    <thead>
    <tr>
        <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            {{ $group->description() }}
        </th>
        <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            {{ $subGroup->description() }}
        </th>
        @if($subSubGroup)
        <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            {{ $subSubGroup->description() }}
        </th>
        @endif
        <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            Duration
        </th>
        <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            Duration (decimal)
        </th>
        @if($showBillableRate)
        <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            Amount ({{ Str::upper($currency) }})
        </th>
        @endif
    </tr>
    </thead>
    <tbody>
    @php
        $counter = 1;
        $totalDuration = 0;
        $totalCost = 0;
    @endphp
    @foreach($data['grouped_data'] ?? [] as $group1Entry)
        @foreach($group1Entry['grouped_data'] ?? [] as $group2Entry)
            @if($subSubGroup)
                @foreach($group2Entry['grouped_data'] ?? [] as $group3Entry)
                    @php
                        $duration = CarbonInterval::seconds($group3Entry['seconds']);
                    @endphp
                    <tr>
                        @if($exportFormat === ExportFormat::ODS || $exportFormat === ExportFormat::CSV)
                            @if ($group === TimeEntryAggregationType::Billable)
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group1Entry['key'] ? 'Yes' : 'No' }}
                                </td>
                            @else
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group1Entry['description'] ?? $group1Entry['key'] ?? '-' }}
                                </td>
                            @endif
                            @if ($subGroup === TimeEntryAggregationType::Billable)
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group2Entry['key'] ? 'Yes' : 'No' }}
                                </td>
                            @else
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group2Entry['description'] ?? $group2Entry['key'] ?? '-' }}
                                </td>
                            @endif
                            @if ($subSubGroup === TimeEntryAggregationType::Billable)
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group3Entry['key'] ? 'Yes' : 'No' }}
                                </td>
                            @else
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group3Entry['description'] ?? $group3Entry['key'] ?? '-' }}
                                </td>
                            @endif
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $interval->format($duration) }}
                            </td>
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ round($duration->totalHours, 2) }}
                            </td>
                            @if($showBillableRate)
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ round(BigDecimal::ofUnscaledValue($group3Entry['cost'], 2)->toFloat(), 2) }}
                            </td>
                            @endif
                        @else
                            @if ($group === TimeEntryAggregationType::Billable)
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group1Entry['key'] ? 'Yes' : 'No' }}
                                </td>
                            @else
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group1Entry['description'] ?? $group1Entry['key'] ?? '-' }}
                                </td>
                            @endif
                            @if ($subGroup === TimeEntryAggregationType::Billable)
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group2Entry['key'] ? 'Yes' : 'No' }}
                                </td>
                            @else
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group2Entry['description'] ?? $group2Entry['key'] ?? '-' }}
                                </td>
                            @endif
                            @if ($subSubGroup === TimeEntryAggregationType::Billable)
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group3Entry['key'] ? 'Yes' : 'No' }}
                                </td>
                            @else
                                <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                    {{ $group3Entry['description'] ?? $group3Entry['key'] ?? '-' }}
                                </td>
                            @endif
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_NUMERIC }}"
                                data-format="[hh]:mm:ss">
                                {{ $duration->totalDays }}
                            </td>
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_NUMERIC }}"
                                data-format="{{ NumberFormat::FORMAT_NUMBER_00 }}">
                                {{ $duration->totalHours }}
                            </td>
                            @if($showBillableRate)
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_NUMERIC }}"
                                data-format="{{ NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1 }}">
                                {{ BigDecimal::ofUnscaledValue($group3Entry['cost'], 2)->__toString() }}
                            </td>
                            @endif
                        @endif
                    </tr>
                    @php
                        ++$counter;
                        $totalDuration += $group3Entry['seconds'];
                        if ($showBillableRate) {
                            $totalCost += $group3Entry['cost'];
                        }
                    @endphp
                @endforeach
            @else
                @php
                    $duration = CarbonInterval::seconds($group2Entry['seconds']);
                @endphp
                <tr>
                    @if($exportFormat === ExportFormat::ODS || $exportFormat === ExportFormat::CSV)
                        @if ($group === TimeEntryAggregationType::Billable)
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group1Entry['key'] ? 'Yes' : 'No' }}
                            </td>
                        @else
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group1Entry['description'] ?? $group1Entry['key'] ?? '-' }}
                            </td>
                        @endif
                        @if ($subGroup === TimeEntryAggregationType::Billable)
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group2Entry['key'] ? 'Yes' : 'No' }}
                            </td>
                        @else
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group2Entry['description'] ?? $group2Entry['key'] ?? '-' }}
                            </td>
                        @endif
                        <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                            {{ $interval->format($duration) }}
                        </td>
                        <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                            {{ round($duration->totalHours, 2) }}
                        </td>
                        @if($showBillableRate)
                        <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                            {{ round(BigDecimal::ofUnscaledValue($group2Entry['cost'], 2)->toFloat(), 2) }}
                        </td>
                        @endif
                    @else
                        @if ($group === TimeEntryAggregationType::Billable)
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group1Entry['key'] ? 'Yes' : 'No' }}
                            </td>
                        @else
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group1Entry['description'] ?? $group1Entry['key'] ?? '-' }}
                            </td>
                        @endif
                        @if ($subGroup === TimeEntryAggregationType::Billable)
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group2Entry['key'] ? 'Yes' : 'No' }}
                            </td>
                        @else
                            <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_STRING }}">
                                {{ $group2Entry['description'] ?? $group2Entry['key'] ?? '-' }}
                            </td>
                        @endif
                        <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_NUMERIC }}"
                            data-format="[hh]:mm:ss">
                            {{ $duration->totalDays }}
                        </td>
                        <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_NUMERIC }}"
                            data-format="{{ NumberFormat::FORMAT_NUMBER_00 }}">
                            {{ $duration->totalHours }}
                        </td>
                        @if($showBillableRate)
                        <td style="border: 1px solid black;" data-type="{{ DataType::TYPE_NUMERIC }}"
                            data-format="{{ NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1 }}">
                            {{ BigDecimal::ofUnscaledValue($group2Entry['cost'], 2)->__toString() }}
                        </td>
                        @endif
                    @endif
                </tr>
                @php
                    ++$counter;
                    $totalDuration += $group2Entry['seconds'];
                    if ($showBillableRate) {
                        $totalCost += $group2Entry['cost'];
                    }
                @endphp
            @endif
        @endforeach
    @endforeach
    @php
        $labelColumnCount = $subSubGroup ? 3 : 2;
        $durationColumn = chr(ord('A') + $labelColumnCount);
        $decimalColumn = chr(ord('A') + $labelColumnCount + 1);
        $amountColumn = chr(ord('A') + $labelColumnCount + 2);
        // Tag grouping expands each entry into one row per tag, so summing the
        // visible rows double-counts multi-tag entries. Use the service's
        // corrected top-level total (matching the on-screen report) for the Total
        // row. For non-tag exports this equals the sum of the rows, so the xlsx
        // SUM() formulas are kept there; for tag exports a static corrected value
        // is written instead.
        $hasTagGrouping = $group === TimeEntryAggregationType::Tag
            || $subGroup === TimeEntryAggregationType::Tag
            || ($subSubGroup !== null && $subSubGroup === TimeEntryAggregationType::Tag);
        $reportTotalSeconds = $data['seconds'] ?? $totalDuration;
        $reportTotalCost = $data['cost'] ?? $totalCost;
        $reportTotalInterval = CarbonInterval::seconds($reportTotalSeconds);
    @endphp
    <tr style="border: 1px solid black;">
        <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}"></td>
        @if($subSubGroup)
        <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}"></td>
        @endif
        <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
            Total
        </td>
        @if($exportFormat === ExportFormat::ODS || $exportFormat === ExportFormat::CSV)
            <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
                {{ $interval->format($reportTotalInterval) }}
            </td>
            <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
                {{ round($reportTotalInterval->totalHours, 2) }}
            </td>
            @if($showBillableRate)
            <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
                {{ round(BigDecimal::ofUnscaledValue($reportTotalCost, 2)->toFloat(), 2) }}
            </td>
            @endif
        @else
            @if($hasTagGrouping)
                <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_NUMERIC }}"
                    data-format="[hh]:mm:ss">
                    {{ $reportTotalInterval->totalDays }}
                </td>
                <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_NUMERIC }}"
                    data-format="{{ NumberFormat::FORMAT_NUMBER_00 }}">
                    {{ $reportTotalInterval->totalHours }}
                </td>
                @if($showBillableRate)
                <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_NUMERIC }}"
                    data-format="{{ NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1 }}">
                    {{ BigDecimal::ofUnscaledValue($reportTotalCost, 2)->__toString() }}
                </td>
                @endif
            @else
                <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_FORMULA }}"
                    data-format="[hh]:mm:ss">
                    @if($counter > 1)
                        =SUM({{ $durationColumn }}2:{{ $durationColumn }}{{ $counter }})
                    @else
                        =0
                    @endif
                </td>
                <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_FORMULA }}"
                    data-format="{{ NumberFormat::FORMAT_NUMBER_00 }}">
                    @if($counter > 1)
                        =SUM({{ $decimalColumn }}2:{{ $decimalColumn }}{{ $counter }})
                    @else
                        =0
                    @endif
                </td>
                @if($showBillableRate)
                <td style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_FORMULA }}"
                    data-format="{{ NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1 }}">
                    @if($counter > 1)
                        =SUM({{ $amountColumn }}2:{{ $amountColumn }}{{ $counter }})
                    @else
                        =0
                    @endif
                </td>
                @endif
            @endif
        @endif
    </tr>
    </tbody>
</table>
