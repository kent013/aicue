Round 2 の指摘（Critical 1 件・Warning 4 件）に全て対応しました。再レビューをお願いします。

## 対応マトリクス

### [Critical] stale 回復 cron と finalize の競合（無課金 succeeded）→ 対応
- **terminal tx へ再設計**: materialize・チケット commit・job succeeded を**単一トランザクション**で原子的に確定する。
  - a. terminal tx 冒頭で job 行を lockForUpdate → **guard: status === running**。cron の failJob が先勝ちして failed なら、materialize も commit も succeeded も行わず終了（無課金 succeeded を構造的に排除）。
  - b. materialize（ScenarioService::materializeFromAnalysis。内側 DB::transaction は同一接続の savepoint。manual 行ロック + analyzing guard は本メソッド内）。
  - c. TicketLedgerService::commit。**非 Reserved は LogicException → terminal tx 全体 rollback（materialize も巻き戻る）→ catch 経路の failJob**。「report + 続行」は撤回し禁止と明記。
  - d. job succeeded + progress=100。
- failJob 側も **status ∈ {queued, running} のときのみ**遷移（succeeded/failed は no-op = 冪等）と明文化。terminal tx 勝ち後に cron / failed() フックが走っても安全。

### [Warning] updated_at はハートビートではない → 対応
- 表現を「各 step 遷移の progress 更新による**最終 step 更新時刻**を stale 判定に利用」へ修正。
- 安全性の本体は閾値ではなく **terminal tx の job 行ロック + status guard** であることを明記（誤回収されても生存 pipeline は materialize/commit を行わずに終了する）。専用 heartbeat カラムは追加しない。

### [Warning] SOP 差し替えと analyze の競合 → 対応
- source-documents store も **VideoManual 行を lockForUpdate() した同一 tx 内**で状態確認（{draft, ready}）+ SourceDocument 行作成を行う（analyze trigger と直列化）。
- trigger の「最新 document 選択」も同じ行ロック下で **latest('id')**（決定的順序）に固定。

### [Warning] 100,000 字は token 上限を保証しない → 対応
- token budget 導出に変更: モデル context 200,000 token − 出力予約 16,000 − 固定プロンプト余裕 4,000 = 入力 budget 180,000 token。保守係数「1 文字 = 最大 2 token」（日本語/記号の悲観値）で上限 90,000 字 → **既定値 80,000 字**（マージン込み）。
- 算術 `max_text_chars × 2 + 出力予約 + 固定分 ≤ context` を **config 不変条件テストで CI 固定**（値の変更で budget を壊せない）。tokenizer は導入しない（保守的係数で担保）。

### [Warning] ready→analyzing と doc §10.2 の不一致 → 対応
- 実装施策に「**doc/10_実装仕様.md §10.2 の更新**（ready→analyzing の追加・失敗復帰規則 = cuts ≥1 なら ready / 無ければ draft）」を含め、許可遷移を状態遷移テストへ登録することを設計に明記（Docs 行を実装方針表に追加）。

---

改訂後の概念設計全文: /workspace/devnotes/20260711-0137-ai-analysis/conceptual-design.md（ファイル読み込み可）。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 残る指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には修正提案を添える
- 日本語で出力
