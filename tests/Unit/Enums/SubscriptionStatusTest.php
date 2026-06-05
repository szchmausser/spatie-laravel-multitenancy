<?php

use App\Enums\SubscriptionStatus;

test('subscription status enum has correct cases', function () {
    expect(SubscriptionStatus::Active->value)->toBe('active');
    expect(SubscriptionStatus::Trialing->value)->toBe('trialing');
    expect(SubscriptionStatus::Cancelled->value)->toBe('cancelled');
    expect(SubscriptionStatus::Expired->value)->toBe('expired');
});

test('subscription status enum label returns human-readable string', function () {
    expect(SubscriptionStatus::Active->label())->toBe('Active');
    expect(SubscriptionStatus::Trialing->label())->toBe('Trialing');
    expect(SubscriptionStatus::Cancelled->label())->toBe('Cancelled');
    expect(SubscriptionStatus::Expired->label())->toBe('Expired');
});

test('subscription status enum isActive returns true only for active', function () {
    expect(SubscriptionStatus::Active->isActive())->toBeTrue();
    expect(SubscriptionStatus::Trialing->isActive())->toBeFalse();
    expect(SubscriptionStatus::Cancelled->isActive())->toBeFalse();
    expect(SubscriptionStatus::Expired->isActive())->toBeFalse();
});

test('subscription status enum isTrialing returns true only for trialing', function () {
    expect(SubscriptionStatus::Trialing->isTrialing())->toBeTrue();
    expect(SubscriptionStatus::Active->isTrialing())->toBeFalse();
    expect(SubscriptionStatus::Cancelled->isTrialing())->toBeFalse();
    expect(SubscriptionStatus::Expired->isTrialing())->toBeFalse();
});

test('subscription status enum isCancelled returns true only for cancelled', function () {
    expect(SubscriptionStatus::Cancelled->isCancelled())->toBeTrue();
    expect(SubscriptionStatus::Active->isCancelled())->toBeFalse();
    expect(SubscriptionStatus::Trialing->isCancelled())->toBeFalse();
    expect(SubscriptionStatus::Expired->isCancelled())->toBeFalse();
});

test('subscription status enum isExpired returns true only for expired', function () {
    expect(SubscriptionStatus::Expired->isExpired())->toBeTrue();
    expect(SubscriptionStatus::Active->isExpired())->toBeFalse();
    expect(SubscriptionStatus::Trialing->isExpired())->toBeFalse();
    expect(SubscriptionStatus::Cancelled->isExpired())->toBeFalse();
});
