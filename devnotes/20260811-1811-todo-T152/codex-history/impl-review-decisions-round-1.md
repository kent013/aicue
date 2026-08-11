# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 0 / Suggestion 1)

## [Suggestion] テスト 4 の 1 回目の POST にも着地 assertion を置く

- 対象: `tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php`
  「同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る」
- 指摘: 1 回目の POST に assertion が無いため、前段が失敗していても
  「後段 (2 回目) の失敗」として見えてしまう余地がある。
- 判断: **対応する**
- 根拠: 診断可能性の改善であり、契約を変えない。M-2c の mutation は
  「2 回目が 500 になる」ことで kill されており、1 回目への assertion 追加は
  その 1:1 対応を壊さない (1 回目は mutation 下でも必ず成功する = 既存行が無いため)。
- 対応内容: 1 回目の POST に `->assertRedirect($this->gateway->setupUrl)` を追加し、
  意図をコメントで書いた。再実行して 4 本とも緑であることを確認する。

## 「特に見てほしい点」への回答 (Codex の判定を記録)

| # | 論点 | Codex の判定 |
|---|------|-------------|
| 1 | catch を `UniqueConstraintViolationException` へ狭めたのは旧 `if (! isUniqueViolation) throw` と等価か | 等価。指摘なし |
| 2 | catch 節内 SELECT が pgsql 25P02 を踏まないか | 踏まない (tx/savepoint 巻き戻し後のため)。指摘なし |
| 3 | 同一性判定に `initiated_by_user_id` を入れない契約の妥当性 | 設計どおりで妥当。指摘なし |
| 4 | 代替実装 probe の予測外れの扱い (E-7 訂正不要という結論) | 整合。E-7 の OID 順前提は崩れていない |
| 5 | `use Illuminate\Database\QueryException;` の残置 | 既存テストが使っているため残置で問題なし |

→ Critical / Warning が 0 件で APPROVED のため、合議は Round 1 で終了する。
