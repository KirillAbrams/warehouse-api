<?php

namespace App\Actions\Demo;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Перевыпускает ключи доступа двух учебных пользователей. Команда и заполнение базы используют одну операцию,
 * чтобы не расходиться в правилах.
 */
final readonly class RotateDemoTokens
{
    public const USERS = [
        'Alice' => 'alice@example.test',
        'Bob' => 'bob@example.test',
    ];

    /**
     * Сначала находит обоих пользователей и удерживает их записи до завершения операции. Заменяет только
     * учебные ключи и возвращает новые значения после успешного сохранения. Если пользователя нет, возвращает
     * пустой результат; ошибка записи отменяет замену обоих ключей.
     *
     * @return array<string, string>|null
     */
    public function handle(): ?array
    {
        // Пока ключи меняются, второй запуск ждёт. Ошибка любой записи отменяет замену обоих пользователей.
        return DB::transaction(function (): ?array {
            $users = User::query()
                ->whereIn('email', array_values(self::USERS))
                ->orderBy('email')
                ->lockForUpdate()
                ->get();

            if ($users->count() !== count(self::USERS)) {
                return null;
            }

            $tokens = [];

            foreach ($users as $user) {
                $user->tokens()->where('name', 'demo')->delete();
                $tokens[$user->email] = $user->createToken('demo')->plainTextToken;
            }

            return $tokens;
        }, attempts: 3);
    }
}
