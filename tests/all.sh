#!/usr/bin/env bash
# すべての検証を順に走らせる。
set -uo pipefail
cd "$(dirname "$0")/.."
PHP=${PHP:-php}
fail=0

run() {
  echo "── $1"
  shift
  "$@" > /tmp/tfphp-test.log 2>&1
  local rc=$?
  tail -2 /tmp/tfphp-test.log | sed 's/^/   /'
  [ $rc -ne 0 ] && fail=1
  return 0
}

run "cty / msgpack 単体"        "$PHP" tests/run.php
run "境界値・異常系"             "$PHP" tests/adversarial.php
run "provider ロジック"          "$PHP" tests/provider.php
echo "── リファレンス実装との相互検証"
"$PHP" tests/crossval_emit.php >/dev/null 2>&1 \
  && python3 tests/crossval.py 2>&1 | tail -3 | sed 's/^/   /' \
  && "$PHP" tests/crossval_verify.php 2>&1 | tail -1 | sed 's/^/   /' \
  || fail=1

echo
[ $fail -eq 0 ] && echo "すべて成功" || echo "失敗あり"
exit $fail
