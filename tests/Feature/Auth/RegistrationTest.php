<?php

use App\Models\User;

test('registration screen returns 404 not found', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('registration requests cannot register new users', function () {
    $userCountBefore = User::count();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::count())->toBe($userCountBefore);
});
