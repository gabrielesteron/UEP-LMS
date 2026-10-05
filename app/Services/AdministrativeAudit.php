<?php

namespace App\Services;

use App\Models\AdministrativeAuditLog;
use App\Models\User;

class AdministrativeAudit
{
    public static function record(?User $actor, string $action, string $type, ?int $id = null, array $metadata = []): void
    {
        // Only explicit non-sensitive fields may enter the administrative history.
        $metadata = array_intersect_key($metadata, array_flip(['old_role', 'new_role', 'old_status', 'new_status', 'changed_fields', 'key', 'before', 'after', 'source']));
        AdministrativeAuditLog::create(['actor_id' => $actor?->id, 'action' => $action, 'target_type' => $type, 'target_id' => $id, 'metadata' => $metadata]);
    }
}
