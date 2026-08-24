# 全体判定: CHANGES_REQUESTED

Round 3 の指摘内容は概ね反映されていますが、施策1の構造走査 API と自己検査に3点の不整合が残っています。

## 施策 1: REQUEST_CHANGES

- [Warning] 制御フロートークンを検査する API の宣言と利用要件が一致していません。  
  docblock は「`throw` / `return` / `break` / `continue` の出現位置（token id 指定）」としていますが、宣言は次の形です。

  ```php
  public static function throwTokens(array $tokens): array;
  ```

  これでは `return` / `break` / `continue` を指定できず、`restore()` の5条 (1)(5) を実装できません。

  修正案: 例えば次へ変更してください。

  ```php
  public static function controlFlowTokens(array $tokens, int $tokenId): array;
  ```

  許可する token id を `T_THROW` / `T_RETURN` / `T_BREAK` / `T_CONTINUE` に限定し、それ以外は例外にすると fail-closed になります。

- [Warning] `with()` の例外連結は、`restore($bodyError)` の引数だけでは固定できません。  
  次の退行でも現在の検査を通ります。

  ```php
  } catch (Throwable $e) {
      $bodyError = null;
      throw $e;
  } finally {
      $snapshot->restore($bodyError);
  }
  ```

  これでは復元失敗時に本体例外が `previous` へ渡りません。

  修正案: `with()` の catch 本体について、次も構造固定してください。

  - `$bodyError = $e` がちょうど1件ある
  - その代入が catch 本体内にある
  - catch 本体の唯一の `throw` が `$e` を再送出する

  対応する負例として、`$bodyError = null`、代入自体の削除、別例外の送出を追加してください。

- [Warning] 構造走査器の「正例1」が、新しい h-1 の契約を満たしていません。  
  現在の正例は次の形です。

  ```php
  try {
      foreach ($changes …) {
          self::apply(…);
      }
  } finally {
      $snapshot->restore();
  }
  ```

  しかし h-1 は `catch=1` と `restore($bodyError)` を必須にしています。このままでは、正例が本番判定と異なる弱い分岐しか検査しないか、本番と同じ判定へ通すと正例自身が失敗します。

  修正案: 正例1を実際の契約と同形にしてください。

  ```php
  try {
      foreach ($changes as $key => $channels) {
          self::apply($key, $channels);
      }
  } catch (Throwable $e) {
      $bodyError = $e;
      throw $e;
  } finally {
      $snapshot->restore($bodyError);
  }
  ```

  `try/catch/finally` の件数、代入、再送出、restore 引数まで同じ合成入力で検査するのが確実です。

## 施策 2: APPROVE

変更なし。前回判定を維持します。

## 施策 3: APPROVE

変更なし。前回判定を維持します。

## 施策 4: APPROVE

変更なし。前回判定を維持します。

## 施策 5: APPROVE

変更なし。前回判定を維持します。