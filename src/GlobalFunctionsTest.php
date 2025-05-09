<?php
require_once __DIR__ . '/MyClass2.php';
use PHPUnit\Framework\TestCase;

class GlobalFunctionsTest extends TestCase
{
    public function testCmpGtCase1(): void
    {
        $result = cmpGt(11);
        $this->assertEquals('big', $result);
    }

    public function testCmpGtCase2(): void
    {
        $result = cmpGt(10);
        $this->assertEquals('small', $result);
    }

    public function testCmpEqCase1(): void
    {
        $result = cmpEq('foo');
        $this->assertEquals(true, $result);
    }

    public function testCmpEqCase2(): void
    {
        $result = cmpEq('foo_x');
        $this->assertEquals(false, $result);
    }

    public function testInList(): void
    {
        $this->markTestIncomplete('未実装');
    }

    public function testCaseTestCase1(): void
    {
        $result = caseTest(1);
        $this->assertEquals('one', $result);
    }

    public function testCaseTestCase2(): void
    {
        $result = caseTest(2);
        $this->assertEquals('two', $result);
    }

    public function testCaseTestCase3(): void
    {
        $result = caseTest(0);
        $this->assertEquals('other', $result);
    }

    public function testNestedCase1(): void
    {
        $result = nested(6);
        $this->assertEquals('big', $result);
    }

    public function testNestedCase2(): void
    {
        $result = nested(5);
        $this->assertEquals('small', $result);
    }

    public function testNestedCase3(): void
    {
        $result = nested(0);
        $this->assertEquals('zero', $result);
    }

}
