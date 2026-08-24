# 赤→緑の実測記録 (実装フェーズで埋める)

本ファイルは**実装フェーズの成果物**である。設計フェーズでは骨組みだけを置く。
AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の (1) は
「テストファーストで先に赤くしてから本体を書く」であり、
**赤を見たことの実測を残すまでが完了条件**である。

記録する項目は詳細設計 §テストファースト手順 の段 1 / 3 / 4 / 6 に対応する 4 件。

## 段 1: 自己テストが先に赤い (読み取り器が無い状態)

- コマンド:
- 結果:

## 段 3-a: `<= 0` 分岐を一時削除 → 赤

- 期待: `zero.yaml` / `negative.yaml` のラベルが集合から落ちて 1 本目の test が赤
- コマンド:
- 結果:

## 段 3-b: `is_int()` → `is_numeric()` へ緩める → 赤

- 期待: **`numeric-string` と `float` の 2 本**が集合から落ちる
  (`is_numeric(true)` / `is_numeric(null)` はどちらも false なので bool / null は違反のまま)
- コマンド:
- 結果:

## 段 4: 到達証明に架空の名前を足す → 赤

- 期待: `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` へ `sop-extract-v9.yaml` を足すと
  「走査の列挙結果に既知の prompt YAML が含まれていません」で赤
- コマンド:
- 結果:

## 段 6: 乖離登録の前に突合 gate が赤い

- 期待: `TemplateDivergenceFingerprintTest` が
  「一致していた状態から新たに不一致になった、未登録かつ非債務のパス」で赤
- コマンド:
- 結果:

## 最終の全数

- `composer test` / `composer phpstan` / `vendor/bin/pint --test`:
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`:
