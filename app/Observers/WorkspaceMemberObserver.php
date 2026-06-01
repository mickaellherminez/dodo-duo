<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\WorkspaceMember;
use App\Services\AuditService;

class WorkspaceMemberObserver
{
    public function created(WorkspaceMember $member): void
    {
        AuditService::log(AuditEvent::MEMBER_ADDED, $member, null, ['role' => $member->role]);
    }

    public function updated(WorkspaceMember $member): void
    {
        if ($member->isDirty('role')) {
            AuditService::log(
                AuditEvent::ROLE_CHANGED,
                $member,
                ['role' => $member->getOriginal('role')],
                ['role' => $member->role]
            );
        }
    }

    public function deleted(WorkspaceMember $member): void
    {
        AuditService::log(AuditEvent::MEMBER_REMOVED, $member, ['role' => $member->role], null);
    }

    public function restored(WorkspaceMember $member): void
    {
        AuditService::log(AuditEvent::MEMBER_ADDED, $member, null, ['role' => $member->role]);
    }
}
