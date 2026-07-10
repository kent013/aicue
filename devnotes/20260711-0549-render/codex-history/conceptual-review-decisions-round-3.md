# 対応マトリクス: conceptual-review Round 3

## [Warning] 並行 trigger の上限テストは単一プロセス Feature テストではロック競合を実証できない
- 判断: 対応する
- 対応内容: テストを 2 段構えに明確化: (a) 逐次 Feature テストで上限 409 の境界、
  (b) pgsql の別 DB connection で Organization 行ロックを保持した状態で triggerPreview が
  直列化される（ロック解放まで検査が始まらない）ことを実証する統合テスト。
  逐次件数テストと区別して設計に明記

## [Warning] Process 配列引数では filtergraph 内のメタ文字解釈を防げない（字幕注入）
- 判断: 対応する
- 根拠: 指摘のとおり配列引数はシェル展開対策のみ。filtergraph の `:` `,` `\` `'` 改行は別問題
- 対応内容: 字幕本文の filtergraph 直埋めを禁止。カットごとに一時 ASS ファイルを生成し
  `subtitles=<サーバ生成一時パス>`（英数字のみの temp dir）だけを filtergraph に渡す。
  ASS 書き出しに形式固有エスケープ（改行→\N、`{` `}` の除去/エスケープ = override tag 注入対策）を
  実装し、攻撃的入力（メタ文字・改行・`{\...}`・日本語）の Unit/Feature テストを追加

## [Warning] 「最新 1 世代のみ保持」は非同期ベストエフォート削除では保証できない
- 判断: 対応する
- 対応内容: 契約を「非同期で最新 succeeded 1 世代へ**収束**」に修正。担保は
  (a) finalize 時の削除 job（即時経路）+ (b) `render:recover-stale-jobs` cron の
  reconciliation（output_path 非 NULL かつ世代交代済みの render_jobs を走査して削除 job を
  再投入。削除 job は冪等のため重複無害）の 2 系統

## [Warning] RenderErrorCode の集合が曖昧 / Service 管理フィールドから error_code が漏れている
- 判断: 対応する
- 対応内容: v1 は 3 値（scenario_version_changed / timeout / internal）で閉じると明記。
  Service 管理フィールド列挙（$fillable 外）に error_code を追記。DB cast・readonly DTO・
  TS literal union を完全一致させ、PHP enum ⇔ TS union の値集合同期テスト
  （types/manual.ts を読み比較する Pest テスト）を追加
