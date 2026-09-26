<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AuditIdentityConflict extends ConflictHttpException
{
    public function __construct(public string $targetType, public ?int $targetId, public string $operationKey, public ?int $actorId)
    {
        parent::__construct('Audit operation identity conflicts.');
    }
}
