<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whole-site sessions (0.4): a site's structure, which site a session builds,
 * and which page each pending change set belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The default SiteRepository: pages, kinds, titles and routes. The
        // documents themselves stay in charlotte_pages under <site>/<id>.
        Schema::create('charlotte_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 191);
            $table->string('site_key', 128);
            $table->longText('structure_json');
            $table->timestamps();
            $table->unique(['tenant_key', 'site_key']);
        });

        Schema::table('charlotte_sessions', function (Blueprint $table): void {
            $table->string('site_key', 128)->nullable();
        });

        // One open change set per (session, page). Null on rows written
        // before 0.4: those belong to the session's page.
        Schema::table('charlotte_proposals', function (Blueprint $table): void {
            $table->string('page_key', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charlotte_proposals', function (Blueprint $table): void {
            $table->dropColumn('page_key');
        });
        Schema::table('charlotte_sessions', function (Blueprint $table): void {
            $table->dropColumn('site_key');
        });
        Schema::dropIfExists('charlotte_sites');
    }
};
