## 全体判定: CHANGES_REQUESTED

復号ロジック本体は概念設計の受理順序を概ね正しく実装しています。一方、施策 7 の gate は現状の設計だと「単一復号点」を機械的に保証できません。概念設計で約束した公開面 pin も詳細設計から欠落しています。

| 施策 | 判定 |
|---|---|
| 1. 失敗区分 | APPROVE |
| 2. 構造走査による復号 | APPROVE |
| 3. 復号契約テスト | APPROVE |
| 4. prompt 出力指示 | APPROVE |
| 5. canned 応答 | APPROVE |
| 6. 既存テスト更新 | REQUEST_CHANGES |
| 7. 単一性 gate | REQUEST_CHANGES |
| 8. 文書 | REQUEST_CHANGES |

## 施策 1: APPROVE

enum の分割、`invalid_json` の廃止、`SchemaViolation` の維持、固定 detail による本文非漏洩はいずれも妥当です。

`match` の全 case 列挙、TS 同期免除、既存の観測カテゴリ生成との整合にも問題は見当たりません。

## 施策 2: APPROVE

構造走査は次を正しく扱っています。

- 文字列内の括弧とコードフェンス
- バックスラッシュの偶奇
- 異種閉じ括弧
- 最初の JSON 値が完結した位置での走査終了
- JSON 妥当性の `json_decode(..., JSON_THROW_ON_ERROR)` への委譲
- 応答本文を例外へ含めない境界

[Suggestion] `skipWhitespace()` が認めるのは SP / HT / LF / CR の4文字です。受理文法の「空白」を「JSON whitespace（SP、HT、LF、CR）」と明記してください。Unicode 空白まで受理するようにも読める現状の文言を避けられます。

[Suggestion] 次の境界テストも追加すると、走査器と `json_decode` の責務分離を強く固定できます。

- 深さ513以上による `JsonException` → `syntax_broken`
- 不正UTF-8を含むJSON文字列 → `syntax_broken`
- 4個以上の開閉フェンスを、宣言どおり個数対応なしで受理するケース

## 施策 3: APPROVE

受理5件・拒否14件は主要な分岐を網羅しています。`FencedLlmResponse` の導入も、本番コードをテスト都合に依存させず fixture の形だけを集約しており妥当です。

非漏洩テストについては、単体層の `getMessage()` / `userMessage()` に加えて施策6で統合層を補ってください。

## 施策 4: APPROVE

4つの構造化 prompt のみを変更し、自由文 prompt を対象外とする切り分けは正しいです。既存の canary、防御指示、token・timeout pin にも干渉しません。

## 施策 5: APPROVE

canned 応答は「応答を作る側」なので本番用の専用 helper を持つのが妥当です。テスト用 `FencedLlmResponse` との重複も、production から `tests/` を参照しないため必要な分離です。

## 施策 6: REQUEST_CHANGES

[Warning] テストファーストの順序が詳細設計内で矛盾しています。

施策6では「既存テストを書き換えて実装前に赤を確認」としていますが、全体の実装順は「3 → 1 → 2 → 6」で、既存契約テストの書き換えが復号実装後です。

修正案:

1. 施策3の新規テストと、施策6の契約反転テストを先に追加する
2. 赤を確認する
3. 施策1、2を実装する
4. 残りの fixture 包装を行う
5. pipeline の統合テストを緑にする

[Warning] 概念設計で約束した i9 の統合層テストが詳細設計から落ちています。詳細設計は例外の2メソッドだけを検査していますが、概念設計では以下も固定するとしています。

- 再試行ログの context
- 終端ログ
- `analysis_jobs.error`

修正案: sentinel を含む fake 応答を pipeline に流し、少なくとも retry と終端失敗について、ログ context とDBの `error` に sentinel が存在しないことを Feature テストへ追加してください。可能なら6区分の dataset にします。

[Suggestion] 「禁止事項3＝既存テストの削除・上書き」という説明は、提示された AGENTS.md の禁止事項3と一致しません。ここは「旧契約を新しい不変条件へ置換するための意図的なテスト更新」とだけ説明する方が正確です。

## 施策 7: REQUEST_CHANGES

[Critical] 概念設計で約束した「緩い公開入口を追加できない pin」が6検査に含まれていません。

