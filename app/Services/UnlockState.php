<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class UnlockState
{
    public function token(User $target, User $actor, string $category): string
    {
        return Crypt::encryptString(json_encode(['actor' => $actor->id, 'permission_version' => $actor->permission_version,
            'target' => $target->id, 'category' => $category, 'state' => $this->fingerprint($target, $category), 'expires' => now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function verify(string $token, User $target, User $actor, string $category): void
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ConflictHttpException('The restriction changed. Refresh before unlocking.');
        }
        if (($data['actor'] ?? null) !== $actor->id || ($data['permission_version'] ?? null) !== $actor->permission_version
            || ($data['target'] ?? null) !== $target->id || ($data['category'] ?? null) !== $category || ($data['expires'] ?? 0) < now()->timestamp
            || ! hash_equals($data['state'] ?? '', $this->fingerprint($target, $category))) {
            throw new ConflictHttpException('The restriction changed. Refresh before unlocking.');
        }
    }

    private function fingerprint(User $target, string $category): string
    {
        $locks = DB::table('authentication_locks')->where('lock_category', $category)
            ->where(fn ($q) => $q->where('user_id', $target->id)->orWhere('email_normalized', $target->email_normalized))
            ->whereNull('unlocked_at')->where('locked_until', '>', now())->orderBy('id')->get(['id', 'locked_until', 'failed_attempts_count']);

        return hash('sha256', json_encode([$target->id, $target->account_state->value, $target->permission_version,
            $target->lock_category, $target->locked_until?->toISOString(), $locks->toArray()], JSON_THROW_ON_ERROR));
    }
}
