<?php

namespace App\Enums;

final class AuditEvent
{
    const MEMBER_INVITED = 'member.invited';

    const MEMBER_ADDED = 'member.added';

    const MEMBER_REMOVED = 'member.removed';

    const ROLE_CHANGED = 'role.changed';

    const WORKSPACE_CREATED = 'workspace.created';

    const WORKSPACE_UPDATED = 'workspace.updated';

    const WORKSPACE_DELETED = 'workspace.deleted';

    const ADMIN_DASHBOARD_VIEWED = 'admin.dashboard.viewed';

    private function __construct() {}
}
