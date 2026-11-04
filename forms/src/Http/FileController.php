<?php

declare(strict_types=1);

namespace Cms\Forms\Http;

use Illuminate\Http\Request;
use Cms\Forms\FormRepository;
use Cms\Forms\Submission;
use Cms\Support\Files;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Uploaded files and exports are private: only admins with forms.view can download them. */
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

    /** A form's submissions as a CSV file, the way a spreadsheet opens them. */
    public function export(Request $request, FormRepository $forms, string $handle): Response
    {
        abort_unless($request->user()?->hasPermission('forms.view'), 403);
        $form = $forms->find($handle) ?? abort(404);
        // The column headings in the language of the person downloading.
        $words = app(\Cms\Admin\Translations::class);
        $words->apply($words->locale($request->user(), $request));

        return response(Submission::csv($form), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$form['handle'].'-'.date('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
