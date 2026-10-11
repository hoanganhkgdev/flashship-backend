<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Self-pick release gate
    |--------------------------------------------------------------------------
    |
    | Keep this disabled during the first, system-assigned-only rollout. Even
    | if a stale database value says "self_pick", newly created orders still
    | snapshot "system_assigned" until this flag is deliberately enabled in a
    | later release.
    |
    */
    'self_pick_enabled' => env('DISPATCH_SELF_PICK_ENABLED', false),
];
