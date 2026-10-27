<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('help'))->assertRedirect(route('login'));
});

test('agents and admins can view the help page', function (string $userType) {
    $user = User::factory()->{$userType}()->create();

    $this->actingAs($user)
        ->get(route('help'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('help/Index'));
})->with(['agent', 'admin']);

test('customers cannot view the help page', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('help'))->assertForbidden();
});
