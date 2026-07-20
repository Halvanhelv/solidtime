<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('calendar_enabled')->default(true);
            $table->boolean('timesheet_enabled')->default(true);
            $table->boolean('tags_enabled')->default(true);
            $table->boolean('dashboard_billable_widgets_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'calendar_enabled',
                'timesheet_enabled',
                'tags_enabled',
                'dashboard_billable_widgets_enabled',
            ]);
        });
    }
};
