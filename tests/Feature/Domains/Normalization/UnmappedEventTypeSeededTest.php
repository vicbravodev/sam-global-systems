<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Normalization\Models\EventType;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmappedEventTypeSeededTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmapped_event_type_exists_after_seeding(): void
    {
        $this->seed(NormalizationSeeder::class);

        $unmapped = EventType::query()->where('code', 'unmapped')->first();

        $this->assertNotNull($unmapped, 'El tipo unmapped no se sembró: los eventos desconocidos caerían en panic_button');
        $this->assertNotSame(
            EventType::query()->orderBy('id')->value('code'),
            'unmapped',
            'unmapped no debe ser el primer tipo: el fallback dejaría de ser detectable',
        );
    }
}
