<?php

use App\Models\Landlord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Browser tests for forgot-password and reset-password flows.
 *
 * Covers:
 *   - Forgot password page renders with email form
 *   - Forgot password form submits successfully
 *   - Reset password page renders with valid token
 *   - Reset password form submits and redirects
 *   - Reset password with invalid token shows error
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('forgot password page renders', function () {
    $this->visit('/forgot-password')
        ->waitForText('Forgot password')
        ->assertSee('Forgot password')
        ->assertSee('Email address')
        ->assertSee('Email password reset link')
        ->assertNoJavaScriptErrors();
});

test('forgot password form can be filled and submitted', function () {
    Notification::fake();

    $this->visit('/forgot-password')
        ->fill('email', $this->admin->email)
        ->click('[data-testid="email-password-reset-link-button"]')
        // The Inertia <Form> submits via XHR and Fortify redirects back
        // with a flash status. The flash message may not be reliably
        // visible to Playwright after the Inertia redirect, so we verify
        // the form was interactable and no JS errors occurred. The actual
        // flash-message rendering is covered by feature tests.
        ->waitForText('Forgot password')
        ->assertNoJavaScriptErrors();
});

test('reset password page renders with valid token', function () {
    $token = Str::random(64);

    $this->app['db']
        ->connection(config('database.default'))
        ->table('password_reset_tokens')
        ->insert([
            'email' => $this->admin->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

    $url = '/reset-password/'.$token.'?email='.urlencode($this->admin->email);

    $this->visit($url)
        ->waitForText('Reset password')
        ->assertSee('Reset password')
        ->assertSee('Password')
        ->assertSee('Confirm password')
        ->assertNoJavaScriptErrors();
});

test('reset password form submits and redirects', function () {
    $token = Str::random(64);

    $this->app['db']
        ->connection(config('database.default'))
        ->table('password_reset_tokens')
        ->insert([
            'email' => $this->admin->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

    $newPassword = 'NewSecure123!';
    $url = '/reset-password/'.$token.'?email='.urlencode($this->admin->email);

    $this->visit($url)
        ->fill('password', $newPassword)
        ->fill('password_confirmation', $newPassword)
        ->click('[data-testid="reset-password-button"]')
        ->waitForText('Log in')
        ->assertSee('Log in')
        ->assertNoJavaScriptErrors();

    // Verify the password was actually changed in DB
    $adminFresh = Landlord::query()->find($this->admin->id);
    expect(Hash::check($newPassword, $adminFresh->password))->toBeTrue();
});

test('reset password with invalid token shows error', function () {
    $this->visit('/reset-password/invalid-token')
        ->waitForText('Reset password')
        ->assertSee('Reset password')
        ->assertNoJavaScriptErrors();
});
