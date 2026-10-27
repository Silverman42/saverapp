<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    /**
     * Show the Help and FAQ manual for Agents and Admins.
     */
    public function __invoke(Request $request): Response
    {
        abort_unless(in_array($request->user()->user_type, [UserType::Agent, UserType::Admin], true), 403);

        return Inertia::render('help/Index');
    }
}
