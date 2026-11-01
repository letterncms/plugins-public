<?php

declare(strict_types=1);

namespace Cms\Forms\Http;

use Illuminate\Http\Request;
use Cms\Forms\Submission;
use Cms\Support\Files;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Uploaded files are private: only admins with forms.view can download them. */
final class FileController
{
    public function download(Request $request, string $id, int $index): BinaryFileResponse
    {
        abort_unless($request->user()?->hasPermission('forms.view'), 403);
        $submission = Submission::query()->findOrFail(strtolower($id));
        $file = ((array) $submission->files)[$index] ?? abort(404);
        $path = Files::within(storage_path('app'), (string) $file['path']);
        abort_unless(is_file($path), 404);

        return response()->download($path, (string) $file['name'], ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }
}
