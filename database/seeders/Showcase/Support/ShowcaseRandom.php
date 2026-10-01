<?php

namespace Database\Seeders\Showcase\Support;

use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Aleatoriedad determinista para el showcase: la misma semilla produce los
 * mismos datos, así dos corridas eligen igual y los reportes son
 * comparables entre máquinas. Una instancia por "cosa" (`forKey`) evita que
 * añadir un paso nuevo desplace todo lo que viene detrás.
 */
final class ShowcaseRandom
{
    private Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    public static function forKey(string $key): self
    {
        return new self(crc32($key));
    }

    /**
     * Teléfono ficticio NO enrutable: el rango 555-0100…0199 de Norteamérica
     * está reservado para ficción. Así, si algún proceso real (Horizon,
     * scheduler) llegara a notificar a un contacto sembrado, nunca le
     * escribe o llama a una persona real.
     */
    public static function fictionalPhone(int $seed): string
    {
        $areas = [202, 212, 213, 305, 312, 415, 512, 617, 702, 713];

        return sprintf('+1%d55501%02d', $areas[$seed % count($areas)], intdiv($seed, count($areas)) % 100);
    }

    public function int(int $min, int $max): int
    {
        return $min >= $max ? $min : $this->randomizer->getInt($min, $max);
    }

    public function float(float $min, float $max, int $decimals = 2): float
    {
        if ($min >= $max) {
            return round($min, $decimals);
        }

        return round($min + $this->randomizer->nextFloat() * ($max - $min), $decimals);
    }

    public function chance(float $probability): bool
    {
        return $this->randomizer->nextFloat() < $probability;
    }

    /**
     * @template T
     *
     * @param  array<int|string, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * PHP convierte las claves numéricas (`'1'`) en enteros: se aceptan ambas
     * y el valor elegido siempre se devuelve como string.
     *
     * @param  array<int|string, int|float>  $weights  valor => peso
     */
    public function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = $this->randomizer->nextFloat() * $total;

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    public function sample(array $items, int $count): array
    {
        $items = array_values($items);

        if ($items === [] || $count >= count($items)) {
            return $items;
        }

        return array_values(array_intersect_key($items, array_flip($this->randomizer->pickArrayKeys($items, max(1, $count)))));
    }

    /**
     * Hora operativa realista para un día: picos en la salida de ruta
     * (7–10 h) y en el regreso (15–19 h), poca actividad de madrugada.
     */
    public function operationalMoment(CarbonImmutable $day): CarbonImmutable
    {
        $hour = (int) $this->weighted([
            '0' => 1, '1' => 1, '2' => 1, '3' => 1, '4' => 2, '5' => 4,
            '6' => 7, '7' => 10, '8' => 11, '9' => 10, '10' => 8, '11' => 7,
            '12' => 7, '13' => 7, '14' => 8, '15' => 9, '16' => 10, '17' => 10,
            '18' => 9, '19' => 7, '20' => 5, '21' => 4, '22' => 3, '23' => 2,
        ]);

        return $day->setTime($hour, $this->int(0, 59), $this->int(0, 59));
    }
}
