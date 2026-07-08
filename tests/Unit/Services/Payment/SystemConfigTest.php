<?php

use App\Models\SystemConfig;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('get returns default value when key does not exist', function () {
    $result = SystemConfig::get('nonexistent.key', 'default_value');

    expect($result)->toBe('default_value');
});

test('get returns stored value when key exists', function () {
    SystemConfig::create([
        'group' => 'test',
        'key' => 'test.key',
        'value' => 'test_value',
        'type' => 'string',
    ]);

    $result = SystemConfig::get('test.key');

    expect($result)->toBe('test_value');
});

test('get reads from cache on subsequent calls', function () {
    SystemConfig::create([
        'group' => 'test',
        'key' => 'test.cached',
        'value' => 'original',
        'type' => 'string',
    ]);

    // First call populates cache
    $first = SystemConfig::get('test.cached');
    expect($first)->toBe('original');

    // Update DB directly (bypassing model)
    SystemConfig::where('key', 'test.cached')->update(['value' => ['updated']]);

    // Cache should still return old value
    $second = SystemConfig::get('test.cached');
    expect($second)->toBe('original');
});

test('set creates new config when key does not exist', function () {
    SystemConfig::set('new.key', 'new_value');

    $config = SystemConfig::where('key', 'new.key')->first();
    expect($config)->not->toBeNull();
    expect($config->value)->toBe('new_value');
    expect($config->group)->toBe('new');
});

test('set updates existing config', function () {
    SystemConfig::create([
        'group' => 'test',
        'key' => 'test.update',
        'value' => 'old_value',
        'type' => 'string',
    ]);

    SystemConfig::set('test.update', 'new_value');

    $config = SystemConfig::where('key', 'test.update')->first();
    expect($config->value)->toBe('new_value');
});

test('set invalidates cache', function () {
    SystemConfig::create([
        'group' => 'test',
        'key' => 'test.invalidate',
        'value' => 'original',
        'type' => 'string',
    ]);

    // Populate cache
    SystemConfig::get('test.invalidate');

    // Update
    SystemConfig::set('test.invalidate', 'updated');

    // Should get new value
    $result = SystemConfig::get('test.invalidate');
    expect($result)->toBe('updated');
});

test('save invalidates cache', function () {
    $config = SystemConfig::create([
        'group' => 'test',
        'key' => 'test.save',
        'value' => 'original',
        'type' => 'string',
    ]);

    // Populate cache
    SystemConfig::get('test.save');

    // Update via save
    $config->value = 'updated';
    $config->save();

    // Should get new value
    $result = SystemConfig::get('test.save');
    expect($result)->toBe('updated');
});

test('shadow_mode_channels accepts valid channel list', function () {
    SystemConfig::set('reconciliation.shadow_mode_channels', ['bank-app'], 'json');

    $result = SystemConfig::get('reconciliation.shadow_mode_channels');
    expect($result)->toBe(['bank-app']);
});

test('shadow_mode_channels accepts empty list', function () {
    SystemConfig::set('reconciliation.shadow_mode_channels', [], 'json');

    $result = SystemConfig::get('reconciliation.shadow_mode_channels');
    expect($result)->toBe([]);
});

test('shadow_mode_channels rejects invalid channel', function () {
    SystemConfig::set('reconciliation.shadow_mode_channels', ['invalid'], 'json');
})->throws(InvalidArgumentException::class, 'Invalid channel(s)');

test('shadow_mode_channels rejects non-array value', function () {
    SystemConfig::set('reconciliation.shadow_mode_channels', 'not-an-array', 'json');
})->throws(InvalidArgumentException::class, 'must be a JSON array');

test('shadow_mode_channels validation fires on direct create too', function () {
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.shadow_mode_channels',
        'value' => json_encode(['bad-channel']),
        'type' => 'json',
    ]);
})->throws(InvalidArgumentException::class, 'Invalid channel(s)');

test('detectType returns correct types', function () {
    SystemConfig::set('type.string', 'hello');
    SystemConfig::set('type.integer', 42);
    SystemConfig::set('type.boolean', true);
    SystemConfig::set('type.json', ['key' => 'value']);

    expect(SystemConfig::where('key', 'type.string')->first()->type)->toBe('string');
    expect(SystemConfig::where('key', 'type.integer')->first()->type)->toBe('integer');
    expect(SystemConfig::where('key', 'type.boolean')->first()->type)->toBe('boolean');
    expect(SystemConfig::where('key', 'type.json')->first()->type)->toBe('json');
});
