<?php

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
$inputDump = <<<'DUMP'
array(2) {
  ["foo"]=>
  string(3) "bar"
  [0]=>
  int(42)
}
DUMP;