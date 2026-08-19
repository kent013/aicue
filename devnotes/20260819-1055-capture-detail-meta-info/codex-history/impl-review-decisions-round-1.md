# 対応マトリクス: impl-review Round 1

全体判定: APPROVED (Critical 0 件・Warning 0 件・Suggestion 1 件)。

## [Suggestion] `CaptureManualBrowsingTest.php` のテスト名・カバレッジ

- 判断: 見送る
- 根拠:
  1. テスト名「PHP↔TS 契約」は、同じファイル内の既存 2 テスト
     (`index の summary shape は TS CaptureManualSummary と対のキー集合 (PHP↔TS 契約)` /
     `show の take shape は TS CaptureTake と対のキー集合 (PHP↔TS 契約)`) と同一の命名規則を
     踏襲している。新設テストだけ名前を変えると、むしろファイル内の命名一貫性が崩れる。
     設計書 (施策 4 テスト計画) 自体が「PHP 側 pin と TS 側 fixture はそれぞれ独立に固定するもの
     であり、対応関係の維持自体は人が両方を見て保つ構造である」と明記済みであり、
     テスト名はその注記の下で読む前提になっている。
  2. `creator_name = null` のサーバ側ケースの追加は、実装上「安価」ではないと判断した。
     `video_manuals.created_by` は `foreignId('created_by')->constrained('users')` で
     NOT NULL + FK 制約付き (`database/migrations/2026_07_10_000100_create_video_manuals_table.php`)
     であり、`User` は `SoftDeletes` を採用していない。つまり通常の Factory 操作では
     creator が解決不能になる状態を作れず、FK 制約を迂回する生 SQL 操作が要る。
     既存コードベースを grep した限り、この状態を作るテストは PHP 側に前例が 1 つも無く
     (JS 側の fixture レベルのテストのみ存在)、新しい負荷を持ち込むことになるため見送る。
- 対応内容: 変更なし。Round 1 で APPROVED 済みのため追加ラウンドは実施しない。
