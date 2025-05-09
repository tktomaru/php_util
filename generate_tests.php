<?php

/** 
 * https://getcomposer.org/download/
 * Windows で OpenSSL 拡張を有効化する手順
 * 1. **使用中の PHP 設定ファイル（php.ini）を確認**  
 *    ```powershell
 *    php --ini
 * と実行し、「Loaded Configuration File: ～\php.ini」がどこを参照しているか確認します。
 * 
 * php.ini を編集
 * エクスプローラーで先ほど確認した php.ini を開く
 * 以下の行を検索して、先頭のセミコロン（;）を外す（アンコメント）
 * ;extension=openssl
 * ;extension=mbstring
 * ↓
 * extension=openssl
 * extension=mbstring
 * 
 * 必要に応じて extension_dir のパスも適切になっているか確認
 * extension_dir = "ext"
 * （PHP を展開したルート直下に ext フォルダがあればこのままで OK）
 * 
 * PHP CLI を再起動
 * PowerShell／コマンドプロンプトを一度閉じて、再度開き直します。
 * OpenSSL が有効になったことを確認
 * php -m | findstr openssl
 * 
 * ライブラリインストール
 * php .\composer.phar require nikic/php-parser phpunit/phpunit --dev
 * php .\composer.phar require --dev phpunit/phpunit:^9.5 --with-all-dependencies
 * 
 * テストを生成したいソースを指定して実行
 * php generate_tests.php src/MyClass.php
 * 
 * src/MyClassTest.php が生成されるので、
 * ./vendor/bin/phpunit src/MyClassTest.php
*/

require __DIR__ . '/vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\ErrorHandler\Collecting as ErrorHandler;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Expr;

// ────────────────────────────────────────
// Visitor：クラス＆関数ノードを収集
// ────────────────────────────────────────
class TestGeneratorVisitor extends NodeVisitorAbstract
{
    /** @var array<string, string[]> FQCN => メソッド名リスト */
    public array $classes       = [];
    /** @var string[] 関数名(FQFN)リスト */
    public array $functions     = [];
    /** @var array<string, Node\Stmt\Function_> 関数名 => ノード */
    public array $functionNodes = [];

    public function enterNode(Node $node)
    {
        // クラス検出
        if ($node instanceof Node\Stmt\Class_ && $node->namespacedName) {
            $fqcn = $node->namespacedName->toString();
            $this->classes[$fqcn] = [];
            foreach ($node->getMethods() as $method) {
                if ($method->isPublic() && !$method->isConstructor()) {
                    $this->classes[$fqcn][] = $method->name->name;
                }
            }
        }

        // グローバル関数検出
        if ($node instanceof Node\Stmt\Function_ && $node->namespacedName) {
            $fqfn = $node->namespacedName->toString();
            $this->functions[]          = $fqfn;
            $this->functionNodes[$fqfn] = $node;
        }
    }
}

/**
 * 関数ノードを解析して「引数例」と「期待値」を返すテストケースを抽出する
 *
 * サポートするパターン：
 *  - if～elseif～else による比較（>, ===）
 *  - in_array() を使ったメンバーシップ判定
 *  - switch/case/default
 *  - ネストした if／elseif／else
 *  - return の型：string, int, bool, null
 *
 * @param Node\Stmt\Function_ $fn
 * @return array<int, array{args: array<string, mixed>, expected: mixed}>
 */
/**
 * 関数ノードを解析してテストケースを返す
 *
 * @param Stmt\Function_ $fn
 * @return array<int,array{args:array<string,mixed>,expected:mixed}>
 */
