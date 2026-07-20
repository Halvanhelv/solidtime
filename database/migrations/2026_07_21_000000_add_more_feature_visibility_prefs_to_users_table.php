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
            $table->boolean('time_enabled')->default(true);
            $table->boolean('clients_enabled')->default(true);
            $table->boolean('import_enabled')->default(true);
            $table->boolean('reporting_shared_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'time_enabled',
                'clients_enabled',
                'import_enabled',
                'reporting_shared_enabled',
            ]);
        });
    }
};
