# 対応マトリクス: design-review Round 3 (最終)

Round 3 の判定: **全施策 APPROVE / 全体 APPROVED**。

## [Suggestion] 「検証コマンド全 9 本」だが列挙は 10 本

- 判断: **対応する**
- 根拠: 数え間違い（`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` = 10 本）。
- 対応内容: 「全 10 本」に修正した。

## 残指摘

なし（Critical / Warning ともにゼロ）。

## 最終確認（app-design スキル Phase 2-5）

### 使命との整合

- 4 施策すべてが「編集者が PC 上でシナリオと素材を照合し、採用を確定する」ための縦串であり、
  使命（標準作業を起点に AI が教材設計し、撮影者スキルに品質を依存させない）に寄与する。
- 効果表現は「手動採用フローを PC 面へ着地させる」に限定済みで、
  自動採用・編集判断の不要化を約束していない。

### 禁止事項チェック

| # | 禁止事項 | 本設計での状態 |
|---|---|---|
| 1 | テストなしの実装完了報告 | Feature / Architecture / Vitest を施策ごとに列挙済み。目録登録（`NestedRouteDefenseInventory` / `AdoptedTakeReferenceInventory`）も完了条件に含む |
| 2 | PHPStan の widen / baseline | 施策ごとに適合チェック節を持ち、`getAttribute` + `Assert::integer` など既存作法に揃えた |
| 3 | dev DB への破壊操作 | 該当なし（migration も無し） |
| 4 | `response()->json()` 直書き | 新設は Inertia render のみ。書き込み応答は既存 Resource を再利用 |
| 5 | Prism 直呼び | LLM 呼び出しを一切追加しない |
| 6 | prompt 直書き | 該当なし |
| 7 | `redirect()->intended()` | 該当なし |
| 8 | 必須条件未充足で disabled | 採用・削除・アップロードすべて押下を受けてエラー表示する設計。テストでも「disabled でない」ことを固定 |
| 9 | Artifact の使用 | 成果物は `devnotes/` 配下のファイルのみ |

### コーディングルールの反映

- PHPStan level 10 / Pest / Factory 前提 / DTO パターン / `declare(strict_types=1)` /
  日本語コメント / アーリーリターンをすべて設計に織り込み済み。
- 新モデルは追加しないため Factory 追加も不要（migration も無し）。
