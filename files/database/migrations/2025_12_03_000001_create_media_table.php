<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->default(config('media-manager.disk', 'public'));
            $table->string('dir');
            $table->string('path')->unique(); // relative to disk
            $table->string('original_name');
            $table->string('ext')->nullable();
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64)->nullable()->index();
            $table->json('thumbnails')->nullable(); // {"64":"thumbnails/...jpg","256":"..."}
            $table->enum('visibility', ['public','private'])->default('public');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('dir');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
