<?php

namespace App\Console\Commands;

use App\Actions\Demo\RotateDemoTokens;
use Illuminate\Console\Command;

/**
 * Команда для получения новых ключей доступа учебных пользователей. Сами правила замены ключей находятся в
 * общей операции.
 */
class DemoTokens extends Command
{
    protected $signature = 'demo:tokens';

    protected $description = 'Rotate and print tokens for the two seeded demo users';

    /**
     * Просит общую операцию заменить ключи. При отсутствии пользователя печатает сообщение о заполнении базы.
     * Новые ключи выводит только после успешного сохранения всех изменений.
     */
    public function handle(RotateDemoTokens $rotate): int
    {
        $tokens = $rotate->handle();

        if ($tokens === null) {
            $this->error('Run php artisan db:seed first.');

            return self::FAILURE;
        }

        foreach ($tokens as $email => $token) {
            $this->line($email.' '.$token);
        }

        return self::SUCCESS;
    }
}
