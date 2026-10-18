<?php

namespace App\Observers;

use App\Models\Position;
use App\Services\Hr\Audit;

class PositionObserver
{
    public function created(Position $position): void
    {
        Audit::record('position.created', $position);
    }

    public function updated(Position $position): void
    {
        $fields = Audit::changedFields($position);

        if ($fields !== []) {
            Audit::record('position.updated', $position, $fields);
        }
    }

    public function deleted(Position $position): void
    {
        Audit::record('position.deleted', $position);
    }
}
