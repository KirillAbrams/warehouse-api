<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoTokenRotationTest extends TestCase
{
    use DatabaseMigrations;

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

    public static function rotationFailures(): array
    {
        return [
            'command first insert' => ['demo:tokens', 'alice@example.test'],
            'command second insert' => ['demo:tokens', 'bob@example.test'],
            'seeder first insert' => ['db:seed', 'alice@example.test'],
            'seeder second insert' => ['db:seed', 'bob@example.test']
        ];
    }

    private function tokens(): array
    {
        return DB::table('personal_access_tokens')
            ->orderBy('id')
            ->get()
            ->map(fn(object $token): array => (array)$token)
            ->all();
    }
}
