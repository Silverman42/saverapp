<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the homepage renders the login page for guests', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

test('the homepage redirects authenticated users away from the login page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertRedirect();
});
