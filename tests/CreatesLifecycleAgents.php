<?php

namespace Tests;

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

trait CreatesLifecycleAgents
{
    /** @return array{User, AgentProfile} */
    protected function createAgentLifecycleFixture(): array
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $admin->givePermissionTo(AdminPermission::AgentsManage);
        $agent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);

        return [$admin, $agent];
    }

    /** @return array<string, mixed> */
    protected function agentLifecyclePayload(AgentProfile $agent): array
    {
        $case = $agent->offboardingCases()->latest('id')->first();

        return ['attempt_reference' => (string) Str::uuid(), 'version' => $agent->fresh()->version,
            'case_id' => $case?->id, 'case_version' => $case?->version, 'confirmed' => true,
            'reason' => 'Private management review.', 'agent_explanation' => 'Contact your manager for the next step.'];
    }

    /** @return array<string, int> */
    protected function agentLifecycleFreshSession(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.fresh_until' => now()->addMinutes(10)->timestamp];
    }

    protected function agentLifecycleRequest(User $actor): Request
    {
        $request = Request::create('/agents/lifecycle', 'POST');
        $session = new Store('agent_lifecycle_test', new ArraySessionHandler(120));
        $session->start();
        $session->put($this->agentLifecycleFreshSession());
        $request->setLaravelSession($session);
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
