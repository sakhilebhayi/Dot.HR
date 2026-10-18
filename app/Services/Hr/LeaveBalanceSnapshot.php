<?php

namespace App\Services\Hr;

use Carbon\CarbonImmutable;

/** Read-only result of LeaveBalance::for(). See LeaveBalance for what each figure means. */
final readonly class LeaveBalanceSnapshot
{
    public float $remaining;

    public float $available;

    public function __construct(
        public bool $tracked,
        public float $entitlement,
        public float $carriedOver,
        public float $used,
        public float $pending,
        public CarbonImmutable $yearStart,
        public CarbonImmutable $yearEnd,
    ) {
        $this->remaining = $entitlement - $used;
        $this->available = $entitlement - $used - $pending;
    }
}
