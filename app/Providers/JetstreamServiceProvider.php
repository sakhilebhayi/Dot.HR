<?php

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateTeam::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        // How these map to Dot.HR is defined once, in App\Support\Hr\HrAccess.
        Jetstream::role('admin', 'HR administrator', [
            'create',
            'read',
            'update',
            'delete',
        ])->description('Sees everyone, decides leave, manages employees, positions and leave types, and reads the audit log. The team owner has this role too.');

        Jetstream::role('editor', 'Manager', [
            'read',
            'create',
            'update',
        ])->description('Sees the employee list (names, positions, status) and who is on leave and when. Never sees contact details or leave reasons, and changes nothing.');

        Jetstream::role('viewer', 'Member', [
            'read',
        ])->description('Sees only their own employee record and leave, once linked to it, and can request leave. Everyone InfoDot lists without an admin or editor role lands here.');
    }
}
