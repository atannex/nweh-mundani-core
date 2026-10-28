<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Persistence\Eloquent;

use Atannex\NwehMundani\Core\Governance\RevisionEntry;
use Atannex\NwehMundani\Core\Governance\Verification;
use Atannex\NwehMundani\Core\Governance\VerificationStatus;
use Atannex\NwehMundani\Core\Persistence\Contracts\AuditTrail;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\RevisionLogRecord;
use Atannex\NwehMundani\Core\Persistence\Eloquent\Models\VerificationRecord;

final class EloquentAuditTrail implements AuditTrail
{
    public function recordRevision(RevisionEntry $entry): void
    {
        RevisionLogRecord::query()->create([
            'tenant_key' => $entry->tenant,
            'subject_type' => $entry->subjectType,
            'subject_key' => $entry->subjectKey,
            'action' => $entry->action,
            'actor' => $entry->actor,
            'changes' => $entry->changes,
            'created_at' => $entry->at,
        ]);
    }

    public function recordVerification(Verification $verification): void
    {
        VerificationRecord::query()->create([
            'tenant_key' => $verification->tenant,
            'subject_type' => $verification->subjectType,
            'subject_key' => $verification->subjectKey,
            'status' => $verification->status->value,
            'actor' => $verification->actor,
            'notes' => $verification->notes,
            'created_at' => $verification->at,
        ]);
    }

    public function revisions(string $tenant, string $subjectType, string $subjectKey): array
    {
        return array_values(RevisionLogRecord::query()
            ->where(['tenant_key' => $tenant, 'subject_type' => $subjectType, 'subject_key' => $subjectKey])
            ->orderBy('id')->get()
            ->map(static fn (RevisionLogRecord $r): RevisionEntry => new RevisionEntry($r->tenant_key, $r->subject_type, $r->subject_key, $r->action, $r->actor, $r->changes ?? [], $r->created_at->toDateTimeImmutable()))
            ->all());
    }

    public function verifications(string $tenant, string $subjectType, string $subjectKey): array
    {
        return array_values(VerificationRecord::query()
            ->where(['tenant_key' => $tenant, 'subject_type' => $subjectType, 'subject_key' => $subjectKey])
            ->orderBy('id')->get()
            ->map(static fn (VerificationRecord $r): Verification => new Verification($r->tenant_key, $r->subject_type, $r->subject_key, VerificationStatus::from($r->status), $r->actor, $r->notes, $r->created_at->toDateTimeImmutable()))
            ->all());
    }
}
