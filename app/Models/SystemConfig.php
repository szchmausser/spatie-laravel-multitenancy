<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class SystemConfig extends Model
{
    use UsesLandlordConnection;

    protected $fillable = ['group', 'key', 'value', 'type', 'description'];

    /**
     * Cast value based on type metadata.
     */
    public function getValue(): string|int|bool|array
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Get a configuration value by key.
     *
     * Sentinel pattern: avoids the has/get race condition.
     * Only caches values that exist in DB. Default is applied outside cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = "system_config.{$key}";
        $sentinel = '__CACHE_MISS__';

        $cached = Cache::get($cacheKey, $sentinel);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $config = static::where('key', $key)->first();

        if ($config) {
            $value = $config->getValue();
            Cache::put($cacheKey, $value, 3600);

            return $value;
        }

        return $default;
    }

    /**
     * Set a configuration value by key.
     */
    public static function set(string $key, mixed $value, string $type = 'string'): static
    {
        $group = explode('.', $key)[0];

        // Auto-detect type from PHP value when passed as default 'string'
        // but the value is not a string (caller didn't specify an explicit type)
        if ($type === 'string' && ! is_string($value)) {
            $type = match (true) {
                is_int($value) => 'integer',
                is_bool($value) => 'boolean',
                is_array($value) => 'json',
                default => 'string',
            };
        }

        // Normalize: if value is already a JSON string, decode before re-encoding
        // to prevent double-encoding when the controller/form sends serialized JSON.
        if ($type === 'json' && is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        $stored = match ($type) {
            'json' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };

        $record = static::updateOrCreate(
            ['key' => $key],
            ['group' => $group, 'value' => $stored, 'type' => $type]
        );
        Cache::forget("system_config.{$key}");

        return $record;
    }

    /**
     * Save the model and invalidate cache.
     */
    public function save(array $options = []): bool
    {
        $result = parent::save($options);

        if ($result) {
            Cache::forget("system_config.{$this->key}");
        }

        return $result;
    }

    /**
     * Boot the model — validate regex configs on saving.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (SystemConfig $config) {
            // Validate shadow_mode_channels — only known SourceType values allowed
            if ($config->key === 'reconciliation.shadow_mode_channels') {
                $channels = json_decode($config->value, true);

                if (! is_array($channels)) {
                    throw new \InvalidArgumentException(
                        'The value for [reconciliation.shadow_mode_channels] must be a JSON array of channel names.'
                    );
                }

                $validValues = SourceType::values();
                $invalid = array_diff($channels, $validValues);

                if (! empty($invalid)) {
                    throw new \InvalidArgumentException(
                        'Invalid channel(s) in [reconciliation.shadow_mode_channels]: '.implode(', ', $invalid)
                        .'. Allowed: '.implode(', ', $validValues)
                    );
                }
            }

            // Validate regex configs (keys starting with regex_)
            if (str_starts_with($config->key, 'regex_')) {
                $regex = is_array($config->value) ? ($config->value['pattern'] ?? null) : $config->value;

                if ($regex !== null) {
                    // Test that regex compiles
                    @preg_match($regex, 'test', $matches);
                    if (preg_last_error() !== PREG_NO_ERROR) {
                        throw new \InvalidArgumentException("Invalid regex pattern for key [{$config->key}]: {$regex}");
                    }

                    // Check for required named groups
                    $requiredGroups = ['amount', 'reference'];
                    preg_match_all('/\(\?<(\w+)>/', $regex, $namedGroups);
                    $foundGroups = $namedGroups[1] ?? [];
                    $missing = array_diff($requiredGroups, $foundGroups);
                    if (! empty($missing)) {
                        throw new \InvalidArgumentException(
                            'Regex pattern for key ['.$config->key.'] is missing required named groups: '.implode(', ', $missing)
                        );
                    }
                }
            }
        });
    }
}
