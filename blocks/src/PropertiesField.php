<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Content\Fields\Blueprint;
use Cms\Content\Fields\Field;
use Cms\Content\Fields\FieldType;

/** Field definitions edited like a blueprint — a component's properties. */
final class PropertiesField extends FieldType
{
    public function key(): string { return 'properties'; }

    public function label(): string { return 'Properties'; }

    public function icon(): string { return 'field'; }

    public function description(): string { return 'A list of field definitions (like a collection’s fields), e.g. the properties of a component.'; }

    public function group(): string { return 'Structure'; }

    public function component(): string { return 'fields'; }

    public function schema(Field $field): array
    {
        return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string'], 'type' => ['type' => 'string'], 'label' => ['type' => 'string']], 'required' => ['handle', 'type']], 'description' => 'Field definitions: [{handle, type, label, default?, …}]'];
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        $value = array_values(array_filter((array) $value, 'is_array'));
        Blueprint::validate($value, $field->handle);

        return $value;
    }

    public function defaultValue(Field $field): mixed
    {
        return [];
    }
}
