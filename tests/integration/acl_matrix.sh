#!/usr/bin/env bash
# Integration test for the ACL matrix (contracts/acl-menu.md). TEST VM ONLY.
#
# Needs OPN_HOST and four API key/secret pairs (read from the environment, never committed):
#   OPN_VIEW_KEY / OPN_VIEW_SECRET     only page-casadelta-status
#   OPN_ADMIN_KEY / OPN_ADMIN_SECRET   only page-casadelta-settings
#   OPN_BOTH_KEY / OPN_BOTH_SECRET     both privileges
#   OPN_NONE_KEY / OPN_NONE_SECRET     neither privilege
#
# The settings requests send harmless input: addHost with an invalid body fails validation
# and setHost/delHost/toggleHost use a non-existent UUID, so nothing is persisted. Requests
# that are allowed may return 200 with a failure body; only the HTTP status is asserted here.

set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$DIR/lib.sh"

require_tools
refuse_production
require_env OPN_VIEW_KEY OPN_VIEW_SECRET OPN_ADMIN_KEY OPN_ADMIN_SECRET \
    OPN_BOTH_KEY OPN_BOTH_SECRET OPN_NONE_KEY OPN_NONE_SECRET

NO_UUID="00000000-0000-0000-0000-000000000000"
INVALID_ADD='{"host":{"name":"","address":""}}'

# expect_code DESCRIPTION USER METHOD PATH EXPECTED [BODY]
expect_code() {
    local desc="$1" user="$2" method="$3" path="$4" expected="$5" data="${6-}"
    local key_var="OPN_${user}_KEY" secret_var="OPN_${user}_SECRET"
    call "$method" "$path" "${!key_var}" "${!secret_var}" "$data"
    assert_eq "$desc" "$expected" "$CODE"
}

# check_endpoint LABEL METHOD PATH VIEW SETTINGS BOTH NONE [BODY]
check_endpoint() {
    local label="$1" method="$2" path="$3" e_view="$4" e_admin="$5" e_both="$6" e_none="$7" data="${8-}"
    expect_code "$label (view only)" VIEW "$method" "$path" "$e_view" "$data"
    expect_code "$label (settings only)" ADMIN "$method" "$path" "$e_admin" "$data"
    expect_code "$label (both)" BOTH "$method" "$path" "$e_both" "$data"
    expect_code "$label (neither)" NONE "$method" "$path" "$e_none" "$data"
}

echo "ACL matrix against $BASE_URL"

# Status endpoints: 200 for view only and both, 403 for settings only and neither
check_endpoint "GET /ui/casadelta/status" GET /ui/casadelta/status 200 403 200 403
check_endpoint "GET /api/casadelta/status/get" GET /api/casadelta/status/get 200 403 200 403
check_endpoint "POST /api/casadelta/status/refresh" POST /api/casadelta/status/refresh 200 403 200 403 '{}'

# Settings endpoints: 403 for view only and neither, 200 for settings only and both
check_endpoint "GET /ui/casadelta/settings" GET /ui/casadelta/settings 403 200 200 403
check_endpoint "GET/POST searchHost" POST /api/casadelta/settings/searchHost 403 200 200 403 '{"current":1,"rowCount":1}'
check_endpoint "POST addHost" POST /api/casadelta/settings/addHost 403 200 200 403 "$INVALID_ADD"
check_endpoint "POST setHost" POST "/api/casadelta/settings/setHost/$NO_UUID" 403 200 200 403 "$INVALID_ADD"
check_endpoint "POST delHost" POST "/api/casadelta/settings/delHost/$NO_UUID" 403 200 200 403 '{}'
check_endpoint "POST toggleHost" POST "/api/casadelta/settings/toggleHost/$NO_UUID/0" 403 200 200 403 '{}'

# Explicit cross-privilege guarantees
echo "cross-privilege guarantees"
for action in searchHost getHost; do
    expect_code "view-only user gets 403 on settings/$action" VIEW GET "/api/casadelta/settings/$action" 403
done
expect_code "settings-only user gets 403 on status/get" ADMIN GET /api/casadelta/status/get 403
expect_code "settings-only user gets 403 on status/refresh" ADMIN POST /api/casadelta/status/refresh 403 '{}'

finish
