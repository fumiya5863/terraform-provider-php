<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use PhpTf\MsgPack\Decoder;
use PhpTf\MsgPack\Ext;
use PhpTf\MsgPack\MapOf;
use PhpTf\MsgPack\RawBin;

/** Python の msgpack がエンコードしたバイト列を、自作デコーダが読めるか検証する。 */
$path = '/tmp/xval/py_encoded.json';
if (!is_file($path)) {
    fwrite(STDERR, "{$path} が無い。先に python3 tests/crossval.py を実行すること\n");
    exit(1);
}
$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data) || $data === []) {
    fwrite(STDERR, "{$path} を読めない、または空\n");
    exit(1);
}

$expected = [
    'nil' => null, 'true' => true, 'false' => false,
    'int_0' => 0, 'int_127' => 127, 'int_128' => 128, 'int_255' => 255, 'int_256' => 256,
    'int_65535' => 65535, 'int_65536' => 65536, 'int_4294967295' => 4294967295,
    'int_4294967296' => 4294967296, 'int_max' => PHP_INT_MAX,
    'int_-1' => -1, 'int_-32' => -32, 'int_-33' => -33, 'int_-128' => -128, 'int_-129' => -129,
    'int_-32768' => -32768, 'int_-32769' => -32769, 'int_-2147483648' => -2147483648,
    'int_-2147483649' => -2147483649, 'int_min' => PHP_INT_MIN,
    'float_1.5' => 1.5, 'float_-0.125' => -0.125, 'float_pi' => 3.141592653589793,
    'str_empty' => '', 'str_fix' => 'hello', 'str_31' => str_repeat('a', 31),
    'str_32' => str_repeat('a', 32), 'str_255' => str_repeat('a', 255),
    'str_256' => str_repeat('a', 256), 'str_jp' => '日本語のテスト用',
    'arr_empty' => [], 'arr_123' => [1, 2, 3],
    'arr_nested' => [[1, 2], ['a' => 1]],
    'map_simple' => ['a' => 1, 'b' => 'x'], 'map_empty' => [],
    'map_numkey_as_string' => ['123' => 7],
    'map_intkey' => [1 => false, 2 => 'tf-'],
    'bin' => "\x00\x01\xff",
];

$ok = 0; $fail = 0;
foreach ($data as $name => $hex) {
    $bytes = hex2bin($hex);
    try {
        $got = Decoder::decode($bytes);
    } catch (\Throwable $e) {
        printf("  FAIL %-24s デコード例外: %s\n", $name, $e->getMessage());
        $fail++;
        continue;
    }

    if (str_starts_with($name, 'ext')) {
        if ($got instanceof Ext) {
            printf("  PASS %-24s -> Ext(code=%d, len=%d)\n", $name, $got->code, strlen($got->payload));
            $ok++;
        } else {
            printf("  FAIL %-24s Ext にならない\n", $name);
            $fail++;
        }
        continue;
    }

    // Decoder は型情報を保つため map を MapOf、bin を RawBin で返す。
    // 値の比較にはほどいたものを使い、保持できていること自体も別途検証する。
    $unwrap = static function (mixed $v) use (&$unwrap): mixed {
        if ($v instanceof MapOf) {
            return array_map($unwrap, $v->entries);
        }
        if ($v instanceof RawBin) {
            return $v->bytes;
        }
        if (is_array($v)) {
            return array_map($unwrap, $v);
        }
        return $v;
    };

    if (str_starts_with($name, 'map_') && !$got instanceof MapOf) {
        printf("  FAIL %-24s マップが MapOf として復元されていない\n", $name);
        $fail++;
        continue;
    }
    if ($name === 'bin' && !$got instanceof RawBin) {
        printf("  FAIL %-24s bin が RawBin として復元されていない\n", $name);
        $fail++;
        continue;
    }
    $got = $unwrap($got);

    if (!array_key_exists($name, $expected)) {
        printf("  FAIL %-24s 期待値が未定義（黙って見逃さない）\n", $name);
        $fail++;
        continue;
    }
    $exp = $expected[$name];

    $match = is_float($exp) ? (abs($got - $exp) < 1e-12) : ($got === $exp);
    if ($match) {
        $ok++;
    } else {
        printf("  FAIL %-24s expected %s got %s\n", $name,
            var_export($exp, true), var_export($got, true));
        $fail++;
    }
}
printf("\n  => %d passed, %d failed\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
