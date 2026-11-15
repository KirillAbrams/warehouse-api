<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Services\DemoTokenService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Prepare repeatable demo records and rotate their access tokens.
     */
    public function run(DemoTokenService $rotate): void
    {
        foreach (DemoTokenService::USERS as $name => $email) {
            User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => bin2hex(random_bytes(32)),
            ]);
        }

        foreach ([
            ['KB-001', 'Mechanical keyboard', 5],
            ['MS-001', 'Wireless mouse', 12],
            ['MN-001', 'Monitor', 3],
        ] as [$sku, $name, $stock]) {
            Product::query()->firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'initial_stock' => $stock,
                'available_stock' => $stock,
            ]);
        }

        $tokens = $rotate->rotate() ?? throw new LogicException('Demo users disappeared before token rotation.');

        foreach ($tokens as $email => $token) {
            $this->command?->line($email . ' ' . $token);
        }
    }
}
