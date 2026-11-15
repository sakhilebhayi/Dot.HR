<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Hr\HeaderSearch as Search;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The search in the top bar (the shared dot-ui pill with its results under it), on every page a person is signed in.
 *
 * What it may find is decided in App\Services\Hr\HeaderSearch from the signed-in person's own access, never from anything
 * the browser sends: `personal` only picks the career workspace (own applications and the public job board) over the
 * business one, and either way the rows are those the person may already open.
 * Nothing is asked until the box has been focused, so a page that never uses the search runs no extra query.
 */
class HeaderSearch extends Component
{
    public string $query = '';

    /** The personal (career) workspace: set by the layout, not editable from the page. */
    #[Locked]
    public bool $personal = false;

    /** Set when the box is first focused. */
    public bool $touched = false;

    public function touch(): void
    {
        $this->touched = true;
    }

    /** Enter with nothing highlighted: the pill's own script opens the first row; nothing else to do. */
    public function submit(): void {}

    public function render(Search $search): View
    {
        /** @var User|null $me */
        $me = auth()->user();

        return view('livewire.header-search', [
            'groups' => $this->touched ? $search->search($me, $this->query, $this->personal) : [],
            'typed' => mb_strlen(Search::clean($this->query)) >= Search::MIN_CHARS,
        ]);
    }
}
