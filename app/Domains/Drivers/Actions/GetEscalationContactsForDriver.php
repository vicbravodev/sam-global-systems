<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverContact;
use Illuminate\Support\Collection;

class GetEscalationContactsForDriver
{
    /**
     * Returns contacts ordered by escalation priority:
     * 1. Emergency contacts
     * 2. Supervisor contacts
     * 3. Primary contacts
     * 4. Remaining contacts
     *
     * @return Collection<int, DriverContact>
     */
    public function execute(Driver $driver): Collection
    {
        $contacts = $driver->contacts()->get();

        return $contacts->sortBy(function (DriverContact $contact) {
            if ($contact->is_emergency) {
                return 0;
            }

            if ($contact->contact_type === ContactType::SupervisorContact) {
                return 1;
            }

            if ($contact->is_primary) {
                return 2;
            }

            return 3;
        })->values();
    }
}
