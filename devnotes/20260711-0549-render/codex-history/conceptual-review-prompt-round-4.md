Round 3 の全指摘（Warning 4）に対応しました。対応内容と改訂後の該当箇所を示します。
再レビューをお願いします（改訂は該当 4 箇所のみ。他の設計は Round 3 提示のものから変更なし）。

## 対応マトリクス（Round 3 指摘 → 対応）

1. [Warning] 並行 trigger テストがロック競合を実証できない → **対応**。テストを 2 段構えに:
   (a) 逐次 Feature テストで上限 409 の境界を固定、
   (b) **pgsql の別 DB connection で Organization 行ロックを保持した状態で triggerPreview が
   直列化される（ロック解放まで検査が始まらない）ことを実証する統合テスト**。
   逐次件数テストと区別して設計に明記

2. [Warning] filtergraph 内メタ文字の解釈（字幕注入）→ **対応**。字幕本文の filtergraph
   直埋めを禁止し、以下に変更:
   - カットごとに一時 **ASS ファイル**を生成し、filtergraph には
     `subtitles=<サーバ生成一時ファイルパス>` のみを渡す（パスは英数字のみの temp dir =
     filtergraph メタ文字を含まない）
   - ASS 書き出しに形式固有エスケープを実装（改行 → `\N`、`{` `}` は ASS override tag
     注入になるため除去/エスケープ）
   - メタ文字・改行・`{\...}` タグ・日本語を含む攻撃的入力の Unit/Feature テストを追加

3. [Warning] 「最新 1 世代のみ保持」は非同期削除では保証不能 → **対応**。契約を
   「**非同期で最新 succeeded 1 世代へ収束**」に修正。担保は 2 系統:
   (a) finalize 時の削除 job 投入（即時経路）、
   (b) `render:recover-stale-jobs` cron が reconciliation として「output_path 非 NULL かつ
   世代交代済み」の render_jobs を走査し削除 job を再投入（削除 job は冪等 = 重複無害）。
   テスト観点に reconciliation 再投入を追加

4. [Warning] RenderErrorCode の集合が曖昧 / Service 管理フィールド列挙から漏れ → **対応**。
   - v1 の backed enum を **3 値（scenario_version_changed / timeout / internal）で閉じる**と明記
   - Service 管理フィールド（$fillable 外）の列挙に error_code を追記
   - DB cast・readonly DTO・TS literal union を完全一致させ、**PHP enum ⇔ TS union の
     値集合同期テスト**（types/manual.ts を読み比較する Pest テスト）を追加

## 改訂後の該当箇所（抜粋）

### §1 データモデル（enum・保護フィールドの記述）
- 新 enum: RenderStep（compose/concat）、RenderKind（render/preview）、RenderErrorCode
  （v1 は scenario_version_changed / timeout / internal の 3 値で閉じる。DB cast・readonly DTO・
  TS literal union を完全一致させ、PHP enum ⇔ TS union の値集合同期テストを追加）
- RenderJob の status/step/progress/kind/scenario_version/output_path/error/error_code は
  Service 管理状態のため $fillable を持たない

### §4 preview トリガー（並行上限テスト）
- テストは 2 段構え: (a) 逐次 Feature テストで上限 409 の境界、(b) pgsql の別 DB connection で
  Organization 行ロックを保持した状態で triggerPreview が直列化されることを実証する統合テスト

### §5 finalize（保持ポリシー）
- 出力保持ポリシー: render / preview とも「非同期で最新 succeeded 1 世代へ収束」を契約とする。
  収束の担保は (a) finalize 時の削除 job 投入 + (b) recover-stale-jobs cron の reconciliation
  （output_path 非 NULL かつ世代交代済みを走査し削除 job を再投入）

### §6 ffmpeg（字幕の安全境界）
- 字幕テキストを filtergraph へ直接埋め込まない。一時 ASS ファイル + `subtitles=<安全なパス>`。
  ASS 固有エスケープ（\N、{} 除去/エスケープ）+ 攻撃的入力テスト

【出力形式】（Round 1 と同じ）
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には必ず修正提案を添える
- 日本語で出力