`DECODE_POINT_PATH` は検査5からファイル全体が除外されるため、将来次のようなメソッドを追加しても gate は緑のままです。

```php
public static function decodeLenient(string $text): array
```

修正案: `LlmJson` の public static surface を完全一致で pin してください。少なくとも次を固定します。

- public method は `decode` と `schemaViolation` のみ
- `decode` は public static
- 引数は必須の `string` 1つ
- 戻り値は `array`
- 新しい decode 系メソッドの追加で赤になる負例

[Critical] 登録済み receiver が実際に `LlmJson::decode()` を呼ぶことを検査していません。

検査3が保証するのは、`executeSync()` の結果が `fromLlmText()` の引数へ入るところまでです。その後 receiver から `LlmJson::decode()` を削除し、別のデコーダを使っても現在の gate は通り得ます。

修正案: 各 receiver について、callable scope 内で次を完全一致検査してください。

- `LlmJson::decode` の完全修飾 static call がちょうど1件ある
- `fromLlmText(string $text)` の `$text` が、その呼び出しの直接引数としてだけ使用される
- `$text` の別変数への代入、別サービスへの受け渡し、2回目の利用は失敗する

これにより生の応答文字列が復号点以外へ流れる経路を構造的に閉じられます。

[Critical] `json_decode` 検出は完全修飾呼び出しと関数 alias を見逃します。

設計上の検出対象は `T_STRING` の `json_decode` だけなので、少なくとも以下がすり抜けます。

```php
\json_decode($text, true);

use function json_decode as decodeJson;
decodeJson($text, true);
```

修正案:

- `T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` も扱う
- `use function`、group use、alias を解決する
- 解決できない関数呼び出しを未解決として返す
- `\json_decode`、alias、group-use alias の負例を追加する
- 動的関数呼び出しを保証外とするなら、gate の docblock に明記する

既存予定の `my_json_decode` / `json_decode_all` / `$o->json_decode` は誤検出防止には有用ですが、この回避経路の検出力を証明しません。

[Warning] `X::make(...)->executeSync()` の受け手解決アルゴリズムが十分に規定されていません。`make()` の引数にネストした呼び出し、配列、名前付き引数、クロージャがある場合、単純な直前探索では `X` に戻れません。

修正案: 対応する括弧を後方へバランス走査し、`X::make` の static-call site と結び付ける手順を明記してください。少なくとも以下の正例・未解決例が必要です。

- ネストした関数呼び出しを引数に持つ `make`
- 配列・名前付き引数
- 複数行
- 対応不能な括弧・動的 receiver は未解決として失敗

[Warning] inventory の `kind` が単なる `string` です。誤記時にどの検査にも該当せず、分類漏れになる危険があります。

修正案: テスト側 enum を使うか、`decoded / provider_shape / free_text` の完全一致検査を最初に置いてください。分類ごとの必須項目も検証してください。

## 施策 8: REQUEST_CHANGES

[Warning] 旧ログの時期表現が不整合です。

- 施策1: 「2026-08 以前」
- 施策8: 「2026-08 より前」

後者では2026年8月中のデプロイ前ログが対象外になります。

修正案: 月ではなく「本変更の本番デプロイ以前」またはデプロイ日時・リリースSHAを境界にしてください。

[Warning] 「マージコミットの revert」は、squash merge や rebase merge の運用では成立しません。

修正案: 「本変更を導入したリリースコミットまたは変更一式を revert」と記述し、復号点だけを部分的に緩めないことを明示してください。

## 確認できた適合事項

- DTO / JsonResource / Inertia の境界変更なし
- TypeScript enum 同期不要の根拠あり
- tenant・認可・入力payloadの新しい境界なし
- PromptDefense → GuardedPrompt の実行経路を維持
- UI変更なしのため DESIGN.md / Atomic Design の追加対応なし
- DB migration 不要
- 課金を伴う互換性確認をユーザー承認待ちにする扱いは適切

主な修正対象は施策7です。公開面 pin、receiver から `LlmJson::decode()` への直接接続、完全修飾・alias を含む `json_decode` 検出の3点を入れれば、正典 i1/i4 を機械的に支える設計になります。