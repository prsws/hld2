#!/usr/bin/env bash
# Integration test for the settings API (contracts/settings-api.md). TEST VM ONLY.
# Needs OPN_HOST, OPN_ADMIN_KEY, OPN_ADMIN_SECRET (user with page-casadelta-settings).
# Every host created here is removed again, also when an assertion fails.

set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$DIR/lib.sh"

require_tools
refuse_production
require_env OPN_ADMIN_KEY OPN_ADMIN_SECRET
ADMIN_KEY="$OPN_ADMIN_KEY"
ADMIN_SECRET="$OPN_ADMIN_SECRET"

CREATED=()
cleanup() {
    local uuid
    for uuid in "${CREATED[@]:-}"; do
        [[ -n "$uuid" ]] && del_host "$uuid"
    done
}
trap cleanup EXIT

echo "settings API tests against $BASE_URL"

echo "add / search / get / set"
add_host "cd-test-main" "192.168.1.10:8006" "Nodes" 10 1
UUID="$(jqv '.uuid')"
CREATED+=("$UUID")
assert_eq "addHost with valid data returns result=saved" "saved" "$(jqv '.result')"
assert_jq "addHost returns a UUID" '(.uuid | type == "string") and (.uuid | length > 0)'

call POST /api/casadelta/settings/searchHost "$ADMIN_KEY" "$ADMIN_SECRET" '{"current":1,"rowCount":-1,"searchPhrase":"cd-test-main"}'
assert_jq "searchHost lists the new host" "[.rows[] | select(.uuid == \"$UUID\")] | length == 1"

call GET "/api/casadelta/settings/getHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "getHost returns the stored name" '.host.name == "cd-test-main"'
assert_jq "getHost returns the stored address" '.host.address == "192.168.1.10:8006"'
assert_jq "getHost returns the stored sort_order" '.host.sort_order == "10"'

call GET /api/casadelta/settings/getHost "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "getHost without UUID returns enabled=1 default" '.host.enabled == "1"'
assert_jq "getHost without UUID returns group=Network default" \
    '(.host.group | if type == "object" then (to_entries | map(select(.value.selected == 1)) | .[0].key) else . end) == "Network"'
assert_jq "getHost without UUID returns sort_order=0 default" '.host.sort_order == "0"'

call POST "/api/casadelta/settings/setHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET" \
    '{"host":{"enabled":"1","name":"cd-test-main","address":"pve.lan:8006","group":"Nodes","sort_order":"20","description":"edited"}}'
assert_eq "setHost updates the host" "saved" "$(jqv '.result')"
call GET "/api/casadelta/settings/getHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "setHost changed the address" '.host.address == "pve.lan:8006"'
assert_jq "setHost changed the sort_order" '.host.sort_order == "20"'

echo "toggle"
call POST "/api/casadelta/settings/toggleHost/$UUID/0" "$ADMIN_KEY" "$ADMIN_SECRET" '{}'
assert_eq "toggleHost/0 disables" "Disabled" "$(jqv '.result')"
call GET "/api/casadelta/settings/getHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "host is stored as disabled" '.host.enabled == "0"'
call POST "/api/casadelta/settings/toggleHost/$UUID/1" "$ADMIN_KEY" "$ADMIN_SECRET" '{}'
assert_eq "toggleHost/1 enables" "Enabled" "$(jqv '.result')"
call GET "/api/casadelta/settings/getHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "host is stored as enabled" '.host.enabled == "1"'

echo "mutating calls via GET change nothing"
call GET /api/casadelta/settings/addHost "$ADMIN_KEY" "$ADMIN_SECRET"
assert_eq "addHost via GET fails" "failed" "$(jqv '.result')"
call GET "/api/casadelta/settings/delHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_eq "delHost via GET fails" "failed" "$(jqv '.result')"
call GET "/api/casadelta/settings/getHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "host still exists after the GET attempts" '.host.name == "cd-test-main"'

echo "validation"
add_host "cd-test-main" "192.168.1.11" "Nodes" 0 1
assert_eq "duplicate name is rejected" "failed" "$(jqv '.result')"
assert_eq "duplicate name message" "A host with this display name already exists." "$(jqv '.validations["host.name"]')"

expect_address_rejected() {
    local address="$1" message="$2"
    add_host "cd-test-bad" "$address" "Network" 0 1
    [[ "$(jqv '.result')" == "saved" ]] && CREATED+=("$(jqv '.uuid')")
    assert_eq "address '$address' is rejected" "failed" "$(jqv '.result')"
    assert_eq "address '$address' message" "$message" "$(jqv '.validations["host.address"]')"
}
expect_address_rejected "192.168.1" "Invalid IPv4 address."
expect_address_rejected "pve.lan:0" "Port must be between 1 and 65535."
expect_address_rejected "pve.lan:65536" "Port must be between 1 and 65535."
expect_address_rejected "pve.lan:08006" "Port must be between 1 and 65535."

expect_address_accepted() {
    local address="$1"
    add_host "cd-test-ok-$RANDOM" "$address" "Network" 0 1
    local result uuid
    result="$(jqv '.result')"
    uuid="$(jqv '.uuid')"
    [[ "$result" == "saved" ]] && CREATED+=("$uuid")
    assert_eq "address '$address' is accepted" "saved" "$result"
}
expect_address_accepted "192.168.1.10:8006"
expect_address_accepted "pve.lan:8006"
expect_address_accepted "[2001:db8::1]:8006"

echo "sort_order default"
add_host "cd-test-nosort" "192.168.1.12" "Network" "-" 1
NOSORT_UUID="$(jqv '.uuid')"
CREATED+=("$NOSORT_UUID")
assert_eq "host without sort_order is saved" "saved" "$(jqv '.result')"
call GET "/api/casadelta/settings/getHost/$NOSORT_UUID" "$ADMIN_KEY" "$ADMIN_SECRET"
assert_jq "missing sort_order is stored as 0" '.host.sort_order == "0"'

echo "delete"
call POST "/api/casadelta/settings/delHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET" '{}'
assert_eq "delHost removes the host" "deleted" "$(jqv '.result')"
call POST "/api/casadelta/settings/delHost/$UUID" "$ADMIN_KEY" "$ADMIN_SECRET" '{}'
assert_eq "delHost on an unknown UUID reports not found" "not found" "$(jqv '.result')"

finish
