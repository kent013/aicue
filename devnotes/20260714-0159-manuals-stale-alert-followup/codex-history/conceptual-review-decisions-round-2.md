# 対応マトリクス: conceptual-review Round 2

W1・W3 は解消確認。残る [Warning] 2 件（時刻精度・不変条件A のテスト範囲）に対応する。

## [Warning] 3-1 (再) 時刻精度: `updated_at > job.updated_at` は同一秒衝突で検出できない
- 判断: **根拠提示（precision マイグレーションは見送り）+ 設計に fail-safe を明記**
- 事実確認:
  - `$table->timestamps()` は precision 0（秒）。本アプリの既定接続は sqlite、テストは pgsql（強制）で、
    driver 横断で「列 precision に依存した微秒保存」は脆い（sqlite は動的型で Carbon 直列化形式依存）。
  - よって **列 precision に staleness の正しさを依存させない**方針を採る。
- 論拠（precision 変更が不要な理由）:
  1. 比較する 2 事象は **常に別リクエスト/別プロセス**で、同一トランザクション内には決して起きない。
     - `job.updated_at`: 失敗確定の瞬間（analysis/render は queue worker の terminal tx）。
     - `cut.updated_at`: シナリオ編集（web リクエスト）または take 採用。
     - stale 方向（失敗の**後**に編集）は「LLM 失敗が非同期で着地 → ユーザーが気づいて編集画面へ →
       手動完成」で、人間ペースの秒〜分オーダー。同一秒衝突は実運用でほぼ起きない。
  2. **strict `>` が同一秒の曖昧さを fail-safe にする**: 同一秒衝突時 `T > T = false` → not stale →
     **error を表示**（＝既存挙動）。これは「本来 stale を一瞬だけ表示し続ける」だけで、次に
     シナリオへ触れた瞬間（次秒）に stale 化して自己回復する。**実エラーを誤って隠すことは絶対に無い**
     （隠す方向の誤りが起きるのは `cut.updated_at > job.updated_at` が真のときのみ = 失敗後に確実に触った時）。
     Codex 指摘どおり `>=` は失敗前の同一秒更新まで stale 化するため不採用（strict `>` を維持）。
  3. non-stale を守るべき 2 経路（再解析失敗 / scenario_version_changed CTA）では、cut 変更は
     いずれも失敗より**明確に前**（別リクエスト・別秒）で衝突せず、`>` が誤って隠すことはない。
  4. 直そうとしているバグは「reload しても数秒〜分残る stale error」。同一秒エッジは別物で自己回復する。
     3 テーブルの precision マイグレーションは本 finding に対し不均衡（オーバーエンジニアリング）。
- 対応内容: 概念設計に「strict `>` による fail-safe（同一秒は安全側=表示に倒れ、自己回復）」を明記。
  Feature テストで stale/not-stale の判定行列を Carbon::setTestNow で秒をずらして固定する。

## [Warning] 5-1 (再) 不変条件A のテスト範囲が「再 failJob」だけでは不十分
- 判断: **対応する（focused test に拡張）**
- 根拠: 別経路の save/touch で将来破られうる。ただし完全な「job 更新経路 inventory」の新設は現時点で
  不均衡。**terminal job の updated_at を触りうる既知の実経路**を網羅する focused Feature テストで固定する。
- 対応内容: Feature テストで「failed job 確定後に (a) 解析再トリガー（新規 job を作り旧 job は不変）、
  (b) `recoverStaleJobs`（terminal を対象外）、(c) ポーリング GET（read-only）を実行しても、
  当該 failed job の `updated_at` が不変」を検証する。これで invariant A を concrete な mutation 面で固定。
  （full Architecture inventory への昇格は将来 job 書き込み経路が増えた時点で検討、と設計に注記。）
