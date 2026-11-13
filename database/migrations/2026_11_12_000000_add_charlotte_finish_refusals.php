<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 0.4: consecutive cms_finish refusals (not_ready) in the current piece of
 * work. At charlotte.finish.max_not_ready the agent is stopped instead of
 * retrying. `finish_refusals_run` is the harness run a sub-agent's count
 * belongs to: each child run starts from zero (Prism maintainers). Null for a
 * top-level turn, whose count resets on a new message or a successful finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charlotte_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('finish_refusals')->default(0);
            $table->string('finish_refusals_run', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charlotte_sessions', function (Blueprint $table): void {
            $table->dropColumn(['finish_refusals', 'finish_refusals_run']);
        });
    }
};
