#!/usr/bin/env bash
set -euo pipefail

# Runs entirely in an unprivileged, isolated network namespace. Host networking
# and qdiscs are never modified. Only browser->audio.php TCP/8889 is impaired;
# local UDP/9966 remains clean so the measured RTP gaps expose server pacing.
run_profile() {
    local profile_name=$1
    shift
    local netem_args="$*"
    echo "PROFILE ${profile_name} netem=${netem_args}"
    PROFILE_ARGS="$netem_args" unshare -Urn bash -c '
        set -e
        ip link set lo up
        tc qdisc add dev lo root handle 1: prio
        tc qdisc add dev lo parent 1:3 handle 30: netem $PROFILE_ARGS
        tc filter add dev lo protocol ip parent 1: prio 1 u32 \
            match ip protocol 6 0xff match ip dport 8889 0xffff flowid 1:3
        php tests/real_mic_uplink_burst.php
        tc -s qdisc show dev lo | tail -4
    '
}

run_profile A delay 50ms 10ms distribution normal
run_profile B delay 100ms 30ms distribution normal
run_profile C delay 150ms 60ms distribution normal
run_profile D delay 250ms 100ms distribution normal
run_profile E slot 200ms 500ms
