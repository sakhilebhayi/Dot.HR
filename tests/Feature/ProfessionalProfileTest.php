<?php

namespace Tests\Feature;

use App\Models\HrGrant;
use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/** R-32 Phase 3: the person's own professional profile and who may see it. */
class ProfessionalProfileTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_a_person_opens_their_own_workspace_and_gets_an_empty_private_profile(): void
    {
        $person = User::factory()->withPersonalTeam()->create(['name' => 'Boitumelo Setlhare']);

        $this->actingAs($person)->get(route('me.home'))
            ->assertOk()
            ->assertSee('Boitumelo, this is your own space')
            ->assertSee('Private — only me');

        $profile = ProfessionalProfile::query()->where('user_id', $person->id)->firstOrFail();
        $this->assertSame('private', $profile->visibility);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $profile->handle);
        $this->assertStringNotContainsStringIgnoringCase('boitumelo', $profile->handle);
    }

    public function test_the_profile_saves_and_refuses_values_outside_the_registries(): void
    {
        $person = User::factory()->withPersonalTeam()->create();

        $this->actingAs($person)->put(route('me.profile.update'), [
            'headline' => 'Bookkeeper · Sage and Pastel',
            'about' => 'Six years in retail.',
            'location' => 'Mahikeng',
            'availability' => 'looking',
            'visibility' => 'employers',
            'work_arrangements' => ['on_site', 'hybrid'],
            'work_types' => ['full_time', 'fixed_term'],
        ])->assertRedirect(route('me.profile'));

        $profile = ProfessionalProfile::query()->where('user_id', $person->id)->firstOrFail();
        $this->assertSame('employers', $profile->visibility);
        $this->assertSame(['full_time', 'fixed_term'], $profile->work_types);

        $this->actingAs($person)->put(route('me.profile.update'), [
            'availability' => 'looking', 'visibility' => 'everyone-ever', 'work_types' => ['astronaut'],
        ])->assertSessionHasErrors(['visibility', 'work_types.0']);
    }

    public function test_skills_and_history_are_added_deduplicated_and_removed_only_from_your_own_profile(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();

        $this->actingAs($person)->post(route('me.skills.store'), ['name' => 'Pastel Partner']);
        $this->actingAs($person)->post(route('me.skills.store'), ['name' => '  pastel   partner ']);
        $this->actingAs($person)->post(route('me.entries.store'), [
            'kind' => 'experience', 'title' => 'Bookkeeper', 'organisation' => 'Bapong Co-op',
            'started_on' => '2020-03-01', 'is_current' => 1,
        ]);

        $profile = ProfessionalProfile::query()->where('user_id', $person->id)->firstOrFail();
        $this->assertSame(1, $profile->skills()->count(), 'Case and spacing variants are one skill');
        $this->assertSame('Mar 2020 – now', $profile->entries()->firstOrFail()->period());

        $skillId = $profile->skills()->firstOrFail()->id;
        $entryId = $profile->entries()->firstOrFail()->id;

        // Someone else deleting by id touches nothing: the routes only act on your own profile.
        $this->actingAs($other)->delete(route('me.skills.destroy', $skillId));
        $this->actingAs($other)->delete(route('me.entries.destroy', $entryId));
        $this->assertSame(1, $profile->skills()->count());
        $this->assertSame(1, $profile->entries()->count());

        $this->actingAs($person)->delete(route('me.skills.destroy', $skillId));
        $this->assertSame(0, $profile->skills()->count());
    }

    private function profileWith(string $visibility): ProfessionalProfile
    {
        $person = User::factory()->withPersonalTeam()->create(['name' => 'Kagiso Mahlangu']);
        $profile = ProfessionalProfile::forUser($person);
        $profile->update(['visibility' => $visibility, 'headline' => 'Payroll clerk']);

        return $profile->fresh();
    }

    public function test_public_profiles_are_readable_by_anyone_and_never_show_contact_details(): void
    {
        $profile = $this->profileWith('public');

        $this->get(route('people.show', $profile->handle))
            ->assertOk()
            ->assertSee('Kagiso Mahlangu')
            ->assertSee('Payroll clerk')
            ->assertDontSee($profile->user->email);
    }

    public function test_private_and_applications_only_profiles_are_404_to_everyone_else(): void
    {
        foreach (['private', 'applications'] as $visibility) {
            $profile = $this->profileWith($visibility);
            $recruiter = $this->owner();

            $this->get(route('people.show', $profile->handle))->assertNotFound();
            $this->actingAs($recruiter)->get(route('people.show', $profile->handle))->assertNotFound();
            $this->actingAs($profile->user)->get(route('people.show', $profile->handle))->assertOk();
            auth()->logout();
        }
    }

    public function test_employers_visibility_admits_recruiters_only_and_honours_blocks(): void
    {
        $profile = $this->profileWith('employers');

        $admin = $this->owner();
        $plainMember = $this->memberOf($admin, 'viewer');
        $granted = $this->memberOf($admin, 'viewer');
        HrGrant::query()->create(['team_id' => $admin->currentTeam->id, 'user_id' => $granted->id, 'capability' => HrGrant::RECRUITER]);

        $this->get(route('people.show', $profile->handle))->assertNotFound();
        $this->actingAs($plainMember)->get(route('people.show', $profile->handle))->assertNotFound();
        $this->actingAs($granted)->get(route('people.show', $profile->handle))->assertOk();
        $this->actingAs($admin)->get(route('people.show', $profile->handle))->assertOk();

        // The person blocks that business by its exact name: both of its recruiters lose sight.
        $this->actingAs($profile->user)
            ->post(route('me.blocks.store'), ['business' => strtoupper($admin->currentTeam->name)])
            ->assertSessionHas('status');

        $this->actingAs($granted)->get(route('people.show', $profile->handle))->assertNotFound();
        $this->actingAs($admin)->get(route('people.show', $profile->handle))->assertNotFound();
    }

    public function test_blocking_an_unknown_name_says_so_and_never_lists_businesses(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $business = $this->owner()->currentTeam; // a business whose name the form must not reveal

        $this->actingAs($person)->get(route('me.profile'))->assertOk()->assertDontSee($business->name);

        $this->actingAs($person)
            ->post(route('me.blocks.store'), ['business' => 'No Such Company (Pty) Ltd'])
            ->assertSessionHas('error');
    }

    public function test_the_personal_workspace_lists_only_your_businesses_and_the_switcher_offers_personal(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $stranger = $this->owner();

        $this->actingAs($member)->get(route('me.home'))
            ->assertOk()
            ->assertSee($owner->currentTeam->name)
            ->assertDontSee($stranger->currentTeam->name);

        $this->actingAs($member)->get(route('dashboard'))
            ->assertSee(route('me.home'), false)
            ->assertSee('Your profile and career');
    }
}
