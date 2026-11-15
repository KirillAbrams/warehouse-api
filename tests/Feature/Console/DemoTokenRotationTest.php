<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Проверяет полную отмену замены ключей при настоящей ошибке записи базы.
 */
class DemoTokenRotationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Заставляет базу отказать при записи первого или второго нового ключа. Для команды и заполнения базы
     * сравнивает все прежние поля ключей и проверяет отсутствие частично выданных значений.
     */
    #[DataProvider('rotationFailures')]
    public function test_insert_failure_preserves_all_tokens_and_prints_no_credentials(string $command, string $failedEmail): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.test']);
        $bob = User::factory()->create(['email' => 'bob@example.test']);
        foreach ([$alice, $bob, User::factory()->create()] as $user) {
            $user->createToken('demo');
            $user->createToken('integration');
        }
        $before = $this->tokens();
        $failedId = $failedEmail === $alice->email ? $alice->id : $bob->id;
        DB::unprepared(
            "CREATE FUNCTION reject_demo_token() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.name = 'demo' AND NEW.tokenable_id = {$failedId} THEN RAISE EXCEPTION 'injected token insert failure'; END IF; RETURN NEW; END $$"
        );
        DB::unprepared(
            'CREATE TRIGGER reject_demo_token BEFORE INSERT ON personal_access_tokens FOR EACH ROW EXECUTE FUNCTION reject_demo_token()'
        );
        $failed = false;

        try {
            Artisan::call($command, ['--no-ansi' => true]);
        } catch (QueryException) {
            $failed = true;
        } finally {
            DB::unprepared('DROP TRIGGER reject_demo_token ON personal_access_tokens');
            DB::unprepared('DROP FUNCTION reject_demo_token()');
        }

        $this->assertTrue($failed, 'The injected database failure must reach the real rotation.');
        $this->assertSame($before, $this->tokens());
        $this->assertStringNotContainsString('alice@example.test ', Artisan::output());
        $this->assertStringNotContainsString('bob@example.test ', Artisan::output());
    }

    /**
     * Задаёт четыре случая отказа: две точки ошибки для двух способов запуска. Это проверяет общую операцию
     * через обе вызывающие стороны.
     */
    public static function rotationFailures(): array
    {
        return [
            'command first insert' => ['demo:tokens', 'alice@example.test'],
            'command second insert' => ['demo:tokens', 'bob@example.test'],
            'seeder first insert' => ['db:seed', 'alice@example.test'],
            'seeder second insert' => ['db:seed', 'bob@example.test'],
        ];
    }

    /**
     * Читает все поля ключей в постоянном порядке. Полный снимок обнаруживает не только потерю записи, но и
     * случайное изменение её остальных полей.
     */
    private function tokens(): array
    {
        return DB::table('personal_access_tokens')
            ->orderBy('id')
            ->get()
            ->map(fn (object $token): array => (array) $token)
            ->all();
    }
}
