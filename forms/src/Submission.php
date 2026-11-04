<?php

declare(strict_types=1);

namespace Cms\Forms;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Cms\Support\RecordTimestamps;

class Submission extends Model
{
    use HasUlids;

    protected $table = 'form_submissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'files' => 'array', 'meta' => 'array'];
    }

    /** A form's submissions as CSV, oldest first and without spam: one column per field. */
    public static function csv(array $form): string
    {
        $fields = FormFields::extract($form['fields']);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, [__('Submitted'), __('Status'), ...array_map(static fn ($f) => strip_tags((string) ($f['props']['label'] ?? $f['name'])), $fields)], escape: '\\');
        // Guard against spreadsheet formula injection.
        $cell = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v;

        foreach (self::query()->where('form', $form['handle'])->where('status', '!=', 'spam')->orderBy('created_at')->cursor() as $s) {
            fputcsv($out, [$s->created_at?->toDateTimeString(), $s->status, ...array_map(static fn ($f) => $cell(is_array($s->data[$f['name']] ?? null) ? implode(', ', $s->data[$f['name']]) : ($s->data[$f['name']] ?? '')), $fields)], escape: '\\');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'form' => $this->form,
            'data' => $this->data,
            'files' => array_map(static fn ($f) => ['field' => $f['field'], 'name' => $f['name'], 'size' => $f['size'], 'mime' => $f['mime']], (array) $this->files),
            'meta' => array_intersect_key((array) $this->meta, array_flip(['page', 'locale', 'user_agent'])),
            'status' => $this->status,
            'createdAt' => $this->created_at?->toIso8601String(),
            ...RecordTimestamps::api(['published_at' => null, 'updated_at' => $this->updated_at]),
        ];
    }
}
