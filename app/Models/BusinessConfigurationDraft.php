<?php

namespace App\Models;

use Database\Factories\BusinessConfigurationDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $business_profile_id
 * @property int|null $actor_user_id
 * @property int $revision
 * @property int $base_version
 * @property array<string, mixed>|null $patch
 * @property array<string, mixed>|null $preview
 * @property string $status
 */
#[Fillable(['business_profile_id', 'actor_user_id', 'revision', 'base_version', 'patch', 'preview', 'status'])]
class BusinessConfigurationDraft extends Model
{
    /** @use HasFactory<BusinessConfigurationDraftFactory> */
    use HasFactory;

    protected $hidden = ['patch', 'preview'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'patch' => 'encrypted:array',
            'preview' => 'encrypted:array',
            'revision' => 'integer',
            'base_version' => 'integer',
        ];
    }
}
