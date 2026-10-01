<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpTf\Cty\DynamicValue;
use PhpTf\Cty\Type;
use PhpTf\Cty\Value;
use PhpTf\MsgPack\Decoder;
use PhpTf\MsgPack\Encoder;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32mPASS\033[0m {$n}\n"; }
    else { $fail++; echo "  \033[31mFAIL\033[0m {$n}" . ($d ? "\n       {$d}" : '') . "\n"; }
}
function hex(string $s): string {
    return implode(' ', array_map(fn($c) => sprintf('%02x', ord($c)), str_split($s)));
}

$enc = new Encoder();
$dv = new DynamicValue();

echo "\n[A] 整数の全幅境界\n";
$ints = [
    0, 1, 127,            // positive fixint 境界
    128, 255,             // uint8 境界
    256, 65535,           // uint16 境界
    65536, 4294967295,    // uint32 境界
    4294967296,           // uint64
    -1, -32,              // negative fixint 境界
    -33, -128,            // int8 境界
    -129, -32768,         // int16 境界
    -32769, -2147483648,  // int32 境界
    -2147483649,          // int64
    PHP_INT_MAX, PHP_INT_MIN,
];
foreach ($ints as $n) {
    $back = Decoder::decode($enc->encode($n));
    ok("int {$n}", $back === $n, 'got: ' . var_export($back, true) . ' hex: ' . hex($enc->encode($n)));
}

echo "\n[B] 文字列長の境界（fixstr / str8 / str16）\n";
foreach ([0, 31, 32, 255, 256, 1000] as $len) {
    $s = str_repeat('a', $len);
    $back = Decoder::decode($enc->encode($s));
    ok("string len={$len}", $back === $s, 'len mismatch: ' . strlen((string) $back));
}

echo "\n[C] マルチバイト（バイト長で数えているか）\n";
$jp = '日本語のテスト用';  // 24バイト / 8文字
$back = Decoder::decode($enc->encode($jp));
ok('日本語の往復', $back === $jp, 'got: ' . var_export($back, true));
$long = str_repeat('あ', 20); // 60バイト → str8 になるはず
ok('60バイトは str8', str_starts_with($enc->encode($long), "\xd9"), 'hex head: ' . substr(hex($enc->encode($long)), 0, 8));
ok('60バイト往復', Decoder::decode($enc->encode($long)) === $long);

echo "\n[D] 入れ子型\n";
// list(object)
$objT = Type::object(['name' => Type::string(), 'n' => Type::number()]);
$listT = Type::list($objT);
$v = Value::known($listT, [
    Value::known($objT, ['name' => Value::known(Type::string(), 'a'), 'n' => Value::known(Type::number(), 1)]),
    Value::known($objT, ['name' => Value::null(Type::string()), 'n' => Value::unknown(Type::number())]),
]);
$back = $dv->decode($dv->encode($v), $listT);
ok('list(object) 往復', $back->isKnown() && count($back->raw()) === 2);
ok('入れ子の null が保たれる', $back->raw()[1]->raw()['name']->isNull());
ok('入れ子の unknown が保たれる', $back->raw()[1]->raw()['n']->isUnknown());

// map(list(string))
$mlT = Type::map(Type::list(Type::string()));
$mlV = Value::known($mlT, [
    'x' => Value::known(Type::list(Type::string()), [Value::known(Type::string(), 'p')]),
]);
$back = $dv->decode($dv->encode($mlV), $mlT);
ok('map(list(string)) 往復', $back->raw()['x']->raw()[0]->raw() === 'p');

echo "\n[E] 空のコレクション（空マップ vs 空配列）\n";
$emptyList = Value::known(Type::list(Type::string()), []);
ok('空 list は msgpack 配列 (0x90)', $dv->encode($emptyList) === "\x90", 'hex: ' . hex($dv->encode($emptyList)));
$emptyMap = Value::known(Type::map(Type::string()), []);
ok('空 map は msgpack マップ (0x80)', $dv->encode($emptyMap) === "\x80", 'hex: ' . hex($dv->encode($emptyMap)));
$emptyObj = Value::known(Type::object([]), []);
ok('空 object は msgpack マップ (0x80)', $dv->encode($emptyObj) === "\x80", 'hex: ' . hex($dv->encode($emptyObj)));
ok('空 list 往復', count($dv->decode("\x90", Type::list(Type::string()))->raw()) === 0);
ok('空 map 往復', count($dv->decode("\x80", Type::map(Type::string()))->raw()) === 0);

