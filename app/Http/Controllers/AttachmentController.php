<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * File attachments linked to assets (PRD §37).
 *
 * Metadata lives in PostgreSQL; files live on the local disk for the MVP
 * (SharePoint in Phase 8+). Strict upload validation (PRD §50).
 */
class AttachmentController extends Controller
{
    private const MAX_SIZE_KB = 10240; // 10 MB

    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword', 'application/vnd.ms-excel',
        'text/plain', 'text/csv',
    ];

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('manageAttachments', $asset);

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_SIZE_KB, 'mimetypes:'.implode(',', self::ALLOWED_MIMES)],
        ]);

        $uploaded = $request->file('file');
        $path = $uploaded->store('attachments/'.$asset->id);

        $attachment = Attachment::create([
            'asset_id' => $asset->id,
            'file_name' => $uploaded->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $this->fileType($uploaded->getMimeType()),
            'mime_type' => $uploaded->getMimeType(),
            'file_size' => $uploaded->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'UPLOAD',
            'entity_type' => 'attachments',
            'entity_id' => $attachment->id,
            'entity_identifier' => $attachment->file_name,
            'source' => 'Attachment',
        ]);

        return back()->with('success', 'File uploaded.');
    }

    public function download(Asset $asset, Attachment $attachment)
    {
        $this->authorize('view', $asset);

        abort_unless($attachment->asset_id === $asset->id, 404);
        abort_unless(Storage::exists($attachment->file_path), 404);

        return Storage::download($attachment->file_path, $attachment->file_name);
    }

    public function destroy(Asset $asset, Attachment $attachment)
    {
        $this->authorize('manageAttachments', $asset);

        abort_unless($attachment->asset_id === $asset->id, 404);

        Storage::delete($attachment->file_path);
        $attachment->delete();

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'DELETE_ATTACHMENT',
            'entity_type' => 'attachments',
            'entity_id' => $attachment->id,
            'entity_identifier' => $attachment->file_name,
            'source' => 'Attachment',
        ]);

        return back()->with('success', 'Attachment deleted.');
    }

    private function fileType(?string $mime): string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            str_contains((string) $mime, 'spreadsheet') || str_contains((string) $mime, 'excel') || $mime === 'text/csv' => 'spreadsheet',
            str_contains((string) $mime, 'word') || $mime === 'application/msword' => 'document',
            default => 'other',
        };
    }
}
