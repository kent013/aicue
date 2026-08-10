# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / high) の全体判定は **APPROVED**。[Critical] は 0 件。指摘は [Warning] 1 件。

## [Warning] schema 照合の配置が設計 (C1d 検査 3) と違う

- **判断**: 配置は変えない (反論)。ただし**理由の明記**という要求そのものは受け入れて対応する。
- **根拠**: `tests/Pest.php` は `RefreshDatabase` を **Feature / Unit にしか適用していない**
  (Architecture lane は `TestCase` のみで DB を使わない、と明記されている)。Architecture lane で
  `Schema::hasColumn()` を引くと migration 前の空スキーマを見ることになり、
  **検査が常に空振り (vacuous green) する** — 設計が最も避けたい形になる。
  よって「実在列の照合」だけを DB のある Feature lane へ移し、
  Architecture 側には **DB を必要としない構造検査** (修飾名 `{table}.{column}` の table 部が
  目録内の実在 target のテーブルであること = 検査 3) を残した。検査の総量は減らしていない。
- **対応内容**:
  - `tests/Architecture/BillingRetentionTargetInventoryTest.php` の冒頭 docblock
    「保証しないもの」に、**C1d から移設した事実と理由 (Architecture lane に DB が無く空振りするため)** を明記。
  - `tests/Feature/Billing/BillingRetentionHorizonTest.php` の該当テスト直前にも
    移設元と理由をコメントで明記 (どちらから読んでも辿れるようにした)。
  - 実装ノート (StructuredOutput の deviations) にも記録する。

## 追加ラウンドの要否

Round 1 で APPROVED かつ [Critical] 0 件のため、合議は Round 1 で終了する。
今回の対応はコメント (docblock) の追記のみで、検査ロジック・実装コードの挙動は 1 行も変えていない。
