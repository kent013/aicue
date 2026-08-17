# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical なし / Warning 9 件。**全件対応**した。

## [Warning] 横断: 正典照合の実行順が不明確 (未確認の公開 API をテストで先に固定してしまう)
- 判断: 対応する
- 根拠: 正当。名前が変わればテスト 3 本を書き直すことになり、テストファーストの順序が無意味になる。
- 対応内容: 施策一覧の前に **Step 0 (正典照合)** を置き、実装順を
  `Step 0 → 1 → 2 → 3 (赤の確認) → 4 → 5` に改めた。lctl が取得不能なままなら
  **完了ではなく blocked** とすることと、照合結果 (正典の世代・取得日時・3 点の比較) を
  `codex-history/canon-reconciliation.md` に残すことを完了条件へ明記した。

## [Warning] 施策 1: 定数を参照しつつ payload() を迂回する実装を検出できない
- 判断: 対応する
- 根拠: 反例 (`foreach (KINDS as $kind) { $flash[$kind] = session()->get($kind); }`) は
  提示された検査を全部通る。指摘のとおり単一出典が空洞化する。
- 対応内容: middleware に対する検査を 3 つに増やした。
  (a) `FlashNotificationRelay::payload(` の出現が**ちょうど 1 回** /
  (b) `FlashNotificationRelay::SHARED_PROP_KEY` を使っている /
  (c) middleware に `session` / `uuid` の**呼び出し名が 1 つも無い**。
  併せて走査を**字句 (token_get_all) 単位**に変え、コメント・docblock を数えないようにした
  (既存 `ForbiddenStatementTokenInvariantTest` と同じ流儀)。

## [Warning] 施策 1: 中核ヘルパが `/* … */` のままで fail-closed 性を確認できない
- 判断: 対応する
- 対応内容: 走査ヘルパの契約を設計に確定した (対象列挙の失敗・読み込み失敗・対象 0 件は
  いずれも `RuntimeException` / 戻り値は `list<string>` で重複なし昇順 /
  文字列リテラルの字句だけを数える)。

## [Warning] 施策 2: extractKinds に degenerate PASS の負のコントロールが無い
- 判断: 対応する
- 対応内容: 語彙抽出にも負のコントロールを 2 つ (定義が無い入力 / 空配列) 追加し、
  併せて**受け付ける定義形式を 1 つに固定**した (`public const array KINDS = [ … ];`)。
  正例 fixture も持たせ、抽出器自身の変更をレビューできるようにした。

## [Suggestion] 施策 2: Pint 整形後の実形式を fixture として持つ
- 判断: 対応する (上と同じ対応で吸収)

## [Warning] 施策 3: renderedFlash の `mixed` 返しが PHPStan level 10 で安全と言い切れない
- 判断: 対応する
- 根拠: 正当。`expect()->toBeArray()` は静的解析の narrowing ではない。
- 対応内容: 提案どおり `array<string, mixed>` を返し、配列でない各段で `RuntimeException` を
  投げる形に変えた。

## [Warning] 施策 3: 非文字列の正規化を KINDS[0] だけで検査している
- 判断: 対応する
- 対応内容: 全 `KINDS` × 代表値 (配列 / 整数 / 真偽値 / オブジェクト) を dataset で回す形に変えた。

## [Warning] 施策 3: withSession は一時メッセージの寿命を検査していない / 未使用 import
- 判断: 対応する
- 対応内容: 既存ケースの名称を「session に置かれた値」に直し、**本物の一時メッセージ経路**
  (`redirect()->with(...)` を返すテスト用 route → 着地で共有 prop を読む → もう一度読むと消えている)
  を 1 ケース追加した。未使用 import (`Inertia`) は落とし、`Route` は追加ケースで使う。

## [Warning] 施策 5: readFlash の実行時検査と戻り値型が一致していない (配列・非文字列が通る)
- 判断: 対応する
- 根拠: 正当。`typeof x === "object"` は配列を通し、`["壊れた値"]` は truthy なので
  `addToast` に非文字列が渡る。設計のリスク記述も事実と違っていた。
- 対応内容: `readFlash` を**正規化器**に変えた (配列を拒否し、語彙の各値と見分けキーを
  `string | null` へ倒す)。`consumeFlash` にも `typeof === "string"` の確認を入れ、
  誤ったリスク記述を書き直した。

## [Warning] 施策 5: readFlash の利用強制が無く、prop 名の定数が再び飾りになる
- 判断: 対応する
- 対応内容: TS レーンの検査へ deny-by-default の走査を 2 つ追加した
  (`flash-to-toast.ts` 以外に `.flash` の直読みが無い / 3 レイアウトが `readFlash` を使う /
  走査不能・対象 0 件は fail)。

## [Warning] 施策 5: 変更ファイル一覧とテスト計画の矛盾 (既存テスト更新不要と言いつつ追加する)
- 判断: 対応する
- 対応内容: `tests/js/lib/flash-to-toast.test.ts` を施策 5 の変更ファイルへ入れ、
  「既存テストの更新は不要」の記述を全て削除した。追加ケース (非 object / 配列 /
  欠落 / 非文字列の種別値 / 非文字列の見分けキー) を明記した。
