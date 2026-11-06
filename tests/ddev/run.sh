#!/usr/bin/env bash
# Runs every DDEV integration test for the plugin. Usage: bash tests/ddev/run.sh [pattern]
set -u
cd "$(dirname "$0")/../../../../.." || exit 1
pattern="${1:-}"
fail=0
for t in wp-content/plugins/swish-migrate-and-backup/tests/ddev/test-*.php; do
  [ -n "$pattern" ] && [[ "$t" != *"$pattern"* ]] && continue
  echo "=== $(basename "$t") ==="
  if ! ddev wp eval-file "$t"; then fail=1; fi
done
exit $fail
