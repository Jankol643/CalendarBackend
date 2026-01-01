<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class CreateCalendarsTable extends Migration {

    public function up(): void {
        Schema::create('calendars', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('calendars');
    }

}
