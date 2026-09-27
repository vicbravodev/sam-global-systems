<?php

namespace Database\Seeders\Concerns;

/**
 * Seeders de demo / prueba: crean cuentas con contraseñas conocidas
 * (`password`) y super-admins. NUNCA deben correr en producción, ni vía
 * DatabaseSeeder ni con `db:seed --class=...`.
 */
trait DevelopmentOnly
{
    /**
     * true (y avisa por consola) si estamos en producción y hay que omitir.
     */
    protected function skipInProduction(): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        $this->command?->warn(static::class.' omitido: es un seeder de demo/prueba y el entorno es producción.');

        return true;
    }
}
