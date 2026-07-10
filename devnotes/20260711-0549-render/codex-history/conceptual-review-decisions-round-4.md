# 対応マトリクス: conceptual-review Round 4

## [Warning] reconciliation を render:recover-stale-jobs に同居させるのは責務逸脱（概念統合）
- 判断: 対応する
- 対応内容: 専用 command `render:reconcile-outputs` に分離（schedule は同じ 5 分毎、
  コマンド・テストの責務は分離）。施策一覧・テスト観点も更新

## [Warning] 2 DB connection の同期呼び出しではロック待ちで自己デッドロックし実証にならない
- 判断: 対応する
- 対応内容: 並行実行主体を分けたテストに変更: 親が connection A で Organization 行ロックを
  保持 → 子プロセス（Symfony Process で起動するテスト用 artisan command）で triggerPreview
  開始（開始通知）→ 未完了確認 → A commit → 子が完了し解放後の in-flight 件数で判定、の
  順序を同期ポイント付きで検証。子プロセス側に短い lock_timeout + テスト全体タイムアウトで
  ハング防止

## [Suggestion] ASS serializer の正規化対象拡大 + 専用 Service 隔離
- 判断: 対応する
- 対応内容: `AssSubtitleWriter` Service に隔離。正規化対象にリテラル `\N` `\n` `\h`・CR・
  制御文字を明記。入力と生成ファイル双方の Unit テスト
