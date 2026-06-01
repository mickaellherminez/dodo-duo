<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Workspace Invitation</title>
    </head>
    <body>
        <p>You've been invited to join <strong>{{ $workspaceName }}</strong>.</p>
        <p>Invited by: {{ $inviterName }}</p>
        <p>Role: {{ ucfirst($role) }}</p>
        <p>Expires: {{ optional($expiresAt)->toDayDateTimeString() }}</p>
        <p>
            <a href="{{ $acceptUrl }}">Accept Invitation</a>
        </p>
        <p>
            <a href="{{ $declineUrl }}">Decline Invitation</a>
        </p>
    </body>
</html>
