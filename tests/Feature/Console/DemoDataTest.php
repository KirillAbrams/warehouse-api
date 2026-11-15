<?php

namespace Tests\Feature\Console;

use App\Actions\Reservations\CreateReservation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Проверяет заполнение учебных данных и выдачу ключей без потери существующей истории.
 */
class DemoDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Дважды заполняет базу, создав резерв между запусками. Проверяет сохранение удержанного количества,
     * истории и защищённого хранения новых ключей.
     */
    public function test_repeated_seeding_preserves_inventory_and_issues_hashed_demo_tokens(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('products', 3);
        $this->assertSame(2, PersonalAccessToken::query()->where('name', 'demo')->count());
        $owner = User::query()->where('email', 'alice@example.test')->firstOrFail();
        $product = Product::query()->where('sku', 'KB-001')->firstOrFail();
        $this->app->make(CreateReservation::class)->handle($owner->id, $product->id, 2, 'seed-check');

        $this->seed();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'available_stock' => 3, 'reserved_stock' => 2]);
        foreach (PersonalAccessToken::query()->get() as $token) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token->token);
        }
    }

    /**
     * Запускает выдачу ключей до создания пользователей. Ожидает понятный отказ и отсутствие созданных ключей.
     */
    public function test_token_command_requires_seeded_users(): void
    {
        $this->artisan('demo:tokens')->expectsOutput('Run php artisan db:seed first.')->assertFailed();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Оставляет только одного из двух учебных пользователей и сохраняет снимок всех ключей. Проверяет, что
     * отказ ничего не отозвал, не создал и не напечатал частичный результат.
     */
    #[DataProvider('singleDemoUsers')]
    public function test_missing_demo_user_leaves_all_existing_tokens_unchanged(string $existingEmail): void
    {
        $existing = User::factory()->create(['email' => $existingEmail]);
        $other = User::factory()->create();
        $existing->createToken('demo');
        $existing->createToken('integration');
        $other->createToken('demo');
        $before = DB::table('personal_access_tokens')
            ->orderBy('id')
            ->get()
            ->map(fn (object $token): array => (array) $token)
            ->all();

        $exitCode = Artisan::call('demo:tokens', ['--no-ansi' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(
            $before,
            DB::table('personal_access_tokens')
                ->orderBy('id')
                ->get()
                ->map(fn (object $token): array => (array) $token)
                ->all()
        );
        $this->assertSame('Run php artisan db:seed first.'.PHP_EOL, Artisan::output());
        $this->assertDatabaseCount('users', 2);
    }

    /**
     * Перечисляет оба варианта отсутствующего пользователя. Одна и та же проверка должна защищать каждого из
     * них.
     */
    public static function singleDemoUsers(): array
    {
        return [['alice@example.test'], ['bob@example.test']];
    }

    /**
     * Создаёт учебный и отдельный служебный ключ. Проверяет замену только учебных ключей, сохраняя остальные
     * без изменений.
     */
    public function test_token_command_rotates_only_demo_tokens(): void
    {
        $this->seed();
        $owner = User::query()->where('email', 'alice@example.test')->firstOrFail();
        $kept = $owner->createToken('integration')->accessToken;
        $oldIds = PersonalAccessToken::query()->where('name', 'demo')->pluck('id')->all();

        $this->artisan('demo:tokens')->assertSuccessful();
        $this->assertSame(2, PersonalAccessToken::query()->where('name', 'demo')->count());
        $this->assertSame(0, PersonalAccessToken::query()->whereIn('id', $oldIds)->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $kept->id, 'name' => 'integration']);
    }
}
