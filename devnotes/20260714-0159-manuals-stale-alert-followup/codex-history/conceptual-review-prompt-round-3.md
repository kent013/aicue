# 概念設計レビュー Round 3

Round 2 の残 [Warning] 2 件（時刻精度 / 不変条件A のテスト範囲）に対応しました。確認して
全体判定（APPROVED / CHANGES_REQUESTED）を返してください。

## W2-精度: `updated_at` 比較の同一秒衝突

事実確認: `$table->timestamps()` は precision 0（秒）。既定接続は sqlite、テストは pgsql（強制）で、
driver 横断で「列 precision による微秒保存」に依存するのは脆い。よって **precision には依存しない設計**とし、
以下で担保する:

1. **比較する 2 事象は常に別リクエスト/別プロセスで、同一トランザクションには決して同居しない**。
   - `job.updated_at` = 失敗確定（analysis/render は queue worker の terminal tx）。
   - `cut.updated_at` = シナリオ編集（web リクエスト）/ take 採用。
   - stale 方向（失敗の**後**に編集）は「LLM 失敗が非同期着地 → ユーザーが気づき編集 → 手動完成」で
     人間ペースの秒〜分。同一秒衝突は実運用でほぼ起きない。
2. **strict `>` が同一秒を fail-safe にする**: 同一秒なら `T > T = false` → not stale → **error 表示**
   （既存挙動）。「本来 stale を一瞬表示し続ける」だけで、次のシナリオ操作で stale 化し自己回復する。
   **実エラーを誤って隠すことは決して無い**（隠す方向は `cut.updated_at > job.updated_at` が真＝失敗後に
   確実に触った時のみ）。`>=` は失敗前の同一秒更新まで stale 化するので不採用（strict `>` 維持）。
3. non-stale を守る 2 経路（再解析失敗 / scenario_version_changed CTA）では cut 変更が失敗より明確に前
   （別リクエスト・別秒）で衝突せず、`>` が誤って隠すことはない。
4. 直すバグは「reload しても秒〜分残る stale error」。同一秒エッジは別物で自己回復するため、3 テーブルの
   precision マイグレーションは本 finding に不均衡（オーバーエンジニアリング）と判断。
   Feature テストは `Carbon::setTestNow` で秒をずらし判定行列を固定する。

## W4-inventory: 不変条件A のテスト範囲

「再 failJob だけ」から拡張し、**terminal job の updated_at を触りうる既知の実経路を網羅する focused
Feature テスト**とする: 失敗確定後に (a) 解析再トリガー（新規 job を作り旧 job は不変）、
(b) `recoverStaleJobs`（terminal 対象外）、(c) ポーリング GET（read-only）を実行しても当該 failed job の
`updated_at` が不変であることを検証。full な Architecture inventory 昇格は将来 job 書き込み経路が増えた
時点で検討、と設計に注記した。

## 質問
strict `>` の fail-safe 論（同一秒は「隠す」ではなく「表示」に倒れ自己回復、実エラーは決して隠れない）で
時刻精度の懸念は解消と判断してよいか。残 Critical/Warning があれば指摘してください。
