<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 0.4: consecutive cms_finish refusals (not_ready) in the current piece of
 * work. At charlotte.finish.max_not_ready the agent is stopped instead of
 * retrying. Reset by a successful finish, a new turn, a new page sub-agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charlotte_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('finish_refusals')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('charlotte_sessions', function (Blueprint $table): void {
            $table->dropColumn('finish_refusals');
        });
    }
};
