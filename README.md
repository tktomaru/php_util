# PHP テストコード自動生成ツール

## 概要
このプロジェクトは、既存の PHP ソースコードを静的解析し、自動で PHPUnit テストコードの雛形（および簡易的な分岐ケースの引数／期待値）を生成する CLI ツールです。  
クラスの public メソッド、グローバル関数に対して以下のパターンに対応しています。

- `if ($x > 定数) … else …`  
- `if ($y === 定数) … else …`  
- `if (in_array($item, [...])) … else …`  
- `switch($z) { case …; default … }`  
- ネストした `if`／`elseif`／`else`  
- 関数直下のデフォルト `return`  

自動生成後は、テストメソッド内に具体的な引数と期待値が埋め込まれた状態で出力されるため、最小限の修正で実際の単体テストを完成できます。

---

## 主な機能

- PHP ソースを AST（抽象構文木）解析  
- クラスの public メソッドを検出し、`TestCase` 骨子を生成  
- グローバル関数の分岐ロジックを解析して、  
  - 引数例  
  - 期待値  
  を自動挿入  
- 解析不能な箇所は `markTestIncomplete()` 付きの雛形として残す  

---

## 必要要件

- PHP 8.0 以上  
- ext-openssl, ext-mbstring が有効化された CLI PHP  
- Composer  
- 以下の Composer パッケージ（開発用）  
  - `nikic/php-parser`  
  - `phpunit/phpunit`  

---

## インストール

1. リポジトリをクローン  
2. Composer 依存をインストール
3. テスト自動生成スクリプトを実行
 - php generate_tests.php <テスト対象の PHP ファイル>
4. PHPUnit でテストを実行
 - ./vendor/bin/phpunit --colors=always tests/GlobalFunctionsTest.php

---

## カスタマイズ

新しい分岐パターン を追加したい場合
generate_tests.php 内の analyzeFunctionBranches()／parseBranches()／processIfBranch() などのロジックを拡張してください。

クラス検出やネームスペース対応 を調整したい場合
TestGeneratorVisitor クラスをカスタマイズできます。

