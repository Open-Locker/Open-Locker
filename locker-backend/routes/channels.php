<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('users.{id}.compartment-status', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Account-level state the app caches and cannot otherwise learn has changed —
// terms acceptance today. Kept apart from compartment-status so a channel name
// keeps meaning what it says.
Broadcast::channel('users.{id}.account', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
