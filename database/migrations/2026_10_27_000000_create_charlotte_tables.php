<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One builder session: who, which page, which brief, which guide.
        Schema::create('charlotte_sessions', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('tenant_key', 191);
            $table->string('page_key', 191);
            $table->string('participant_type', 191);
            $table->string('participant_id', 191);
            $table->string('autonomy', 32);
            $table->string('provider', 64);
            $table->string('model', 191);
            $table->longText('brief_json');
            $table->string('guide_hash', 64);
            $table->text('guide_json');
            $table->string('status', 32);
            $table->timestamp('stop_requested_at')->nullable();
            $table->string('ready_revision', 40)->nullable();
            $table->timestamps();
            $table->index(['tenant_key', 'page_key']);
        });

        // The default PageRepository: a DRAFT (a fancy-cms PageSession with its
        // full attributed history) and, separately, what was last published.
        Schema::create('charlotte_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 191);
            $table->string('page_key', 191);
            $table->longText('draft_json');
            $table->longText('document_json');
            $table->longText('published_document_json')->nullable();
            $table->string('published_revision', 40)->nullable();
            $table->string('published_by', 191)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_key', 'page_key']);
        });

        // The append-only event log every transport reads.
        Schema::create('charlotte_events', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 26);
            $table->unsignedBigInteger('seq');
            $table->string('event_id', 26)->unique();
            $table->string('type', 64);
            $table->string('actor_kind', 16);
            $table->string('actor_id', 191);
            $table->longText('data_json');
            $table->string('at', 32);
            $table->unique(['session_id', 'seq']);
        });

        // approve_on_save: the pending change set. `working_json` is a
        // PageSession seeded from the draft, holding the agent's edits until a
        // person approves or rejects them.
        Schema::create('charlotte_proposals', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('session_id', 26);
            $table->string('state', 16);
            $table->string('base_revision', 40);
            $table->longText('working_json');
            $table->longText('ops_json');
            $table->unsignedInteger('batches')->default(0);
            $table->unsignedInteger('op_count')->default(0);
            $table->string('resolved_by', 191)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('agent_notified')->default(false);
            $table->timestamps();
            $table->index(['session_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charlotte_proposals');
        Schema::dropIfExists('charlotte_events');
        Schema::dropIfExists('charlotte_pages');
        Schema::dropIfExists('charlotte_sessions');
    }
};
