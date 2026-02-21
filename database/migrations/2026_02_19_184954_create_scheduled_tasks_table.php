<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('calendar_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('uploaded')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['uploaded', 'start_datetime']);
            $table->index(['task_id', 'start_datetime']);
            $table->index(['calendar_id', 'start_datetime']); // Added this index
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('scheduled_tasks');
    }
};
