# 対応マトリクス: conceptual-review Round 1

判定は **APPROVED**。Critical は 0 件。Warning 5 件・Suggestion 2 件をすべて処理した。
Warning はいずれも「詳細設計で詰めろ」という要求なので、概念設計に**受け入れ条件**として
明文化し、詳細設計の該当施策へ引き継ぐ。

## [Warning] 禁止事項 8 (disabled) との衝突可能性

- 判断: **対応する**
- 根拠: 指摘のとおり、新しい操作点 (ハンドル) を足すときに「ドラッグできない状態だから
  disabled」を作り込むのは禁止事項 8 と同型になる。既存 ▲▼ は端でも disabled にしておらず
  (`moveStep` は境界で早期 return するだけ)、その挙動は正しいので**変えない**。
- 対応内容: 概念設計に受け入れ条件 A1 を追記。
  「ハンドル・▲▼ ともに disabled にしない。端での移動要求は無反応ではなく
  aria-live 領域で『これ以上、上へは移動できません』と告知する」。
  テイク側の PATCH 失敗は既存の `role="alert"` (`take-strip-error`) に出す (現状どおり)。

## [Warning] pointer lifecycle の解放漏れ (pointercancel / destroy / autoscroll のリーク)

- 判断: **対応する**
- 根拠: 実装上いちばん壊れやすい箇所で、指摘の 4 点はそのまま受け入れ条件になる。
- 対応内容: 受け入れ条件 A2 として 4 点を明文化
  (`pointerup` / `pointercancel` / `Escape` / destroy の全経路で必ず解放 /
  スクロール後も `getBoundingClientRect()` 実測で挿入位置が壊れない /
  pointer capture 非対応環境でも同じ callback 契約で終わる / rAF・listener を残さない)。
  詳細設計では `pointer-drag.ts` に **単一の終了関数 `finish(commit: boolean)`** を置き、
  すべての終了経路をそこへ集約する形で書く。

## [Warning] iOS Safari 実機確認を受け入れ条件に格上げせよ

- 判断: **対応する**
- 根拠: `docs/supported-browsers.md` が「撮影 PWA = iOS Safari が最重要」「WebKit レーンの
  green を iOS Safari 対応の実証と言い換えない」と定めており、指摘と一致する。
- 対応内容: 受け入れ条件 A3 に格上げ。「自動テストは純関数 + コンポーネント配線を固定する。
  iOS Safari 実機確認は本 devnotes に日時・端末・OS・結果を記録する。記録が無い状態を
  『テスト済み』と書かない」。

## [Warning] 共通化しすぎると pointer-drag.ts が画面都合を吸い込む

- 判断: **対応する**
- 根拠: 妥当。共通化の境界を先に線引きしておかないと、後から片方の画面の都合が
  共通モジュールへ漏れる (思考原則 4「別物の概念を似ているからで統合しない」)。
- 対応内容: 受け入れ条件 A4 として**共通化の境界**を明記。
  共通に置くのは (i) pointer lifecycle (ii) 挿入位置計算 (iii) `moveItem` の 3 つだけ。
  保存経路・文言・aria-live メッセージ・見た目・`position` 変換は各 feature 側に残す。

## [Warning] position の基準 (0-based / 最終 index) の off-by-one

- 判断: **対応する**
- 根拠: ここは実際に一度きり間違えると気付きにくい。サーバ
  (`CaptureTakeService::reorderWithinCut`) は「対象を除いた配列に position で splice する」
  実装で、結果として **position = 移動後の全体配列での 0 始まり index** と一致する。
  この同一性は自明ではないので、テストで固定する価値がある。
- 対応内容: 受け入れ条件 A5。`list-reorder.ts` は
  「挿入 index (gap 基準 0..n)」と「最終 index (0..n-1)」を**別の関数**として持ち、
  `toFinalIndex(insertion, from)` の同一性をテストで固定する。`TakeStrip` がサーバへ渡すのは
  最終 index である旨をコードコメントと設計書に明記する。

## [Suggestion] 効果は「操作単位」で測る方が堅い

- 判断: **対応する** (表現の修正のみ)
- 対応内容: 期待効果を「任意位置への移動が 1 ジェスチャになる」「採用候補 (先頭) への
  引き上げが 1 ジェスチャになる」という操作単位の言い方へ書き換えた。

## [Suggestion] 使命との整合性は妥当

- 判断: **見送る** (指摘なしのため対応不要)
