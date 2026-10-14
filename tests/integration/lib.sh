#!/usr/bin/env bash
# Shared helpers for the integration tests. Source this file; do not run it.
#
# Runs against the TEST VM only (Principle VIII). Credentials come from the environment and are
# never stored in the repository or copied to the firewall:
#   OPN_HOST                       host name or IP of the test VM (required)
#   OPN_BASE_URL                   optional, default https://$OPN_HOST
#   OPN_CURL_OPTS                  optional extra curl options, default "-k" (self-signed cert)
#   PRODUCTION_HOST                optional, the tests refuse to run if the target matches it
#   OPN_VIEW_KEY/_SECRET           user with only page-casadelta-status
#   OPN_ADMIN_KEY/_SECRET          user with only page-casadelta-settings
#   OPN_BOTH_KEY/_SECRET           user with both privileges (acl_matrix.sh)
#   OPN_NONE_KEY/_SECRET           user with neither privilege (acl_matrix.sh)

if [[ -n "${CD_LIB_LOADED:-}" ]]; then
    return 0
fi
CD_LIB_LOADED=1

PASS_COUNT=0
FAIL_COUNT=0
BODY=""
CODE=""

require_tools() {
    local tool
    for tool in curl jq; do
        command -v "$tool" >/dev/null 2>&1 || { echo "FATAL: '$tool' is required" >&2; exit 2; }
    done
}

require_env() {
    local name
    for name in "$@"; do
        if [[ -z "${!name:-}" ]]; then
            echo "FATAL: environment variable $name is required" >&2
            exit 2
        fi
    done
}

# Principle VIII guard: never run against production.
refuse_production() {
    require_env OPN_HOST
    BASE_URL="${OPN_BASE_URL:-https://$OPN_HOST}"
    if [[ -n "${PRODUCTION_HOST:-}" ]]; then
        local target="${BASE_URL#*://}"
        if [[ "$target" == "$PRODUCTION_HOST"* || "$OPN_HOST" == "$PRODUCTION_HOST" ]]; then
            echo "FATAL: target '$BASE_URL' matches PRODUCTION_HOST; refusing to run." >&2
            exit 2
        fi
    fi
    # shellcheck disable=SC2206
    CURL_OPTS=(${OPN_CURL_OPTS:--k})
}

# call METHOD PATH KEY SECRET [JSON_BODY]  ->  sets CODE and BODY
call() {
    local method="$1" path="$2" key="$3" secret="$4" data="${5-}"
    local out
    if [[ -n "$data" ]]; then
        out=$(curl -sS "${CURL_OPTS[@]}" -u "$key:$secret" -X "$method" \
            -H 'Content-Type: application/json' -d "$data" \
            -w $'\n%{http_code}' "$BASE_URL$path") || out=$'\n000'
    else
        out=$(curl -sS "${CURL_OPTS[@]}" -u "$key:$secret" -X "$method" \
            -w $'\n%{http_code}' "$BASE_URL$path") || out=$'\n000'
    fi
    CODE="${out##*$'\n'}"
    BODY="${out%$'\n'*}"
}

pass() {
    PASS_COUNT=$((PASS_COUNT + 1))
    echo "  ok   - $1"
}

fail() {
    FAIL_COUNT=$((FAIL_COUNT + 1))
    echo "  FAIL - $1" >&2
    if [[ -n "${2:-}" ]]; then
        echo "         $2" >&2
    fi
}

assert_eq() {
    local desc="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        pass "$desc"
    else
        fail "$desc" "expected '$expected', got '$actual'"
    fi
}

# assert_jq DESC JQ_FILTER  : the filter must evaluate to true on $BODY
assert_jq() {
    local desc="$1" filter="$2" result
    result=$(printf '%s' "$BODY" | jq -r "$filter" 2>/dev/null || echo "jq-error")
    if [[ "$result" == "true" ]]; then
        pass "$desc"
    else
        fail "$desc" "filter '$filter' gave '$result' on: ${BODY:0:300}"
    fi
}

jqv() {
    printf '%s' "$BODY" | jq -r "$1" 2>/dev/null
}

finish() {
    echo
    echo "passed: $PASS_COUNT, failed: $FAIL_COUNT"
    if [[ "$FAIL_COUNT" -gt 0 ]]; then
        exit 1
    fi
    exit 0
}

# Host helpers (administrator credentials in ADMIN_KEY / ADMIN_SECRET).
# add_host NAME ADDRESS [GROUP] [SORT_ORDER] [ENABLED]  -> BODY holds the API response
add_host() {
    local payload
    payload=$(jq -nc --arg name "$1" --arg address "$2" --arg group "${3:-Network}" \
        --arg sort "${4-0}" --arg enabled "${5:-1}" \
        '{host: ({enabled: $enabled, name: $name, address: $address, group: $group, description: ""}
                 + (if $sort == "-" then {} else {sort_order: $sort} end))}')
    call POST /api/casadelta/settings/addHost "$ADMIN_KEY" "$ADMIN_SECRET" "$payload"
}

del_host() {
    call POST "/api/casadelta/settings/delHost/$1" "$ADMIN_KEY" "$ADMIN_SECRET" '{}'
}
