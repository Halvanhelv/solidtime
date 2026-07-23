<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

/**
 * A single column of the detailed time-entry export. The backing value is the
 * human-readable header shown in the exported file.
 */
enum DetailedExportColumn: string
{
    case Description = 'Description';
    case Task = 'Task';
    case Project = 'Project';
    case Client = 'Client';
    case User = 'User';
    case Start = 'Start';
    case End = 'End';
    case Duration = 'Duration';
    case DurationDecimal = 'Duration (decimal)';
    case Billable = 'Billable';
    case Tags = 'Tags';
}
