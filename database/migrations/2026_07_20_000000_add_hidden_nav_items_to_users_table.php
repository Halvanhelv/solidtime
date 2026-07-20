<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Values of the nav/UI surfaces this user has hidden (see App\Enums\HideableNavItem).
            // Empty array = everything visible. Adding a new hideable item needs no migration.
            $table->jsonb('hidden_nav_items')->default(new Expression("'[]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('hidden_nav_items');
        });
    }
};
