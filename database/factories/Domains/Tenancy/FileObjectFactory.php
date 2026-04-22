<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Models\FileObject;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileObject>
 */
class FileObjectFactory extends Factory
{
    protected $model = FileObject::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'bucket' => 'sam',
            'object_key' => fake()->uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'size_bytes' => fake()->numberBetween(1024, 10485760),
            'content_type' => 'image/jpeg',
            'checksum' => hash('sha256', fake()->text(50)),
            'visibility' => 'private',
            'category' => 'attachment',
            'fileable_type' => null,
            'fileable_id' => null,
            'metadata_json' => null,
        ];
    }

    public function forMorph(string $type, int|string $id): static
    {
        return $this->state(fn () => [
            'fileable_type' => $type,
            'fileable_id' => $id,
        ]);
    }

    public function category(string $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }
}
