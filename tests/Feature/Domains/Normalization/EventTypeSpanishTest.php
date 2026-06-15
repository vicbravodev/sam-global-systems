<?php

namespace Tests\Feature\Domains\Normalization;

use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventTypeSpanishTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_and_types_are_seeded_in_spanish(): void
    {
        $this->seed(NormalizationSeeder::class);

        $this->assertSame('Seguridad', DB::table('event_categories')->where('code', 'safety')->value('name'));
        $this->assertSame('Emergencia', DB::table('event_categories')->where('code', 'emergency')->value('name'));
        $this->assertSame('Botón de pánico', DB::table('event_types')->where('code', 'panic_button')->value('name'));
        $this->assertSame('Frenado brusco', DB::table('event_types')->where('code', 'harsh_braking')->value('name'));
    }

    public function test_no_event_type_name_is_left_in_a_known_english_token(): void
    {
        $this->seed(NormalizationSeeder::class);

        // Ningún nombre de tipo debe contener tokens delatores del inglés.
        $names = DB::table('event_types')->pluck('name')->all();

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(Button|Collision|Braking|Speeding|Driver|Warning|Stop|Light|Usage|Exit|Entry|Idle|Offline|Movement|Violation)\b/',
                (string) $name,
            );
        }
    }
}
