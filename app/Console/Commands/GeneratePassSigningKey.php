<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Mints an Ed25519 key pair for card entitlements.
 *
 * Prints rather than writes. Editing .env from a command is convenient right
 * up to the moment it silently clobbers a production secret, and a private key
 * that signs every card in circulation is not the place to find that out. The
 * operator pastes it, deliberately, once.
 */
class GeneratePassSigningKey extends Command
{
    protected $signature = 'pass:generate-key {--id=1 : Key id written into every card signed with it}';

    protected $description = 'Generate an Ed25519 signing key pair for Mova Pass entitlements';

    public function handle(): int
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->error('ext-sodium is not available. Ed25519 signing cannot run on this PHP build.');

            return self::FAILURE;
        }

        $keyId = (string) $this->option('id');
        $pair = sodium_crypto_sign_keypair();

        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        $existing = config('pass.signing.keys', []);
        $existing[$keyId] = ['public' => $public, 'secret' => $secret];

        $this->newLine();
        $this->line('<fg=green>Key pair generated.</> Add these to your .env, then run <fg=yellow>php artisan config:clear</>:');
        $this->newLine();
        $this->line("PASS_ACTIVE_KEY_ID={$keyId}");
        // Single-quoted so a shell never expands anything inside the JSON.
        $this->line("PASS_SIGNING_KEYS='" . json_encode($existing, JSON_UNESCAPED_SLASHES) . "'");
        $this->newLine();

        $this->warn('The SECRET half must never leave this server:');
        $this->line('  · never commit it, never put it in the back-office, never log it');
        $this->line('  · never reuse APP_KEY — one key per security domain, or rotation is impossible');
        $this->newLine();
        $this->line('Public key for Mova Control (safe to distribute):');
        $this->line("  {$keyId} => {$public}");
        $this->newLine();
        $this->info('Rotating? Keep the old entry in PASS_SIGNING_KEYS and only change');
        $this->info('PASS_ACTIVE_KEY_ID — cards already in circulation carry the id that');
        $this->info('signed them and must keep verifying (criterion A4).');

        return self::SUCCESS;
    }
}
