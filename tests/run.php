<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpTf\Cty\DynamicValue;
use PhpTf\Cty\Type;
use PhpTf\Cty\Value;
use PhpTf\MsgPack\Decoder;
use PhpTf\MsgPack\Encoder;
use PhpTf\MsgPack\Ext;
use PhpTf\MsgPack\MapOf as MsgPackMapOf;

$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32mPASS\033[0m {$name}\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m {$name}" . ($detail !== '' ? "\n       {$detail}" : '') . "\n";
    }
}

function hex(string $s): string
{
    return implode(' ', array_map(fn ($c) => sprintf('%02x', ord($c)), str_split($s)));
}

$enc = new Encoder();
$dv = new DynamicValue();

// ─────────────────────────────────────────────────────────
echo "\n[1] msgpack の既知バイト列との照合（仕様から手計算）\n";
// ─────────────────────────────────────────────────────────
$vectors = [
    'nil'                => [null,            'c0'],
    'true'               => [true,            'c3'],
    'false'              => [false,           'c2'],
    'positive fixint 42' => [42,              '2a'],
    'negative fixint -1' => [-1,              'ff'],
    'uint8 200'          => [200,             'cc c8'],
    'uint16 1000'        => [1000,            'cd 03 e8'],
    'int8 -100'          => [-100,            'd0 9c'],
    'fixstr "hello"'     => ['hello',         'a5 68 65 6c 6c 6f'],
    'fixarray [1,2,3]'   => [[1, 2, 3],       '93 01 02 03'],
];
foreach ($vectors as $name => [$input, $expect]) {
    $got = hex($enc->encode($input));
    ok($name, $got === $expect, "expect: {$expect}\n       got:    {$got}");
}
// float64 は IEEE754 big-endian
ok('float64 1.5', hex($enc->encode(1.5)) === 'cb 3f f8 00 00 00 00 00 00',
   'got: ' . hex($enc->encode(1.5)));
// fixmap はキーが文字列
ok('fixmap {a:1}', hex($enc->encode(['a' => 1])) === '81 a1 61 01',
   'got: ' . hex($enc->encode(['a' => 1])));

// ─────────────────────────────────────────────────────────
echo "\n[2] unknown 値の拡張型エンコード\n";
// ─────────────────────────────────────────────────────────
$unknown = $dv->encode(Value::unknown(Type::string()));
// 絞り込み無し = 旧形式コード0、ペイロード長0 → ext8(0xc7) len=0 code=0
ok('素の unknown は拡張コード0', hex($unknown) === 'c7 00 00', 'got: ' . hex($unknown));

$refined = $dv->encode(Value::unknown(Type::string(), [
    DynamicValue::REFINE_NULLNESS => false,
    DynamicValue::REFINE_STRING_PREFIX => 'tf-',
]));
$r = Decoder::decode($refined);
ok('refined unknown は拡張コード12', $r instanceof Ext && $r->code === 12);
$payload = Decoder::decode($r->payload);
ok('refinement マップは整数キーで復元される',
   $payload instanceof MsgPackMapOf && $payload->stringKeys === false,
   'got: ' . get_debug_type($payload));
$entries = $payload instanceof MsgPackMapOf ? $payload->entries : [];
ok('refinement のキーは整数のまま',
   isset($entries[1], $entries[2]) && $entries[1] === false && $entries[2] === 'tf-',
   'got: ' . json_encode($entries));

// ─────────────────────────────────────────────────────────
echo "\n[3] cty 全型の往復（encode → decode で一致するか）\n";
// ─────────────────────────────────────────────────────────
$cases = [
    'string'  => [Type::string(), Value::known(Type::string(), 'こんにちは世界')],
    'number'  => [Type::number(), Value::known(Type::number(), 42)],
    'float'   => [Type::number(), Value::known(Type::number(), 3.14)],
    'bool'    => [Type::bool(),   Value::known(Type::bool(), true)],
];
foreach ($cases as $name => [$t, $v]) {
    $back = $dv->decode($dv->encode($v), $t);
    ok("往復 {$name}", $back->isKnown() && $back->raw() == $v->raw(),
       'got: ' . $back->describe());
}