echo "\n[F] unknown の扱い\n";
// 仕様: どんな拡張コードでも unknown とみなす
foreach ([0, 5, 12, 99] as $code) {
    $ext = $enc->encode(new \PhpTf\MsgPack\Ext($code, $code === 12 ? $enc->encode(new \PhpTf\MsgPack\MapOf([1 => false], false)) : ''));
    $back = $dv->decode($ext, Type::string());
    ok("拡張コード {$code} は unknown", $back->isUnknown());
}
// 仕様: 絞り込み無しは必ず旧形式（コード0）
ok('絞り込み無しは必ずコード0', hex($dv->encode(Value::unknown(Type::string()))) === 'c7 00 00');
// 仕様: 知らない refinement キーは無視して良い（落ちないこと）
$weird = $enc->encode(new \PhpTf\MsgPack\Ext(12, $enc->encode(new \PhpTf\MsgPack\MapOf([99 => 'unknown-key'], false))));
$back = $dv->decode($weird, Type::string());
ok('未知の refinement キーで落ちない', $back->isUnknown());

echo "\n[G] 数値の表現揺れ（Terraform は int / float / string のどれでも送る）\n";
foreach ([['int', "\x2a", 42], ['float', $enc->encode(3.5), 3.5], ['string(任意精度)', $enc->encode('123456789012345678901234567890'), '123456789012345678901234567890']] as [$label, $bytes, $expect]) {
    $back = $dv->decode($bytes, Type::number());
    ok("number as {$label}", $back->isKnown() && $back->raw() === $expect, 'got: ' . var_export($back->raw(), true));
}

echo "\n[H] 壊れた入力で安全に落ちるか\n";
foreach (['長さ超過' => "\xa5ab", '未知ヘッダ' => "\xc1", '途中切れ配列' => "\x93\x01"] as $label => $bytes) {
    try { Decoder::decode($bytes); ok("{$label} は例外", false, '例外が出なかった'); }
    catch (\RuntimeException $e) { ok("{$label} は例外", true); }
    catch (\Throwable $e) { ok("{$label} は RuntimeException", false, get_class($e)); }
}

echo "\n[I] gRPC 框化\n";
$f = \PhpTf\Grpc\Frame::wrap('abc');
ok('wrap は 1+4+本体', $f === "\x00\x00\x00\x00\x03abc", 'hex: ' . hex($f));
ok('unwrap 往復', \PhpTf\Grpc\Frame::unwrap($f) === ['abc']);
ok('複数メッセージ', \PhpTf\Grpc\Frame::unwrap($f . \PhpTf\Grpc\Frame::wrap('de')) === ['abc', 'de']);
try { \PhpTf\Grpc\Frame::unwrap("\x01\x00\x00\x00\x03abc"); ok('圧縮は明示的に拒否', false); }
catch (\RuntimeException $e) { ok('圧縮は明示的に拒否', true); }

echo "\n[J] ハンドシェイク行\n";
$line = \PhpTf\Plugin\Handshake::line('tcp', '127.0.0.1:1234');
ok('6フィールド', count(explode('|', $line)) === 6, 'got: ' . $line);
ok('CORE は 1', explode('|', $line)[0] === '1');
ok('APP は 6', explode('|', $line)[1] === '6');
ok('改行を含まない', !str_contains($line, "\n"));
// パディング無し base64
$der = random_bytes(10); // 10バイト → base64 は本来 "=" 2個付く
$line2 = \PhpTf\Plugin\Handshake::line('tcp', 'a', $der);
ok('base64 にパディングが無い', !str_contains(explode('|', $line2)[5], '='), 'got: ' . explode('|', $line2)[5]);

printf("\n%s\n結果: %d passed, %d failed\n", str_repeat('─', 50), $pass, $fail);
exit($fail === 0 ? 0 : 1);
