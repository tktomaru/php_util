<?php
// src/MyFunctions.php

/**
 * 整数 $x が 10 より大きいかどうか判定して文字列を返す
 *   $x > 10: "big"
 *   else :   "small"
 */
function cmpGt(int $x): string
{
    if ($x > 10) {
        return 'big';
    } else {
        return 'small';
    }
}

/**
 * 文字列 $y が "foo" と等しいかどうか判定して真偽を返す
 *   $y === 'foo': true
 *   else:          false
 */
function cmpEq(string $y): bool
{
    if ($y === 'foo') {
        return true;
    } else {
        return false;
    }
}

/**
 * 配列 $a の中に $item が含まれているかを判定して文字列を返す
 *   in_array: "yes"
 *   else   : "no"
 */
function inList(mixed $item, array $a = [1, 2, 3]): string
{
    if (in_array($item, $a, true)) {
        return 'yes';
    } else {
        return 'no';
    }
}

/**
 * 整数 $z に応じて文字列を返す
 *   case 1: "one"
 *   case 2: "two"
 *   default: "other"
 */
function caseTest(int $z): string
{
    switch ($z) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'other';
    }
}

/**
 * ネストした if で複数パターンを返す例
 *   $n > 0:
 *     $n > 5 ? 'big' : 'small'
 *   それ以外: 'zero'
 */
function nested(int $n): string
{
    if ($n > 0) {
        if ($n > 5) {
            return 'big';
        } else {
            return 'small';
        }
    }
    return 'zero';
}
