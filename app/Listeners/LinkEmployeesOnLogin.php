<?php

namespace App\Listeners;

use App\Actions\Hr\LinkEmployeesByEmail;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/** On every sign-in, link the person to the employee record that carries their verified email. */
class LinkEmployeesOnLogin
{
    public function __construct(private LinkEmployeesByEmail $linker) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->linker->forUser($event->user);
        }
    }
}
