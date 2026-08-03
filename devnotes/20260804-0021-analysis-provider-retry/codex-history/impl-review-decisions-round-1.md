# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 1 / Suggestion 1)。

## [Suggestion] `userMessageFor()` の `[500,502,503,504]` が `isTransient()` の status 定義と drift しうる
- 判断: **対応する**
- 根拠: 指摘は正しい。retryable 判定 (`TRANSIENT_HTTP_STATUSES`) と文言分岐のリテラル配列が
  別々に書かれており、片方だけ増減させても CI が検出できない (= 設計の
  「status の解釈を二重管理しない」意図が実装で守られていない)。
- 対応内容: 定数を目的別に 2 つへ分割し、**両メソッドが同じ定数を読む**形にした。
  - `private const TIMED_OUT_HTTP_STATUS = 408;` (文言 = `timedOut()`)
  - `private const PROVIDER_BUSY_HTTP_STATUSES = [500, 502, 503, 504];` (文言 = `providerBusy()`)
  - retryable 集合 = 上記 2 つの和集合と定義し `isTransient()` がそれを返す。
    これで「retryable なのに文言が default に落ちる」「文言はあるのに retry されない」の
    両方向の drift が構造的に起こらなくなる。
  - 詳細設計の `TRANSIENT_HTTP_STATUSES = [408, 500, 502, 503, 504]` からの逸脱
    (deviations_from_design に記録)。判定集合そのものは設計と同一。
- 追加テスト: `(A) transient: generic PrismException (previous 408) ×3 → failed + timeout 文言`
  を Feature テストへ追加 (408 分岐が未検証だったため。禁止事項 1)。

## [Warning] `pnpm test` / `pnpm build` の完了結果をマージ前に確認すること
- 判断: **対応する**
- 対応内容: コミット前に `pnpm test` / `pnpm build` の完了を待って結果を確認する
  (差分にフロント変更は無いが、規約どおり全検証コマンドを回す)。

## 横断チェック (Codex 判定を追認)
- セキュリティ不変条件 #7 (チケット 2 フェーズ): リトライは `startJob` (reserve) の後・
  `finalize` (commit) の前に閉じており予約行に触れない。Feature テスト (D) 群で
  「succeeded でも予約 1 件・commit 1 件」「failed なら Released・commit 0 件」
  「SIGALRM 相当の中断はどの回復順でも同じ最終会計状態」を固定済み。
- 禁止事項 5 / 6: Prism facade 直呼びなし・prompt 文字列のコード直書きなし (YAML 更新のみ)。
- DESIGN.md / Atomic Design: フロント変更なしのため該当なし。
