#!/bin/sh

set -eu

data_dir=${DATA_DIR:-/data}
lock_file="$data_dir/.locker-client.lock"

umask 077
mkdir -p "$data_dir"
touch "$lock_file"
chmod 600 "$lock_file"

exec flock \
    --exclusive \
    --nonblock \
    --no-fork \
    --conflict-exit-code 75 \
    -- \
    "$lock_file" \
    "$@"
