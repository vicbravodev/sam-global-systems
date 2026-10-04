<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'sam:vapid-keys';

    protected $description = 'Genera un par de llaves VAPID para los avisos al dispositivo (pegar en .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Pega ambas en .env junto con VAPID_SUBJECT (p. ej. mailto:soporte@tu-dominio). Cambiarlas invalida todas las suscripciones.');

        return self::SUCCESS;
    }
}
