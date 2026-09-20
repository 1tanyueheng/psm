<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Module 3 — An uploaded submission file.
 *
 * Files are never hard-deleted: a revision supersedes the previous file
 * (`is_current = false`) so Module 7 can prove what was submitted and when.
 */
class SubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'revision_no',
        'is_current',
        'superseded_at',
        'superseded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_current'    => 'boolean',
            'size_bytes'    => 'integer',
            'revision_no'   => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function superseder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_ends_with(strtolower($this->original_name), '.pdf');
    }

    /** Human-readable size for the UI. */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];

        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    /** Extension used for icon selection in the React file list. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /**
     * A temporary URL. Files live on a private disk, so this is the only way
     * to serve them — and every call is audited (Module 7).
     */
    public function temporaryUrl(int $minutes = 10): ?string
    {
        $disk = Storage::disk($this->disk);

        try {
            return $disk->temporaryUrl($this->path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            // Local disk has no temporaryUrl support; the controller streams instead
            return null;
        }
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /** Approximate page count for PDFs, used to flag suspiciously short reports. */
    public function looksSuspiciouslySmall(int $bytesThreshold = 20_480): bool
    {
        return $this->isPdf() && $this->size_bytes < $bytesThreshold;
    }
}
