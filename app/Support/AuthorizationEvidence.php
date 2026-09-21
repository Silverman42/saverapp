<?php

namespace App\Support;

use App\Enums\AdminPermission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * Immutable authorization evidence value object.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class AuthorizationEvidence implements Arrayable
{
    public CarbonInterface $decidedAt;

    public function __construct(
        public int $actorId,
        public AdminPermission $permission,
        public int $permissionVersion,
        public ?string $subjectType = null,
        public string|int|null $subjectId = null,
        public ?int $subjectVersion = null,
        ?CarbonInterface $decidedAt = null,
    ) {
        $this->decidedAt = $decidedAt ?? CarbonImmutable::now();
    }

    /**
     * Convert the authorization evidence to a stable array shape.
     *
     * @return array{
     *     actor_id: int,
     *     permission: string,
     *     permission_version: int,
     *     subject_type: string|null,
     *     subject_id: string|null,
     *     subject_version: int|null,
     *     decided_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId,
            'permission' => $this->permission->value,
            'permission_version' => $this->permissionVersion,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId !== null ? (string) $this->subjectId : null,
            'subject_version' => $this->subjectVersion,
            'decided_at' => $this->decidedAt->toISOString(),
        ];
    }

    /**
     * Create an AuthorizationEvidence instance from serialized array data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['actor_id'], $data['permission'], $data['permission_version'])) {
            throw new InvalidArgumentException('Missing required fields for AuthorizationEvidence fromArray.');
        }

        $permission = $data['permission'] instanceof AdminPermission
            ? $data['permission']
            : AdminPermission::from((string) $data['permission']);

        $decidedAt = isset($data['decided_at'])
            ? CarbonImmutable::parse($data['decided_at'])
            : null;

        return new self(
            actorId: (int) $data['actor_id'],
            permission: $permission,
            permissionVersion: (int) $data['permission_version'],
            subjectType: isset($data['subject_type']) ? (string) $data['subject_type'] : null,
            subjectId: isset($data['subject_id']) ? (string) $data['subject_id'] : null,
            subjectVersion: isset($data['subject_version']) ? (int) $data['subject_version'] : null,
            decidedAt: $decidedAt,
        );
    }
}
