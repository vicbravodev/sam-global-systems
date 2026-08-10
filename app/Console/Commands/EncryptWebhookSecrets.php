<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptWebhookSecrets extends Command
{
    protected $signature = 'webhooks:encrypt-secrets';

    protected $description = 'Cifra at-rest los secretos de webhook que sigan en texto plano';

    public function handle(): int
    {
        $encrypted = 0;

        DB::table('webhook_endpoints')
            ->whereNotNull('secret')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$encrypted) {
                foreach ($rows as $row) {
                    if ($this->alreadyEncrypted($row->secret)) {
                        continue;
                    }

                    DB::table('webhook_endpoints')
                        ->where('id', $row->id)
                        ->update(['secret' => Crypt::encryptString($row->secret)]);

                    $encrypted++;
                }
            });

        $this->info("Secretos cifrados: {$encrypted}");

        return self::SUCCESS;
    }

    private function alreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
