#!/usr/bin/env python3
"""自作の msgpack 実装を、独立したリファレンス実装と突き合わせる。

自作エンコーダ ↔ 自作デコーダの往復は、仕様準拠の証明にならない（トートロジー）。
そこで Python の msgpack パッケージを第三者の立会人として使う。

  usage:
    pip install msgpack
    php tests/crossval_emit.php      # 自作がエンコードしたものを書き出す
    python3 tests/crossval.py        # それを読み、検証し、逆方向のデータを書き出す
    php tests/crossval_verify.php    # 逆方向を自作デコーダで読む
"""
import json
import os
import sys

try:
    import msgpack
except ImportError:
    sys.exit("msgpack が必要です: pip install msgpack")

XVAL_DIR = "/tmp/xval"
PHP_FILE = os.path.join(XVAL_DIR, "php_encoded.json")
PY_FILE = os.path.join(XVAL_DIR, "py_encoded.json")

EXPECTED = {
    "nil": None, "true": True, "false": False,
    "int_0": 0, "int_127": 127, "int_128": 128, "int_255": 255, "int_256": 256,
    "int_65535": 65535, "int_65536": 65536, "int_4294967295": 4294967295,
    "int_4294967296": 4294967296, "int_max": 9223372036854775807,
    "int_-1": -1, "int_-32": -32, "int_-33": -33, "int_-128": -128, "int_-129": -129,
    "int_-32768": -32768, "int_-32769": -32769, "int_-2147483648": -2147483648,
    "int_-2147483649": -2147483649, "int_min": -9223372036854775808,
    "float_1.5": 1.5, "float_-0.125": -0.125, "float_pi": 3.141592653589793,
    "str_empty": "", "str_fix": "hello", "str_31": "a" * 31, "str_32": "a" * 32,
    "str_255": "a" * 255, "str_256": "a" * 256, "str_jp": "日本語のテスト用",
    "arr_empty": [], "arr_123": [1, 2, 3], "arr_nested": [[1, 2], {"a": 1}],
    "map_simple": {"a": 1, "b": "x"}, "map_empty": {},
    "map_numkey_as_string": {"123": 7}, "map_intkey": {1: False, 2: "tf-"},
    "bin": b"\x00\x01\xff",
}


def main() -> int:
    if not os.path.isfile(PHP_FILE):
        sys.exit(f"{PHP_FILE} が無い。先に php tests/crossval_emit.php を実行すること")

    with open(PHP_FILE) as f:
        data = json.load(f)
    if not data:
        sys.exit(f"{PHP_FILE} が空")

    ok = fail = 0
    print("[自作PHPがエンコード → Pythonのmsgpackがデコード]")
    for name, hexstr in data.items():
        raw = bytes.fromhex(hexstr)
        try:
            got = msgpack.unpackb(raw, raw=False, strict_map_key=False)
        except Exception as e:
            print(f"  FAIL {name}: デコード例外 {type(e).__name__}: {e}")
            fail += 1
            continue

        if name.startswith("ext"):
            if isinstance(got, msgpack.ExtType):
                ok += 1
            else:
                print(f"  FAIL {name}: ExtType にならない -> {got!r}")
                fail += 1
            continue

        if name not in EXPECTED:
            print(f"  FAIL {name}: 期待値が未定義（黙って見逃さない）")
            fail += 1
            continue

        if got == EXPECTED[name]:
            ok += 1
        else:
            print(f"  FAIL {name}: expected {EXPECTED[name]!r} got {got!r}")
            fail += 1

    print(f"  => {ok} passed, {fail} failed\n")

    # 逆方向のデータを書き出す
    os.makedirs(XVAL_DIR, exist_ok=True)
    rev = {n: msgpack.packb(v, use_bin_type=True).hex() for n, v in EXPECTED.items()}
    rev["ext0"] = msgpack.packb(msgpack.ExtType(0, b""), use_bin_type=True).hex()
    rev["ext12"] = msgpack.packb(
        msgpack.ExtType(12, msgpack.packb({1: False})), use_bin_type=True
    ).hex()
    # go-cty が実際に出す unknown の正準表現（fixext1）も混ぜる
    rev["ext_gocty_unknown"] = "d40000"
    with open(PY_FILE, "w") as f:
        json.dump(rev, f)
    print(f"[逆方向] {len(rev)} 件を {PY_FILE} へ出力")

    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
