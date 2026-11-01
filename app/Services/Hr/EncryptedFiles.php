<?php

namespace App\Services\Hr;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Files kept by Dot.HR itself: checked by content (not by name), encrypted with the application
 * key on the private disk, and checked against their SHA-256 whenever they are read back. The
 * same rules employment documents follow (Documents), for other uploads such as invoices.
 */
final class EncryptedFiles
{
    private const DISK = 'local';

    /**
     * @param  array<string, string>  $mimes  allowed mime => file extension
     * @return array{path: string, name: string, mime: string, size: int, sha256: string}
     */
    public function put(UploadedFile $file, string $folder, array $mimes, int $maxBytes, string $field = 'file'): array
    {
        if (! $file->isValid() || $file->getSize() <= 0) {
            throw ValidationException::withMessages([$field => 'The upload did not arrive intact. Try again.']);
        }
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([$field => 'A file may be at most '.(int) ($maxBytes / 1048576).' MB.']);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! array_key_exists($mime, $mimes)) {
            throw ValidationException::withMessages([$field => 'Send a '.implode(', ', array_unique(array_map('strtoupper', $mimes))).' file.']);
        }

        $path = trim($folder, '/').'/'.Str::uuid().'.enc';
        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes))) {
            throw ValidationException::withMessages([$field => 'The file could not be stored. Try again.']);
        }

        return ['path' => $path, 'name' => Str::limit(basename($file->getClientOriginalName()), 190, ''), 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    /** The original bytes, after checking nothing changed them at rest. */
    public function read(string $path, string $sha256): string
    {
        $stored = Storage::disk(self::DISK)->get($path);
        if ($stored === null) {
            throw new RuntimeException('The file is missing.');
        }

        $bytes = Crypt::decryptString($stored);
        if (! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('The file does not match what was received.');
        }

        return $bytes;
    }
}
