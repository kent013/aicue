# 対応マトリクス: design-review 確認ラウンド (Round 8 / 最終)

> Round 7 で出た Warning (非 `ShouldQueue` Mailable の `$afterCommit` が D5 の母集団から漏れる)
> と Suggestion (外部代入の負のコントロール) を反映した後の再確認ラウンド (one-shot)。

## 確認ラウンドの結果 (Codex 返答: `detailed-review-round-8.md`)

- **全体判定: APPROVED**
- **Critical: 0 件 / Warning: 0 件**
- Round 7 の Warning・Suggestion はいずれも解消と評価された。
- 母集団拡張の副作用 (既存 2 gate との関係 / autoload 副作用) についても
  「大筋問題なし」と確認された。

## [Suggestion] テスト 7c を strict superset として assert しない

- 判断: **対応する (テスト表現の精度の問題であり、そのまま書くと実装時に誤った assert を生む)**
- 根拠: 現行の Mailable 2 クラスはいずれも `ShouldQueue` を併記しているため、
  `shouldQueueClasses() ∪ mailableClasses()` は `shouldQueueClasses()` と**同一集合になりうる**。
  「真に含む (strict superset)」で固定すると、正しい状態でテストが落ちる。
- 対応内容: テスト 7c の文言を
  「`deferralCandidateClasses()` は **`unique(shouldQueueClasses ∪ mailableClasses)` と一致し**、
  Mailable 全件を含む」へ書き換えた。

## [Suggestion] `mailableClasses()` に `isInstantiable()` を要求しない

- 判断: **対応する (指摘に同意。除外すると 0 件 pin が抜ける)**
- 根拠: first-party の abstract な base Mailable は `$afterCommit` の既定値や
  宣言的迂回 interface を concrete subclass へ**伝播させる carrier** であり、
  そこを母集団から外すと親クラス側に置かれた迂回が検出できない。
  vendor の `Illuminate\Mail\Mailable` 本体は `app/` 探索に入らないので偽陽性にはならない。
- 対応内容: M7 の波及変更に「`isInstantiable()` は要求しない」を明記した。
  **`shouldQueueClasses()` 側の `isInstantiable()` は既存挙動なので変更しない**
  (既存 2 gate の母集団を動かさない方針を維持)。

---

## 最終状態

**詳細設計の最終判定: APPROVED** (Codex Round 8 / Critical 0 / Warning 0)。

合議の経緯:

| ラウンド | モード | 判定 | 主な指摘 |
|---|---|---|---|
| 1〜5 | セッション | CHANGES_REQUESTED | 母集団境界 / 絶対パス契約 / collector 方式 ほか |
| 6 | one-shot 確認 | CHANGES_REQUESTED | Warning 1: `$afterCommit` プロパティ経由の迂回 (→ D5 追加) |
| 7 | one-shot 確認 | CHANGES_REQUESTED | Warning 1: 非 `ShouldQueue` Mailable の `$afterCommit` (→ 母集団を Mailable へ拡張) |
| 8 | one-shot 確認 | **APPROVED** | Suggestion 2 件のみ (いずれも反映済み) |

実装フェーズへ進めてよい。実装時は §mutation 表 (#1〜#24) の赤化確認を必ず実施し、
結果を実装 PR の devnotes に記録すること。
