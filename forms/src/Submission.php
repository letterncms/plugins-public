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