function analyzeFunctionBranches(Stmt\Function_ $fn): array {
    // パラメータ名リスト
    $params = array_map(fn(Node\Param $p) => $p->var->name, $fn->getParams());

    // ────────────────────────────────────────
    // 1) トップレベルの Return_ と If_ を取得
    // ────────────────────────────────────────
    $defaultReturn = null;
    $outerParam    = null;
    $outerLit      = null;
    foreach ($fn->getStmts() ?? [] as $stmt) {
        // デフォルト返り値
        if ($stmt instanceof Stmt\Return_) {
            $defaultReturn = extractReturn([$stmt]);
        }
        // 外側分岐 ($param > lit) の検出
        if ($outerParam === null
         && $stmt        instanceof Stmt\If_
         && $stmt->cond  instanceof Expr\BinaryOp\Greater
         && $stmt->cond->left  instanceof Expr\Variable
         && $stmt->cond->right instanceof Node\Scalar\LNumber
         && in_array($stmt->cond->left->name, $params, true)
        ) {
            $outerParam = $stmt->cond->left->name;
            $outerLit   = $stmt->cond->right->value;
        }
    }

    // ────────────────────────────────────────
    // 2) ネスト内の分岐を再帰的に解析
    // ────────────────────────────────────────
    $cases = [];
    parseBranches($fn->getStmts() ?? [], $params, $cases);

    // ────────────────────────────────────────
    // 3) デフォルトケースを末尾に追加
    // ────────────────────────────────────────
    if ($defaultReturn !== null
     && $outerParam    !== null
     && $outerLit      !== null
    ) {
        $cases[] = [
            'args'     => [$outerParam => $outerLit],
            'expected' => $defaultReturn,
        ];
    }

    return $cases;
}

/**
 * ネスト内の if/switch/in_array を再帰的に解析するヘルパ
 */
function parseBranches(array $stmts, array $params, array &$cases): void {
    foreach ($stmts as $stmt) {
        // if / elseif
        if ($stmt instanceof Stmt\If_ || $stmt instanceof ElseIf_) {
            processIfBranch($stmt, $params, $cases);
            parseBranches($stmt->stmts, $params, $cases);
            foreach ($stmt->elseifs as $elseif) {
                processIfBranch($elseif, $params, $cases);
                parseBranches($elseif->stmts, $params, $cases);
            }
            if ($stmt->else) {
                parseBranches($stmt->else->stmts, $params, $cases);
            }
        }
        // switch/case
        if ($stmt instanceof Stmt\Switch_) {
            processSwitchBranch($stmt, $params, $cases);
        }
        // ループ内部も再帰
        if ($stmt instanceof Stmt\For_
         || $stmt instanceof Stmt\Foreach_
         || $stmt instanceof Stmt\While_
         || $stmt instanceof Stmt\Do_) {
            parseBranches($stmt->stmts, $params, $cases);
        }
    }
}

function processIfBranch(Stmt\If_|ElseIf_ $node, array $params, array &$cases): void {
    $cond = $node->cond;

    // --- 比較演算子：> または === ---
    if ($cond instanceof Expr\BinaryOp\Greater
     || $cond instanceof Expr\BinaryOp\Identical
    ) {
        $left  = $cond->left;
        $right = $cond->right;
        if ($left instanceof Expr\Variable
         && in_array($left->name, $params, true)
         && ($right instanceof Node\Scalar\LNumber
          || $right instanceof Node\Scalar\String_
          || $right instanceof Expr\ConstFetch)
        ) {
            $paramName = $left->name;
            // リテラル値を取得
            $lit = match (true) {
                $right instanceof Node\Scalar\LNumber => $right->value,
                $right instanceof Node\Scalar\String_ => $right->value,
                $right instanceof Expr\ConstFetch => strtolower($right->name->toString()) === 'true'
                    ? true
                    : (strtolower($right->name->toString()) === 'false' ? false : null),
                default => null
            };
            if ($lit !== null) {
                $retTrue  = extractReturn($node->stmts);
                $retFalse = extractElseReturn($node);
                if ($retTrue !== null && $retFalse !== null) {
                    if ($cond instanceof Expr\BinaryOp\Greater) {
                        // > の場合
                        $cases[] = ['args'=>[$paramName=>$lit+1], 'expected'=>$retTrue];
                        $cases[] = ['args'=>[$paramName=>$lit],   'expected'=>$retFalse];
                    } else {
                        // === の場合
                        $cases[] = ['args'=>[$paramName=>$lit],                    'expected'=>$retTrue];
                        $cases[] = ['args'=>[$paramName=>invertValue($lit)],      'expected'=>$retFalse];
                    }
                }
            }
        }
    }
    // --- in_array() メンバーシップ ---
    elseif ($cond instanceof Expr\FuncCall
        && $cond->name instanceof Node\Name
        && $cond->name->toString() === 'in_array'
    ) {
        $argsN = $cond->args;
        if (isset($argsN[0], $argsN[1])
         && $argsN[0]->value instanceof Expr\Variable
         && in_array($argsN[0]->value->name, $params, true)
         && $argsN[1]->value instanceof Expr\Array_
        ) {
            $paramName = $argsN[0]->value->name;
            $values = [];
            foreach ($argsN[1]->value->items as $item) {
                if ($item->value instanceof Node\Scalar\LNumber) {
                    $values[] = $item->value->value;
                } elseif ($item->value instanceof Node\Scalar\String_) {
                    $values[] = $item->value->value;
                }
            }
            $retTrue  = extractReturn($node->stmts);
            $retFalse = extractElseReturn($node);
            // 各メンバーに対して true ケース
            foreach ($values as $v) {
                $cases[] = ['args'=>[$paramName=>$v], 'expected'=>$retTrue];
            }
            // メンバー外で false ケース
            if (count($values) > 0 && $retFalse !== null) {
                $cases[] = ['args'=>[$paramName=>invertValue($values[0])], 'expected'=>$retFalse];
            }
        }
    }
}

