<?php

/**
 * Создаёт таблицы пользователей и стандартные служебные записи входа.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаёт пользователей, записи восстановления пароля и хранения входа. Само наличие этих таблиц не
     * добавляет в приложение регистрацию или форму входа.
     */
    public function up(): void
    {
        // Сведения о пользователях, которым могут принадлежать резервы и ключи доступа.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Стандартное хранение запросов на восстановление пароля.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Стандартное хранение состояния входа браузера.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Удаляет созданные служебные таблицы и пользователей при откате этого изменения. Перед откатом связанных
     * таблиц сначала откатываются более поздние изменения.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
