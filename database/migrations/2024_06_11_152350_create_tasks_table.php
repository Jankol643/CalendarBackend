<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class CreateTasksTable extends Migration {

    public function up(): void {
        //TODO: Seeder anpassen und ausführen
        Schema::create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->dateTime('due_date')->nullable();
            // Duration in minutes or preferred unit
            $table->integer('duration')->nullable();
            // Priorityrity
            $table->integer('priority')->nullable();
            // Scheduled start time
            $table->timestamp('start_datetime')->nullable();
            // Scheduled end time
            $table->timestamp('end_datetime')->nullable();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->onDelete('cascade');
            $table->string('uploaded');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('tasks');
    }

}