function processSwitchBranch(Stmt\Switch_ $node, array $params, array &$cases): void {
    $cond = $node->cond;
    if ($cond instanceof Expr\Variable && in_array($cond->name, $params, true)) {
        foreach ($node->cases as $case) {
            // case 値あり
            if ($case->cond instanceof Node\Scalar\LNumber
             || $case->cond instanceof Node\Scalar\String_
            ) {
                $val = $case->cond instanceof Node\Scalar\LNumber
                    ? $case->cond->value
                    : $case->cond->value;
                $ret = extractReturn($case->stmts);
                if ($ret !== null) {
                    $cases[] = ['args'=>[$cond->name=>$val], 'expected'=>$ret];
                }
            }
            // default
            if ($case->cond === null) {
                $retDefault = extractReturn($case->stmts);
                if ($retDefault !== null) {
                    // default 用に「他の値」を生成
                    $sample = $cases[0]['args'][$cond->name] ?? null;
                    $cases[] = ['args'=>[$cond->name=>invertValue($sample)], 'expected'=>$retDefault];
                }
            }
        }
    }
}

/** return ステートメントからスカラー値を取り出す */
function extractReturn(array $stmts): mixed {
    foreach ($stmts as $s) {
        if ($s instanceof Stmt\Return_ && $s->expr !== null) {
            $e = $s->expr;
            return match (true) {
                $e instanceof Node\Scalar\String_ => $e->value,
                $e instanceof Node\Scalar\LNumber  => $e->value,
                $e instanceof Expr\ConstFetch    => (strtolower($e->name->toString())==='true'),
                default => null,
            };
        }
    }
    return null;
}

/** else/elseif 部分の return を取り出す */
function extractElseReturn(Stmt\If_ $node): mixed {
    if ($node->else) {
        return extractReturn($node->else->stmts);
    }
    return null;
}

/** サンプル値と異なる値を返す（int, string, bool に対応） */
function invertValue(mixed $v): mixed {
    return match (true) {
        is_int($v)    => $v - 1,
        is_string($v) => $v . '_x',
        is_bool($v)   => !$v,
        default       => null,
    };
}
// ────────────────────────────────────────
// 引数チェック＆パース
// // ────────────────────────────────────────
// if ($argc < 2) {
//     fwrite(STDERR, "Usage: php generate_tests.php <path/to/SourceFile.php>\n");
//     exit(1);
// }
// $sourceFile = $argv[1];
// if (!file_exists($sourceFile)) {
//     fwrite(STDERR, "Error: file not found: {$sourceFile}\n");
//     exit(1);
// }


// ────────────────────────────────────────
// （既存の関数／クラス定義はそのまま）
// ────────────────────────────────────────

/**
 * メイン処理を実行する
 *
 * @param array $argv CLI から渡された引数配列
 */
