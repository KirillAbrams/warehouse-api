<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Заготовка пользователя для проверок. Нужные записи создаются внутри каждой проверки и не зависят от учебных
 * пользователей.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    // Одно готовое защищённое значение ускоряет создание множества независимых тестовых пользователей.
    protected static ?string $password;

    /**
     * Создаёт отдельный адрес, имя и защищённый пароль. Готовое представление пароля переиспользуется, чтобы
     * множество проверок не тратили время на одинаковое вычисление.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Возвращает заготовку пользователя с неподтверждённым адресом. Это позволяет явно подготовить такое
     * состояние без изменения общих настроек.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