// null と unknown の状態保存
foreach (['null' => Value::null(Type::string()), 'unknown' => Value::unknown(Type::string())] as $name => $v) {
    $back = $dv->decode($dv->encode($v), Type::string());
    ok("往復 {$name} の状態が保たれる",
       $name === 'null' ? $back->isNull() : $back->isUnknown(),
       'got: ' . $back->describe());
}

// list
$listT = Type::list(Type::string());
$listV = Value::known($listT, [
    Value::known(Type::string(), 'alpha'),
    Value::known(Type::string(), 'bravo'),
]);
$back = $dv->decode($dv->encode($listV), $listT);
ok('往復 list(string)',
   $back->isKnown() && count($back->raw()) === 2 && $back->raw()[1]->raw() === 'bravo',
   'got: ' . $back->describe());

// map
$mapT = Type::map(Type::number());
$mapV = Value::known($mapT, ['a' => Value::known(Type::number(), 1)]);
$back = $dv->decode($dv->encode($mapV), $mapT);
ok('往復 map(number)', $back->isKnown() && $back->raw()['a']->raw() === 1);

// 数値文字列キーが int 化されない（PHP 固有の罠）
$mapV2 = Value::known($mapT, ['123' => Value::known(Type::number(), 7)]);
$encoded = $dv->encode($mapV2);
ok('数値文字列キーが msgpack str のまま',
   str_contains($encoded, "\xa3" . '123'), 'got: ' . hex($encoded));

// object
$objT = Type::object(['name' => Type::string(), 'count' => Type::number()]);
$objV = Value::known($objT, [
    'name'  => Value::known(Type::string(), 'php'),
    'count' => Value::null(Type::number()),
]);
$back = $dv->decode($dv->encode($objV), $objT);
ok('往復 object（null 属性を保持）',
   $back->raw()['name']->raw() === 'php' && $back->raw()['count']->isNull());

// tuple
$tupT = Type::tuple([Type::string(), Type::bool()]);
$tupV = Value::known($tupT, [
    Value::known(Type::string(), 'x'),
    Value::known(Type::bool(), false),
]);
$back = $dv->decode($dv->encode($tupV), $tupT);
ok('往復 tuple', $back->raw()[0]->raw() === 'x' && $back->raw()[1]->raw() === false);

// dynamic
$dynT = Type::dynamic();
$dynV = Value::known($dynT, Value::known(Type::string(), 'runtime'));
$encoded = $dv->encode($dynV);
$back = $dv->decode($encoded, $dynT);
ok('往復 dynamic（型を同梱）',
   $back->isKnown() && $back->raw() instanceof Value && $back->raw()->raw() === 'runtime',
   'got: ' . $back->describe());
ok('dynamic は encode の逆関数になっている',
   $dv->encode($back) === $encoded,
   're-encode が一致しない');
ok('dynamic の第1要素は bin',
   str_starts_with($encoded, "\x92\xc4"), 'got: ' . hex(substr($encoded, 0, 4)));

// ─────────────────────────────────────────────────────────
echo "\n[4] 型制約のコンパクトJSON表現\n";
// ─────────────────────────────────────────────────────────
ok('string',        Type::string()->toJson() === '"string"', 'got: ' . Type::string()->toJson());
ok('list(string)',  Type::list(Type::string())->toJson() === '["list","string"]', 'got: ' . Type::list(Type::string())->toJson());
ok('object',        Type::object(['a' => Type::bool()])->toJson() === '["object",{"a":"bool"}]', 'got: ' . Type::object(['a' => Type::bool()])->toJson());
ok('空 object は {}', Type::object([])->toJson() === '["object",{}]', 'got: ' . Type::object([])->toJson());
$rt = Type::fromJson('["map",["list","number"]]');
ok('fromJson 往復', $rt->toJson() === '["map",["list","number"]]', 'got: ' . $rt->toJson());

// ─────────────────────────────────────────────────────────
printf("\n%s\n結果: %d passed, %d failed\n", str_repeat('─', 50), $pass, $fail);
exit($fail === 0 ? 0 : 1);