function main(array $argv): void
{
    // 1) 引数チェック
    if (count($argv) < 2) {
        fwrite(STDERR, "Usage: php generate_tests.php <path/to/SourceFile.php>\n");
        exit(1);
    }
    $sourceFile = $argv[1];
    if (!file_exists($sourceFile)) {
        fwrite(STDERR, "Error: file not found: {$sourceFile}\n");
        exit(1);
    }

    // 2) ソース読み込み～AST解析～テスト生成までの既存ロジックをここに移動
    //    （$sourceFile をローカル変数として使えるようになります）
    //    … 既存の file_get_contents($sourceFile) や file_put_contents() の処理 …

    // 例:
    $code         = file_get_contents($sourceFile);
    $errorHandler = new ErrorHandler();
    $parser       = (new ParserFactory())->createForHostVersion();
    $ast          = $parser->parse($code, $errorHandler);
    // ────────────────────────────────────────
    // 名称解決＆トラバース
    // ────────────────────────────────────────
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    $visitor   = new TestGeneratorVisitor();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);
    

// ────────────────────────────────────────
// クラスごとのテスト生成
// ────────────────────────────────────────
foreach ($visitor->classes as $fqcn => $methods) {
    $parts     = explode('\\', $fqcn);
    $className = end($parts);
    $testClass = $className . 'Test';
    $filename  = dirname($sourceFile) . '/' . $testClass . '.php';

    // 初期化
    $out  = "<?php\n";
    $out .= "use PHPUnit\\Framework\\TestCase;\n";
    $out .= "use {$fqcn};\n\n";
    $out .= "class {$testClass} extends TestCase\n{\n";

    foreach ($methods as $method) {
        $camel = ucfirst($method);
        $out .= "    public function test{$camel}(): void\n";
        $out .= "    {\n";
        $out .= "        \$obj = new {$className}();\n";
        $out .= "        \$this->markTestIncomplete('未実装');\n";
        $out .= "    }\n\n";
    }
    $out .= "}\n";

    file_put_contents($filename, $out);
    echo "Generated: {$filename}\n";
}

// ────────────────────────────────────────
// グローバル関数ごとのテスト生成
// ────────────────────────────────────────
if (!empty($visitor->functions)) {
    $testClass = 'GlobalFunctionsTest';
    $filename  = dirname($sourceFile) . '/' . $testClass . '.php';

    // 初期化
    $out  = "<?php\n";
    $out .= "require_once __DIR__ . '/" . basename($sourceFile) . "';\n";
    $out .= "use PHPUnit\\Framework\\TestCase;\n\n";
    $out .= "class {$testClass} extends TestCase\n{\n";

    foreach ($visitor->functions as $fqfn) {
        $short = array_slice(explode('\\', $fqfn), -1)[0];
        $cases = analyzeFunctionBranches($visitor->functionNodes[$fqfn]);

        if (!empty($cases)) {
            foreach ($cases as $i => $case) {
                $methodName = "test" . ucfirst($short) . "Case" . ($i + 1);
                $out .= "    public function {$methodName}(): void\n";
                $out .= "    {\n";
                // 引数生成
                $argsPhp = [];
                foreach ($case['args'] as $p => $v) {
                    $argsPhp[] = var_export($v, true);
                }
                $out .= "        \$result = {$short}(" . implode(', ', $argsPhp) . ");\n";
                $out .= "        \$this->assertEquals(" . var_export($case['expected'], true) . ", \$result);\n";
                $out .= "    }\n\n";
            }
        } else {
            // 汎用雛形
            $methodName = "test" . ucfirst($short);
            $out       .= "    public function {$methodName}(): void\n";
            $out       .= "    {\n";
            $out       .= "        \$this->markTestIncomplete('未実装');\n";
            $out       .= "    }\n\n";
        }
    }

    $out .= "}\n";

    file_put_contents($filename, $out);
    echo "Generated: {$filename}\n";
}
}

// ────────────────────────────────────────
// 直接 CLI からこのファイルが実行されたときだけ main() を呼ぶ
// ────────────────────────────────────────
if (php_sapi_name() === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__)
) {
    main($_SERVER['argv']);
}
