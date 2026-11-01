<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\HrDocument;
use App\Models\HrDocumentAcknowledgement;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Employment documents and company policies (R-32 §10). Every file is checked by its content,
 * encrypted with the application key and kept on the private disk; it is only ever read back
 * through an authorised download. Every change is on the audit trail, and the people a
 * document concerns are told — in-app, and by email through Dot.Notify when that is set up.
 */
final class Documents
{
    private const DISK = 'local';

    /** File a document: on an employee's record, or a company policy when $employee is null. */
    public function store(Team $team, ?Employee $employee, UploadedFile $file, string $kind, string $title, User $by, string $source = 'hr', bool $shared = false, bool $requiresAcceptance = false): HrDocument
    {
        [$bytes, $mime] = $this->checked($file);
        $path = 'hr-documents/'.$team->id.'/'.Str::uuid().'.enc';

        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes))) {
            throw ValidationException::withMessages(['file' => 'The document could not be stored. Try again.']);
        }

        $policy = $employee === null;

        $document = DB::transaction(function () use ($team, $employee, $file, $kind, $title, $by, $source, $shared, $requiresAcceptance, $bytes, $mime, $path, $policy) {
            $document = new HrDocument([
                'kind' => $policy ? HrDocument::POLICY : $kind,
                'title' => trim($title),
                'shared' => $policy || $source === 'employee' || $shared || $requiresAcceptance,
                'requires_acceptance' => $requiresAcceptance,
            ]);
            $document->forceFill([
                'team_id' => $team->id,
                'employee_id' => $employee?->id,
                'file_name' => Str::limit(basename($file->getClientOriginalName()), 190, ''),
                'mime' => $mime,
                'size' => strlen($bytes),
                'path' => $path,
                'sha256' => hash('sha256', $bytes),
                'source' => $source,
                'uploaded_by' => $by->id,
            ])->save();

            Audit::record($policy ? 'policy.published' : ($source === 'employee' ? 'document.submitted' : 'document.filed'), $document, ['kind', 'title', 'shared', 'requires_acceptance'], [], $team->id);

            return $document;
        });

        $this->announce($document, $team);

        return $document;
    }

    /** The original bytes, after checking nothing changed them at rest. */
    public function read(HrDocument $document): string
    {
        $stored = Storage::disk(self::DISK)->get($document->path);
        if ($stored === null) {
            throw new RuntimeException('The document file is missing.');
        }

        $bytes = Crypt::decryptString($stored);
        if (! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('The document file does not match what was filed.');
        }

        return $bytes;
    }

    /** Let the employee see it (and, if asked, accept it). Sharing is announced once. */
    public function share(HrDocument $document, bool $requiresAcceptance): void
    {
        $wasShared = $document->shared;
        $document->forceFill(['shared' => true, 'requires_acceptance' => $requiresAcceptance || $document->requires_acceptance])->save();
        Audit::record('document.shared', $document, ['shared', 'requires_acceptance']);

        if (! $wasShared || $requiresAcceptance) {
            $this->announce($document, $document->team()->firstOrFail());
        }
    }

    /** Take it out of use. The file stays on record (employment records are kept), unseen by the employee. */
    public function withdraw(HrDocument $document, User $by): void
    {
        $document->forceFill(['withdrawn_at' => now(), 'withdrawn_by' => $by->id])->save();
        Audit::record($document->isPolicy() ? 'policy.withdrawn' : 'document.withdrawn', $document, ['withdrawn_at']);
    }

    /**
     * The employee confirms they read a policy or accept a document. Accepting a document asks for
     * the person's name as they would sign it; it is recorded with the time.
     */
    public function acknowledge(HrDocument $document, Employee $employee, User $user, ?string $typedName): HrDocumentAcknowledgement
    {
        if (! $document->visibleToEmployee($employee) || (! $document->isPolicy() && ! $document->requires_acceptance)) {
            throw ValidationException::withMessages(['typed_name' => 'This document does not ask for your acceptance.']);
        }

        if (! $document->isPolicy() && mb_strtolower(trim((string) $typedName)) !== mb_strtolower(trim($employee->fullName()))) {
            throw ValidationException::withMessages(['typed_name' => 'Type your full name exactly as it is on your record: '.$employee->fullName().'.']);
        }

        $ack = HrDocumentAcknowledgement::firstOrCreate(
            ['hr_document_id' => $document->id, 'employee_id' => $employee->id],
            ['team_id' => $document->team_id, 'user_id' => $user->id, 'typed_name' => $typedName !== null ? trim($typedName) : null, 'acknowledged_at' => now()],
        );

        if ($ack->wasRecentlyCreated) {
            Audit::record($document->isPolicy() ? 'policy.acknowledged' : 'document.accepted', $document, [], ['employee_id' => $employee->id]);
            $team = $document->team()->firstOrFail();
            Notification::send($team->administrators(), new LifecycleNotification(
                $document->isPolicy() ? 'A policy was acknowledged' : 'A document was accepted',
                $employee->fullName().($document->isPolicy() ? ' acknowledged ' : ' accepted ').'"'.$document->title.'".',
                $document->isPolicy() ? route('policies.index') : route('employees.show', $employee),
                teamId: $team->id,
            ));
        }

        return $ack;
    }

    /** @return array{0: string, 1: string} the bytes and the mime they really are */
    private function checked(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => 'The upload did not arrive intact. Try again.']);
        }
        if ($file->getSize() > HrDocument::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'A document may be at most '.(HrDocument::MAX_BYTES / 1048576).' MB.']);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! array_key_exists($mime, HrDocument::MIMES)) {
            throw ValidationException::withMessages(['file' => 'Send a PDF, a Word document, or a JPEG or PNG photo.']);
        }

        return [$bytes, $mime];
    }

    /** Tell the people a new or newly shared document concerns. */
    private function announce(HrDocument $document, Team $team): void
    {
        if ($document->isPolicy()) {
            $people = User::query()->whereIn('id', Employee::withoutTeamScope(fn () => Employee::query()->where('team_id', $team->id)->whereNotNull('user_id')->pluck('user_id')))->get();
            Notification::send($people, new LifecycleNotification(
                'A new company policy',
                '"'.$document->title.'" was published'.($document->requires_acceptance ? ' and asks you to confirm you have read it.' : '.'),
                route('policies.index'),
                teamId: $team->id,
            ));

            return;
        }

        $employee = $document->employee;
        if ($document->source === 'employee') {
            Notification::send($team->administrators(), new LifecycleNotification(
                'A document was handed in',
                $employee?->fullName().' handed in "'.$document->title.'".',
                $employee ? route('employees.show', $employee) : null,
                teamId: $team->id,
            ));

            return;
        }

        if ($document->shared && $employee?->user_id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification(
                $document->requires_acceptance ? 'A document is waiting for your acceptance' : 'A document was shared with you',
                '"'.$document->title.'" is in your documents.',
                route('my-work.index').'#documents',
                teamId: $team->id,
            ));
        }
    }
}
