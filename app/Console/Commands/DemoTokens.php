<?php

namespace App\Console\Commands;

use App\Services\DemoTokenService;
use Illuminate\Console\Command;

class DemoTokens extends Command
{
    protected $signature = 'demo:tokens';

    protected $description = 'Rotate and print tokens for the two seeded demo users';

    /**
     * Print new demo tokens after both replacements commit.
     */
    public function handle(): int
    {
        $tokens = DemoTokenService::rotate();

        if ($tokens === null) {
            $this->error('Run php artisan db:seed first.');

            return self::FAILURE;
        }

        foreach ($tokens as $email => $token) {
            $this->line($email . ' ' . $token);
        }

        return self::SUCCESS;
    }
}
