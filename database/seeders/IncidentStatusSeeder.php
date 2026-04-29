<?php

namespace Database\Seeders;

use App\Domains\Incidents\Models\IncidentStatus;
use Illuminate\Database\Seeder;

class IncidentStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['code' => 'open', 'name' => 'Open', 'is_terminal' => false, 'sort_order' => 1],
            ['code' => 'in_review', 'name' => 'In Review', 'is_terminal' => false, 'sort_order' => 2],
            ['code' => 'escalated', 'name' => 'Escalated', 'is_terminal' => false, 'sort_order' => 3],
            ['code' => 'resolved', 'name' => 'Resolved', 'is_terminal' => true, 'sort_order' => 4],
            ['code' => 'closed', 'name' => 'Closed', 'is_terminal' => true, 'sort_order' => 5],
            ['code' => 'false_positive', 'name' => 'False Positive', 'is_terminal' => true, 'sort_order' => 6],
            ['code' => 'cancelled', 'name' => 'Cancelled', 'is_terminal' => true, 'sort_order' => 7],
        ];

        foreach ($statuses as $status) {
            IncidentStatus::query()->updateOrCreate(['code' => $status['code']], $status);
        }
    }
}
