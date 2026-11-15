<?php

/**
 * Создаёт хранение ключей доступа пользователей.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Готовит записи защищённых представлений ключей, разрешений и сроков. Открытое значение ключа остаётся
     * только в ответе команды, которая его выдала.
     */
    public function up(): void
    {
        // Защищённые представления ключей доступа вместо их открытых значений.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Убирает таблицу ключей при откате этого изменения. После такого отката старые ключи перестают давать
     * доступ.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
