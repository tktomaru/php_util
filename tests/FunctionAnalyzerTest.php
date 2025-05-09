<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\ErrorHandler\Collecting as ErrorHandler;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

// テスト対象の関数を読み込む
require_once __DIR__ . '/../generate_tests.php';

class FunctionAnalyzerTest extends TestCase
{
    /**
     * 指定したソースコードから、名前が $fnName の Function_ ノードを返す
     *
     * @param string $code   PHP ソースコード
     * @param string $fnName 検出したい関数名
     * @return Function_
     */
    private function getFunctionNode(string $code, string $fnName): Function_
    {
        // パーサ準備
        $parser       = (new ParserFactory())->createForHostVersion();
        $errorHandler = new ErrorHandler();
        $ast          = $parser->parse($code, $errorHandler);
        $this->assertFalse(
            $errorHandler->hasErrors(),
            'パースエラー: ' . implode("\n", array_map(fn($e) => $e->getMessage(), $errorHandler->getErrors()))
        );

        // トラバース＆名前解決
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        // 匿名クラスで関数ノードを検出
        $finder = new class($fnName) extends NodeVisitorAbstract {
            public ?Function_ $found = null;
            private string $target;
            public function __construct(string $target)
            {
                $this->target = $target;
            }
            public function enterNode($node)
            {
                if ($node instanceof Function_ && $node->name->name === $this->target) {
                    $this->found = $node;
                }
            }
        };
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        $this->assertInstanceOf(Function_::class, $finder->found, "関数 {$fnName} が検出できませんでした");
        return $finder->found;
    }

    public function testGreaterComparison(): void
    {
        $code = <<<'PHP'
        <?php
        function cmpGt($x) {
            if ($x > 10) {
                return 'big';
            } else {
                return 'small';
            }
        }
        PHP;

        $fn    = $this->getFunctionNode($code, 'cmpGt');
        $cases = analyzeFunctionBranches($fn);

        $this->assertCount(2, $cases);
        $this->assertEquals(['args'=>['x'=>11],'expected'=>'big'],   $cases[0]);
        $this->assertEquals(['args'=>['x'=>10],'expected'=>'small'], $cases[1]);
    }

    public function testIdenticalComparison(): void
    {
        $code = <<<'PHP'
        <?php
        function cmpEq($y) {
            if ($y === 'foo') {
                return true;
            } else {
                return false;
            }
        }
        PHP;

        $fn = $this->getFunctionNode($code, 'cmpEq');
        $cases = analyzeFunctionBranches($fn);

        // case1: y='foo' => true
        // case2: y='foo_x' => false  (invertValue で文字列に '_x' が付く)
        $this->assertCount(2, $cases);
        $this->assertEquals(['args'=>['y'=>'foo'],'expected'=>true],   $cases[0]);
        $this->assertEquals(['args'=>['y'=>'foo_x'],'expected'=>false], $cases[1]);
    }

    public function testInArrayMembership(): void
    {
        $code = <<<'PHP'
        <?php
        function testIn(array $a) {
            if (in_array($a, [1, 2, 3])) {
                return 'yes';
            } else {
                return 'no';
            }
        }
        PHP;

        $fn = $this->getFunctionNode($code, 'testIn');
        $cases = analyzeFunctionBranches($fn);

        // メンバー(1,2,3) ➔ 'yes', メンバー外(例えば 1-1=0) ➔ 'no'
        $this->assertGreaterThanOrEqual(3, count($cases));
        // 最低でも 1 と 2 と 3 がテスト候補として出ていること
        $foundValues = array_map(fn($c)=>$c['args']['a'], $cases);
        foreach ([1,2,3] as $v) {
            $this->assertContains($v, $foundValues);
        }
        // メンバー外のテストが含まれること（0 が入っている）
        $this->assertContains(0, $foundValues);
    }

    public function testSwitchCaseDefault(): void
    {
        $code = <<<'PHP'
        <?php
        function testSw($z) {
            switch ($z) {
                case 1: return 'one';
                case 2: return 'two';
                default: return 'other';
            }
        }
        PHP;

        $fn = $this->getFunctionNode($code, 'testSw');
        $cases = analyzeFunctionBranches($fn);

        // 1=>'one', 2=>'two', default=> その他(1-1=0) =>'other'
        $this->assertCount(3, $cases);
        $this->assertEquals(['args'=>['z'=>1],'expected'=>'one'],  $cases[0]);
        $this->assertEquals(['args'=>['z'=>2],'expected'=>'two'],  $cases[1]);
        $this->assertEquals(['args'=>['z'=>0],'expected'=>'other'],$cases[2]);
    }

    public function testNestedIfElse(): void
    {
        $code = <<<'PHP'
        <?php
        function nested($n) {
            if ($n > 0) {
                if ($n > 5) {
                    return 'big';
                } else {
                    return 'small';
                }
            }
            return 'zero';
        }
        PHP;

        $fn = $this->getFunctionNode($code, 'nested');
        $cases = analyzeFunctionBranches($fn);

        // 大きい→ n=6→'big'、小さい→n=5→'small'、zero→n=0→'zero'
        $this->assertCount(3, $cases);
        $this->assertEquals(['args'=>['n'=>6],'expected'=>'big'],   $cases[0]);
        $this->assertEquals(['args'=>['n'=>5],'expected'=>'small'],$cases[1]);
        $this->assertEquals(['args'=>['n'=>0],'expected'=>'zero'], $cases[2]);
    }
}