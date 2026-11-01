<?php

declare(strict_types=1);

namespace Cms\Media\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Cms\Abilities\AbilityException;
use Cms\Media\MediaItem;
use Cms\Media\MediaStore;
use Cms\Media\Variants;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class MediaController
{
    /** Multipart upload (admin session or API token). */
    public function upload(Request $request, MediaStore $store): JsonResponse
    {
        $user = $request->user();

        if (! $user?->hasPermission('media.upload')) {
            return response()->json(['error' => ['type' => 'forbidden', 'message' => 'You may not upload files.']], 403);
        }

        $file = $request->file('file');

        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => ['type' => 'invalid_input', 'message' => $file?->getErrorMessage() ?? __('No file was uploaded.')]], 422);
        }

        $tmp = storage_path('app/uploads/'.bin2hex(random_bytes(12)));
        @mkdir(dirname($tmp), 0o775, true);
        $file->move(dirname($tmp), basename($tmp));

        try {
            $item = $store->store($tmp, $file->getClientOriginalName(), $user, (string) $request->input('folder', ''));
        } catch (AbilityException $e) {
            return response()->json(['error' => $e->toArray()], $e->status());
        }

        cms()->hooks()->dispatch('media.uploaded', $item, $user);

        return response()->json(['data' => $item->toPublic()], 201);
    }

    /** Generate an image variant on first request; it is a static file afterwards. */
    public function variant(string $id, string $file, Variants $variants): Response
    {
        abort_unless(preg_match('/^(\d{2,4})(?:-(\d{1,2})x(\d{1,2}))?-([0-9a-f]{8})\.(avif|webp|jpg|png)$/', $file, $m), 404);

        $item = MediaItem::query()->find(strtolower($id));
        $width = (int) $m[1];
        $ratio = $m[2] !== '' ? "{$m[2]}x{$m[3]}" : null;

        abort_if(! $item || ! $item->isImage() || $m[4] !== $variants->version($item), 404);
        abort_unless(in_array($width, [...Variants::WIDTHS, ...$variants->widths($item)], true), 404);
        abort_unless($variants->ensure($item, $width, $m[5], $ratio), 404);

        return new BinaryFileResponse($variants->path($item, $width, $m[5], $ratio), 200, [
            'Content-Type' => 'image/'.($m[5] === 'jpg' ? 'jpeg' : $m[5]),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
