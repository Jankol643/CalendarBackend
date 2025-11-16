<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTasksTable extends Migration {
    public function up() {
        //TODO: Seeder anpassen und ausführen
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->integer('duration')->nullable(); // Duration in minutes or preferred unit
            $table->integer('priority')->nullable(); // Priorityrity
            $table->timestamp('start_datetime')->nullable(); // Scheduled start time
            $table->timestamp('end_datetime')->nullable(); // Scheduled end time
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->onDelete('cascade');
            $table->string('uploaded');
            $table->timestamps();
        });
    }

    public function down() {
        Schema::dropIfExists('tasks');
    }
}
