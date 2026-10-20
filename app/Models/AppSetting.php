<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'string',
        ];
    }

    /**
     * Get the setting value cast to its declared type.
     */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'json' => json_decode((string) $this->value, true),
            default => (string) $this->value,
        };
    }

    /**
     * Convenience accessor: get a setting value by key with optional default.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->typedValue() : $default;
    }

    /**
     * Convenience mutator: set a setting value by key (creates if missing).
     */
    public static function setValue(string $key, mixed $value, string $type = 'string', string $group = 'general'): static
    {
        $stored = $type === 'json' ? json_encode($value) : static::serializeValue($value);

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type, 'group' => $group]
        );
    }

    protected static function serializeValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_null($value)) {
            return null;
        }

        return (string) $value;
    }
}
