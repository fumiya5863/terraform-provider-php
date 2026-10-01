<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use PhpTf\MsgPack\Encoder;
use PhpTf\MsgPack\Ext;
use PhpTf\MsgPack\MapOf;
use PhpTf\MsgPack\RawBin;

/** PHP実装でエンコードしたバイト列を書き出す。Python の msgpack で読めるかを検証する。 */
$enc = new Encoder();
$cases = [
    'nil' => null,
    'true' => true,
    'false' => false,
    'int_0' => 0,
    'int_127' => 127,
    'int_128' => 128,
    'int_255' => 255,
    'int_256' => 256,
    'int_65535' => 65535,
    'int_65536' => 65536,
    'int_4294967295' => 4294967295,
    'int_4294967296' => 4294967296,
    'int_max' => PHP_INT_MAX,
    'int_-1' => -1,
    'int_-32' => -32,
    'int_-33' => -33,
    'int_-128' => -128,
    'int_-129' => -129,
    'int_-32768' => -32768,
    'int_-32769' => -32769,
    'int_-2147483648' => -2147483648,
    'int_-2147483649' => -2147483649,
    'int_min' => PHP_INT_MIN,
    'float_1.5' => 1.5,
    'float_-0.125' => -0.125,
    'float_pi' => 3.141592653589793,
    'str_empty' => '',
    'str_fix' => 'hello',
    'str_31' => str_repeat('a', 31),
    'str_32' => str_repeat('a', 32),
    'str_255' => str_repeat('a', 255),
    'str_256' => str_repeat('a', 256),
    'str_jp' => '日本語のテスト用',
    'arr_empty' => [],
    'arr_123' => [1, 2, 3],
    'arr_nested' => [[1, 2], ['a' => 1]],
    'map_simple' => new MapOf(['a' => 1, 'b' => 'x']),
    'map_empty' => new MapOf([]),
    'map_numkey_as_string' => new MapOf(['123' => 7]),
    'map_intkey' => new MapOf([1 => false, 2 => 'tf-'], false),
    'bin' => new RawBin("\x00\x01\xff"),
    'ext0' => new Ext(0, ''),
    'ext12' => new Ext(12, $enc->encode(new MapOf([1 => false], false))),
    'ext_fix1' => new Ext(7, "\x01"),
    'ext_fix16' => new Ext(7, str_repeat("\x02", 16)),
];

$out = [];
foreach ($cases as $name => $v) {
    $out[$name] = bin2hex($enc->encode($v));
}
$dir = '/tmp/xval';
if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
    fwrite(STDERR, "ディレクトリを作成できない: {$dir}\n");
    exit(1);
}
$path = $dir . '/php_encoded.json';
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (file_put_contents($path, $json) === false) {
    fwrite(STDERR, "書き込めない: {$path}\n");
    exit(1);
}
echo count($out), " 件を {$path} へ出力\n";
