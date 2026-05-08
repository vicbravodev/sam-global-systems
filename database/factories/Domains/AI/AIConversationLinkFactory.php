<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Models\AIConversationLink;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<AIConversationLink>
 */
class AIConversationLinkFactory extends Factory
{
    protected $model = AIConversationLink::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'agent_conversation_id' => function () {
                $id = (string) fake()->uuid();

                if (Schema::hasTable('agent_conversations')) {
                    DB::table('agent_conversations')->insert([
                        'id' => $id,
                        'user_id' => null,
                        'title' => 'factory-conversation',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $id;
            },
            'normalized_event_id' => null,
            'evaluation_id' => null,
            'purpose' => 'event_evaluation',
            'metadata_json' => null,
        ];
    }
}
