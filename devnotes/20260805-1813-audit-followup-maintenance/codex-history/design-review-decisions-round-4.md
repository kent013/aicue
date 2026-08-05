# 対応マトリクス: design-review Round 4 (APPROVED)

Round 4 で **全体判定 APPROVED**。全施策 (A1 / A2 / B1 / B2 / C1 / C2 / D1) が APPROVE。
新たな Critical / Warning はなし。

## [Suggestion] token の `orphans` キーを `drop_targets` にする

- 判断: **対応する**
- 根拠: 指摘のとおり。実際の対象は `Orphan` 分類だけでなく
  「`--include-hash` で名指しされた `Orphan` / `Unlabeled`」なので、
  `orphans` というキー名は意味を取り違えさせる。安全性には影響しないが、
  **名前がその役割を示すべき**（思考原則「機能の名前に立ち返れ」）。
- 対応内容: canonical JSON のキーを `orphans` → **`drop_targets`** に変更し、
  理由をコメントで残した。

## 最終確認 (app-design Phase 2-5)

### 使命への寄与

本バッチは機能を増やさない。効くのは「SOP → シナリオ → 撮影」を実装する
**次サイクルの速度と信頼性**である。

| 施策 | 使命への寄与 |
|---|---|
| A1 / A2 | 日本語コメントを 1 行足しただけでゲートが落ちる偽赤、レーンが理由不明で落ちる偽赤を除去する（実装者が原因追跡に溶かす時間を先に潰す） |
| B1 | bug-hunt が認証面（登録 / ログイン / 削除 / 再認証 / 初回パスワード設定）を探索対象に戻す。**IDOR・詰みが最も出やすい面**が現在まるごと監査外 |
| B2 | AGENTS.md だけを読んだエージェントが T103 の中核契約（認可 gate / 層 2 の順序）を知らないまま route を足す動線を、テストファースト（思考原則 5）へ戻す |
| C1 / C2 | teardown が常時失敗 → 強制撤去が常態化 → 孤児 DB が単調増加、という運用事故の**経路そのもの**を閉じる |
| D1 | supply-chain gate を accept-risk 0 のまま advisory 0 にする |

### 禁止事項チェック

| # | 禁止事項 | 本設計での扱い |
|---|---|---|
| 1 | テストなしの実装完了 | **全 7 施策にテストを定義済み**。新規ゲート 4 本（P1〜P15 / CB1〜CB7 / V0〜V7 / N1〜N7）、新規単体テスト 1 本（T-C2-1〜T-C2-22）、既存契約スイートへの追加 2 件（C25 / C26）、CI inventory 1 件（W16）。doc のみの変更も `bug-hunt-inventory-check.sh` exit 0 / `audit:gate` 緑を受入条件に置いた |
| 2 | PHPStan エラーの widen・baseline 化 | 該当なし。新規コードは `list<...>` / `final readonly class` / `enum` で型付けし、外部入力は境界で `Assert` する |
| 3 | dev DB への破壊操作をエージェント判断で実行 | **DROP の実行責務を `drop-test-db.php` から分散させない** / 新規 DDL は非破壊の `COMMENT ON DATABASE` のみ / **`--include-hash` で名指しされない hash は 1 件も落ちない** / `--confirm` は lock 下で再計算して照合 / **`--apply` は LLM が実行しない**（ユーザー実行またはユーザーの明示承認のみ）を usage / AGENTS.md / scripts/README.md の 3 箇所に明記 |
| 4 | `response()->json()` の直書き | 該当なし（アプリのドメインコードを 1 行も変更しない） |
| 5 | Prism 直呼び | 該当なし |
| 6 | prompt 文字列のコード直書き | 該当なし |
| 7 | `redirect()->intended()` | 該当なし |
| 8 | 必須条件未充足で disabled にする UI | 該当なし（UI 変更なし）。ただし B1 の bug-hunt ストーリーに「押下できること（disabled にしていないこと）」の検出項目を追加している |
| 9 | Artifact の使用 | 使用していない（成果物はリポジトリ内の devnotes ファイル） |

### コーディングルールの反映

- PHPStan level 10: 各施策の「PHPStan 適合チェック」欄で個別に確認
- Pest / RefreshDatabase / `--parallel`: 新規テストはすべて **DB を触らない**（ファイル・git index・純関数）ため、個別 `DatabaseTransactions` の使用は発生しない
- Factory: 新モデルなし（Factory 追加不要）
- DTO: `TestDatabaseCandidate` / `TestDatabaseDecision` を `final readonly class`、分類を `enum` で表現（配列返却なし）
- DESIGN.md / Atomic Design: **UI 変更なし**のため該当なし
