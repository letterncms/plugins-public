<?php

declare(strict_types=1);

namespace Cms\Import\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Cms\Abilities\AbilityException;
use Cms\Import\Uploads;

/** Takes a file from the admin into the import folder. Only for those who may import. */
final class UploadController
{
    public function __invoke(Request $request, Uploads $uploads): JsonResponse
    {
        if (! $request->user()?->hasPermission('import.run')) {
            return response()->json(['error' => ['type' => 'forbidden', 'message' => __('You may not import content.')]], 403);
        }

        $file = $request->file('file');

        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => ['type' => 'invalid_input', 'message' => $file?->getErrorMessage() ?? __('No file was uploaded.')]], 422);
        }

        try {
            return response()->json(['data' => $uploads->store($file->getRealPath(), $file->getClientOriginalName())], 201);
        } catch (AbilityException $e) {
            return response()->json(['error' => $e->toArray()], $e->status());
        } finally {
            @unlink((string) $file->getRealPath());
        }
    }
}
