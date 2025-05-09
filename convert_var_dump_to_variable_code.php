<?php
/**
 * var_dump 出力を PHP の配列表現に変換（キー情報を保持）
 *
 * @param string $dump var_dump の出力
 * @return string      生成された PHP コード
 */
function convertVarDumpToPhpCode(string $dump): string {
    $lines       = preg_split('/\R/', trim($dump));
    $phpLines    = [];
    $indent      = 0;
    $currentKey  = null;

    foreach ($lines as $line) {
        $text = trim($line);

        // 配列開始
        if (preg_match('/^array\(\d+\) \{$/', $text)) {
            // キー付きなら "'key' => [" を、なければ "[" のみ
            if ($currentKey !== null) {
                $phpLines[] = str_repeat('  ', $indent)
                            . var_export($currentKey, true)
                            . ' => [';
            } else {
                $phpLines[] = str_repeat('  ', $indent) . '[';
            }
            $indent++;
            $currentKey = null;
            continue;
        }

        // 配列終了
        if ($text === '}') {
            $indent--;
            $phpLines[] = str_repeat('  ', $indent) . '],';
            continue;
        }

        // キー行
        if (preg_match('/^\[(.+)\]=>$/', $text, $m)) {
            $raw = $m[1];
            // "xxx" なら文字列キー、数字なら int、その他は生キー
            if (preg_match('/^"(.*)"$/', $raw, $mm)) {
                $currentKey = $mm[1];
            } elseif (is_numeric($raw)) {
                $currentKey = (int)$raw;
            } else {
                $currentKey = $raw;
            }
            continue;
        }

        // string
        if (preg_match('/^string\(\d+\) "(.*)"$/', $text, $m)) {
            $val = var_export($m[1], true);
        }
        // int
        elseif (preg_match('/^int\(([-]?\d+)\)$/', $text, $m)) {
            $val = $m[1];
        }
        // float
        elseif (preg_match('/^float\(([-+]?[0-9]*\.?[0-9]+(?:[eE][-+]?\d+)?)\)$/', $text, $m)) {
            $val = $m[1];
        }
        // bool
        elseif (preg_match('/^bool\((true|false)\)$/', $text, $m)) {
            $val = ($m[1] === 'true') ? 'true' : 'false';
        }
        // NULL
        elseif ($text === 'NULL') {
            $val = 'null';
        }
        else {
            // その他の行はスキップ
            continue;
        }

        // スカラー出力：キー付き or 値のみ
        if ($currentKey !== null) {
            $phpLines[] = str_repeat('  ', $indent)
                        . var_export($currentKey, true)
                        . ' => '
                        . $val
                        . ',';
        } else {
            $phpLines[] = str_repeat('  ', $indent)
                        . $val
                        . ',';
        }
        $currentKey = null;
    }

    // 最後の余計なカンマ付き閉括弧をマッチして除去
    $code = implode("\n", $phpLines);
    $code = preg_replace('/\],\s*$/', ']', $code);

    return "<?php\n\$array = " . $code . ";\n";
}


// --------------------------------------------------
// ◆ 使い方例1
// --------------------------------------------------


// $array = [
//     "foo" => 'bar',
//     42,
// ];
// var_dump($array);

$inputDump = <<<'DUMP'
array(2) {
  ["foo"]=>
  string(3) "bar"
  [0]=>
  int(42)
}
DUMP;

// 1) var_dump 出力を再パース
$var = convertVarDumpToPhpCode($inputDump);

// 3) 出力
printf($var);

// --------------------------------------------------
// ◆ 使い方例2
// --------------------------------------------------

// $array = [
//     0 => [
//       0 => '田中',
//       1 => 25,
//       2 => '男性',
//     ],
//     1 => [
//       0 => '鈴木',
//       1 => 32,
//       2 => '女性',
//     ],
//     2 => [
//       0 => '高橋',
//       1 => 20,
//       2 => '女性',
//     ],
//   ];
//   var_dump($array);

// $array = [
//     ['田中',25,'男性'],
//     ['鈴木',32,'女性'],
//     ['高橋',20,'女性']
// ];
// var_dump($array);

$inputDump = <<<'DUMP'
array(3) {
  [0]=>
  array(3) {
    [0]=>
    string(6) "田中"
    [1]=>
    int(25)
    [2]=>
    string(6) "男性"
  }
  [1]=>
  array(3) {
    [0]=>
    string(6) "鈴木"
    [1]=>
    int(32)
    [2]=>
    string(6) "女性"
  }
  [2]=>
  array(3) {
    [0]=>
    string(6) "高橋"
    [1]=>
    int(20)
    [2]=>
    string(6) "女性"
  }
}
DUMP;

// 1) var_dump 出力を再パース
$var = convertVarDumpToPhpCode($inputDump);

// 3) 出力
printf($var);