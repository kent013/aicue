Round 4 の全指摘（Warning 2 + Suggestion 1）に対応しました。これが最終ラウンド（5/5）です。
再レビューと最終判定をお願いします。

## 対応マトリクス（Round 4 指摘 → 対応）

1. [Warning] reconciliation の `render:recover-stale-jobs` 同居は責務逸脱 → **対応**。
   専用 command **`render:reconcile-outputs`** に分離（schedule は同じ 5 分毎で共存可、
   コマンドの責務・テストは分離）。施策一覧・テスト観点も更新済み

2. [Warning] 2 DB connection の同期呼び出しでは自己デッドロックし直列化を実証できない → **対応**。
   並行実行主体を分けたテストへ変更:
   (1) 親（テストプロセス）が connection A で Organization 行ロックを保持
   (2) **子プロセス**（Symfony Process で起動するテスト用 artisan command）で
       `triggerPreview()` を開始（開始を通知）
   (3) 子が未完了であることを確認
   (4) A を commit
   (5) 子が完了し、解放後の in-flight 件数で判定したことを確認
   ハング防止: 子プロセス側に短い `lock_timeout`、テスト全体にタイムアウト

3. [Suggestion] ASS serializer の正規化対象と Service 隔離 → **対応**。
   ASS 生成を専用 Service `AssSubtitleWriter` に隔離。正規化対象に `{` `}` に加えて
   リテラルの `\N` `\n` `\h`・CR・制御文字を明記。入力（攻撃的文字列）と生成ファイルの
   双方を検証する Unit テストを追加

## 改訂後の該当箇所（抜粋）

### §4 preview トリガー（並行直列化テスト）
テストは 2 段構え: (a) 逐次 Feature テストで上限 409 の境界を固定、(b) 並行実行主体を分けた
直列化の実証テスト: subprocess（Symfony Process で起動するテスト用 artisan command）で
triggerPreview を実行し、「親が connection A で Organization 行ロック保持 → 子で開始（通知）→
未完了確認 → A commit → 子完了 + 解放後の in-flight 件数で判定」の順序を同期ポイント付きで
検証。子プロセス側で短い lock_timeout とテスト全体のタイムアウトを設定

### §5 finalize（保持ポリシー）
収束の担保は (a) finalize 時の削除 job 投入（即時経路）、(b) 専用 command
`render:reconcile-outputs`（stale 回復とは別概念のため recover-stale-jobs に同居させない。
schedule は同じ 5 分毎、コマンド・テストの責務を分離）が「output_path 非 NULL かつ
世代交代済み」の render_jobs を走査し削除 job を再投入（冪等 = 重複無害）

### §6 ffmpeg（ASS 生成）
ASS 生成は専用 Service（AssSubtitleWriter）に隔離し、ASS 形式固有の正規化/エスケープを実装
（改行 → \N、{ } の除去/エスケープ、リテラルの \N \n \h・CR・制御文字も正規化対象）。
入力と生成ファイルの双方を検証する Unit テスト + 攻撃的入力の Feature テスト

### コンソール
`render:recover-stale-jobs`（queued=10 分 / running=30 分の 2 閾値）と
`render:reconcile-outputs`（旧世代出力の削除 job 再投入）の 2 command（各 5 分毎）

【出力形式】（Round 1 と同じ）
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には必ず修正提案を添える
- 日本語で出力
