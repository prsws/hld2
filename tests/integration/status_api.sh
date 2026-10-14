#!/usr/bin/env bash
# Integration test for the status API (contracts/status-api.md). TEST VM ONLY.
#
# Needs OPN_HOST, OPN_VIEW_KEY, OPN_VIEW_SECRET. If OPN_ADMIN_KEY/OPN_ADMIN_SECRET are also set,
# the test seeds two temporary hosts (one enabled, one disabled) and removes them at the end;
# otherwise it checks whatever hosts are already configured.

set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$DIR/lib.sh"

require_tools
refuse_production
require_env OPN_VIEW_KEY OPN_VIEW_SECRET
VIEW_KEY="$OPN_VIEW_KEY"
VIEW_SECRET="$OPN_VIEW_SECRET"

SEEDED=()
CAN_SEED=0
if [[ -n "${OPN_ADMIN_KEY:-}" && -n "${OPN_ADMIN_SECRET:-}" ]]; then
    CAN_SEED=1
    ADMIN_KEY="$OPN_ADMIN_KEY"
    ADMIN_SECRET="$OPN_ADMIN_SECRET"
fi

cleanup() {
    local uuid
    if [[ "$CAN_SEED" -eq 1 ]]; then
        for uuid in "${SEEDED[@]:-}"; do
            [[ -n "$uuid" ]] && del_host "$uuid"
        done
    fi
}
trap cleanup EXIT

echo "status API tests against $BASE_URL"

if [[ "$CAN_SEED" -eq 1 ]]; then
    add_host "cd-test-enabled" "127.0.0.1" "Zz-CdTest" 0 1
    SEEDED+=("$(jqv '.uuid')")
    assert_eq "seed enabled host saved" "saved" "$(jqv '.result')"
    add_host "cd-test-disabled" "127.0.0.1" "Zz-CdTest" 1 0
    SEEDED+=("$(jqv '.uuid')")
    assert_eq "seed disabled host saved" "saved" "$(jqv '.result')"
fi

echo "GET /api/casadelta/status/get"
call GET /api/casadelta/status/get "$VIEW_KEY" "$VIEW_SECRET"
assert_eq "get returns HTTP 200" "200" "$CODE"
assert_jq "response has summary and groups" '(.summary | type == "object") and (.groups | type == "array")'
assert_jq "summary has all documented fields" \
    '.summary | has("total_hosts","up_hosts","down_hosts","unresolved_hosts","pending_hosts","last_run","next_check_at","subsystem_status","subsystem_error")'
assert_jq "subsystem_status is ok" '.summary.subsystem_status == "ok"'
FIRST_LAST_RUN="$(jqv '.summary.last_run')"

assert_jq "groups are in display order (fixed groups first, then custom A-Z)" '
    def rank: ascii_downcase | if . == "network" then 0 elif . == "nodes" then 1 elif . == "apps" then 2 else 3 end;
    ([.groups[].name | [rank, (if rank == 3 then ascii_downcase else "" end)]]) as $k | $k == ($k | sort)'
assert_jq "hosts inside each group are ordered by sort_order then name" '
    [.groups[] | .hosts | map([.sort_order, (.name | ascii_downcase)]) | (. == sort)] | all'
assert_jq "latency_ms is non-null only for up hosts" \
    '[.groups[].hosts[] | select((.latency_ms != null) != (.state == "up"))] | length == 0'
assert_jq "no host is stale when the subsystem is ok" '[.groups[].hosts[] | select(.stale)] | length == 0'
assert_jq "total_hosts equals the number of listed hosts" \
    '.summary.total_hosts == ([.groups[].hosts[]] | length)'
assert_jq "response does not expose file paths" '(tostring | contains("/tmp/") | not) and (tostring | contains("/usr/") | not)'

if [[ "$CAN_SEED" -eq 1 ]]; then
    assert_jq "enabled seeded host is listed" '[.groups[].hosts[] | select(.name == "cd-test-enabled")] | length == 1'
    assert_jq "disabled seeded host is absent" '[.groups[].hosts[] | select(.name == "cd-test-disabled")] | length == 0'
fi

echo "two requests within 55 s do not change summary.last_run"
call GET /api/casadelta/status/get "$VIEW_KEY" "$VIEW_SECRET"
SECOND_LAST_RUN="$(jqv '.summary.last_run')"
call GET /api/casadelta/status/get "$VIEW_KEY" "$VIEW_SECRET"
THIRD_LAST_RUN="$(jqv '.summary.last_run')"
assert_eq "last_run unchanged between request 2 and 3" "$SECOND_LAST_RUN" "$THIRD_LAST_RUN"
if [[ "$FIRST_LAST_RUN" != "null" ]]; then
    assert_eq "last_run unchanged between request 1 and 2" "$FIRST_LAST_RUN" "$SECOND_LAST_RUN"
fi

echo "wrong HTTP methods run no check"
BEFORE="$(jqv '.summary.last_run')"
call GET /api/casadelta/status/refresh "$VIEW_KEY" "$VIEW_SECRET"
assert_eq "GET on refresh is rejected" '{"status":"failed"}' "$(printf '%s' "$BODY" | jq -c .)"
call POST /api/casadelta/status/get "$VIEW_KEY" "$VIEW_SECRET" '{}'
assert_eq "POST on get is rejected" '{"status":"failed"}' "$(printf '%s' "$BODY" | jq -c .)"
call GET /api/casadelta/status/get "$VIEW_KEY" "$VIEW_SECRET"
assert_eq "no check was executed by the rejected calls" "$BEFORE" "$(jqv '.summary.last_run')"

echo "POST /api/casadelta/status/refresh"
sleep 6   # let any 5 s cooldown from the requests above expire
call POST /api/casadelta/status/refresh "$VIEW_KEY" "$VIEW_SECRET" '{}'
assert_eq "refresh returns HTTP 200" "200" "$CODE"
assert_jq "refresh has performed and retry_after_s" \
    '(.refresh.performed | type == "boolean") and (.refresh.retry_after_s | type == "number")'
if [[ "$CAN_SEED" -eq 1 ]]; then
    assert_jq "first refresh after the cooldown performs a check" '.refresh.performed == true'
fi
assert_jq "refresh body also carries summary and groups" '(.summary | type == "object") and (.groups | type == "array")'

call POST /api/casadelta/status/refresh "$VIEW_KEY" "$VIEW_SECRET" '{}'
assert_jq "second refresh within 5 s is not performed" '.refresh.performed == false'
assert_jq "retry_after_s is between 1 and 5" '.refresh.retry_after_s >= 1 and .refresh.retry_after_s <= 5'

finish
