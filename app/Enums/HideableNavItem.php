<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

/**
 * Navigation/UI surfaces a user can hide for themselves. The user's
 * `hidden_nav_items` jsonb column stores the values of the items they hid;
 * an empty array means everything is visible.
 */
enum HideableNavItem: string
{
    use LaravelEnumHelper;

    case Projects = 'projects';
    case Members = 'members';
    case Calendar = 'calendar';
    case Timesheet = 'timesheet';
    case Tags = 'tags';
    case DashboardBillableWidgets = 'dashboard_billable_widgets';
    case Time = 'time';
    case Clients = 'clients';
    case Import = 'import';
    case ReportingShared = 'reporting_shared';
}
