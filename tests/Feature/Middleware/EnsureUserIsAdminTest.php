<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('middleware passes for Landlord user', function () {
    $admin = Landlord::factory()->create();

    $middleware = new EnsureUserIsAdmin;
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $admin);

    $response = $middleware->handle($request, fn () => new Response('success'));

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('success');
});

test('middleware aborts 403 for tenant User', function () {
    $user = User::factory()->make();

    $middleware = new EnsureUserIsAdmin;
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $middleware->handle($request, fn () => new Response('success'));
        $this->fail('Expected abort exception was not thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware aborts 403 for guest (no user)', function () {
    $middleware = new EnsureUserIsAdmin;
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);

    try {
        $middleware->handle($request, fn () => new Response('success'));
        $this->fail('Expected abort exception was not thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});
