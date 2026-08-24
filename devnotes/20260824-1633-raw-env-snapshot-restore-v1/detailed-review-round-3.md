# 全体判定: CHANGES_REQUESTED

Round 2 の5件は、意図としてすべて適切に反映されています。特に g-2/g-3 の priming、分割代入の lvalue 判定、D50 の限定表現は解消済みです。

残る争点は施策1の構造検査だけです。

## 施策 1: REQUEST_CHANGES

- [Critical] h-3 が主張する契約より、構造検査の検出力が弱いです。  
  現在固定するのは「唯一の `throw` が `foreach` の外にあること」だけです。次の退行はいずれも緑になり得ます。

  ```php
  foreach ($this->state as $saved) {
      if ($applied === false) {
          break;
      }
  }

  throw new RuntimeException(...);
  ```

  ```php
  foreach ($this->state as $saved) {
      // 失敗を蓄積しない
  }

  throw new RuntimeException(...); // 無条件送出
  ```

  したがって「最初の失敗で止まらない」「失敗を蓄積する」「失敗時だけまとめて送出する」までは保証できません。

  修正案: h-3 で少なくとも以下を固定してください。

  - 復元ループ内に `throw` / `return` / `break` / `continue` がない
  - `$failed[] = $key` がループ内にちょうど1件ある
  - その追加が `$applied === false` の条件分岐内にある
  - ループ後の `$failed !== []` 条件分岐内に唯一の `throw` がある
  - その `throw` 以外にメソッドを途中終了させるトークンがない

  ここまで構造化しない場合は、保証表を「唯一の `throw` が復元ループの外にあること」まで狭める必要があります。ただし、それでは例外契約全体のテストが不足するため、前者を推奨します。

- [Warning] 例外の `previous` 連結が検査されていません。  
  `with()` が `restore($bodyError)`、`captureAndClear()` が `restore($e)` を呼ぶことや、`restore()` が `RuntimeException` の第3引数へ `$previous` を渡すことを外しても、現在の h-1〜h-3 は緑になります。

  修正案:

  - `with()` の `restore` 呼び出し引数が `$bodyError`
  - `captureAndClear()` の引数が `$e`
  - `RuntimeException` 構築の第3引数が `$previous`

  であることを構造検査へ追加してください。引数式を解決できない場合は fail-closed にします。

- [Warning] `foreachOver()` の契約では `$this->state` を表現できません。  
  API は「`foreach (<変数> as …)`」を探すものですが、h-3 の対象は単一変数ではなくプロパティアクセスです。

  修正案: 次のいずれかへ明確化してください。

  - `foreachOverExpression($tokens, ['$this', '->', 'state'])`
  - `foreachOverProperty($tokens, '$this', 'state')`
  - `foreachOver()` が変数だけでなく正規化済みの式トークン列を受ける契約に変更

  `$this->state` の正例と、`array_values($this->state)` のような非直接走査の負例も必要です。

- [Suggestion] 実装手順の段4は h-1 / h-2 だけでなく、今回追加した h-3 も契約テストへ加える記述に更新してください。

## 施策 2: APPROVE

lvalue の根と添字内の読み出しを分ける設計で、前回の誤検出は解消されています。可変関数呼び出しを保証外とする範囲も一貫しています。

## 施策 3: APPROVE

追加指摘はありません。

## 施策 4: APPROVE

追加指摘はありません。

## 施策 5: APPROVE

D50 の見出し、説明、不変条件が同じ限定表現へ統一されました。追加指摘はありません。