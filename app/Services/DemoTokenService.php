<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DemoTokenService
{
    public const USERS = [
        'Alice' => 'alice@example.test',
        'Bob' => 'bob@example.test',
    ];

    /**
     * Rotate both demo tokens atomically.
     *
     * @return array<string, string>|null
     */
    public function rotate(): ?array
    {
        // Serialize concurrent token rotations and roll back both users on failure.
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
