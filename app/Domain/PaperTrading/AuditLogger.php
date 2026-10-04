<?php

namespace App\Domain\PaperTrading;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    public function record(
        string $action,
        ?Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $requestId = null,
        array $metadata = [],
        ?int $actorId = null,
    ): void {
        AuditLog::query()->create([
            'actor_type' => $actorId === null ? 'system' : 'user',
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'request_id' => $requestId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
