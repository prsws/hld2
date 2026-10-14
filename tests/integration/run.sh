#!/usr/bin/env bash
# Runs status_api.sh, settings_crud.sh and acl_matrix.sh in order against the TEST VM and stops
# at the first failure. Refuses to run when the target matches PRODUCTION_HOST (Principle VIII).
#
# Usage:
#   OPN_HOST=… OPN_VIEW_KEY=… OPN_VIEW_SECRET=… OPN_ADMIN_KEY=… OPN_ADMIN_SECRET=… \
#   OPN_BOTH_KEY=… OPN_BOTH_SECRET=… OPN_NONE_KEY=… OPN_NONE_SECRET=… \
#   [PRODUCTION_HOST=firewall.example.com] tests/integration/run.sh

set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$DIR/lib.sh"

refuse_production

for script in status_api.sh settings_crud.sh acl_matrix.sh; do
    echo "=== $script ==="
    if ! bash "$DIR/$script"; then
        echo "FAILED: $script" >&2
        exit 1
    fi
    echo
done

echo "All integration tests passed."
