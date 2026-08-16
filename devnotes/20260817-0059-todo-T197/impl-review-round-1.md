重大な指摘はありません。提示差分の範囲では、施策 A〜J は設計どおり実装されており、旧 `status` 互換や旧 testId の並走も増えていません。追加のコマンド実行はユーザー指示に従い行わず、提示された green 結果を前提にレビューしています。

**ファイル別判定**

- `app/Enums/Manual/ManualProgress.php`: OK  
  5 値 → 3 値の写像が `forStatus()` に集約され、逆写像も `forStatus()` から導出されています。default なしの `match`、`list<>` PHPDoc も妥当です。

- `app/DataTransferObjects/Manual/ManualListQuery.php`: OK  
  `status` から `progress` への置換、allowlist 外を `null` に倒す方針、`toProps()` / `toQueryParams()` の契約更新が一致しています。

- `app/Http/Controllers/Projects/ProjectController.php`: OK  
  `whereIn('status', $progress->statusValues())` で正本 enum 経由になっています。`withQueryString()` を避けて allowlist 済み query だけを `appends()` する変更も設計意図に合っています。

- `app/DataTransferObjects/Manual/ManualListItemData.php`: OK  
  一覧 payload から 5 値 `status` を落とし、3 値 `progress` のみ返す形になっています。完成動画の可否判定用に `VideoManualStatus` を残す点も妥当です。

- `resources/js/types/manual.ts`: OK  
  `ManualProgress` union、ラベル、tone が追加され、TS 側に写像を持たない構造になっています。5 値語彙の用途制限コメントも明確です。

- `resources/js/pages/Projects/Show.svelte`: OK  
  select / query / state が `progress` に移行し、旧 `status` query を生成していません。disabled 新設、hex 直書き、atomic 逆流も見当たりません。

- `resources/js/components/features/manual/ManualListRow.svelte`: OK  
  行バッジが 3 値 `progress` 語彙に切り替わり、旧 `manual-status-{id}` testId も残していません。

- `resources/js/types/capture.ts`: OK  
  `CaptureProgress` が PC 一覧の `ManualProgress` と別概念として定義され、既存三項式と同じ導出になっています。

- `resources/js/pages/Capture/Index.svelte`: OK  
  撮影 PWA の表示語彙を `captureProgressOf()` に委譲し、PC 側語彙へ寄せていません。

- `app/DataTransferObjects/Capture/CaptureManualSummaryData.php`: OK  
  dead payload の `status` 削除は設計 G と一致しています。DTO / array shape も追随しています。

- `tests/Architecture/ManualEnumTsSyncInvariantTest.php`: OK  
  `VideoManualStatus` と `ManualProgress` の PHP enum ⇔ TS union 同期が追加されています。提示された fail-first 実測も有効です。

- `tests/Unit/Manual/ManualProgressMappingTest.php`: OK  
  写像表、漏れなし、重複なし、DB 値列、3 件固定をカバーしています。

- Feature / Vitest 更新群: OK  
  `progress` 絞り込み、旧 `?status=` 無視、payload の `status` 不在、削除後 redirect、撮影 PWA 語彙維持まで対象同定付きで押さえています。

- `doc/02_システム全体像.md` / `doc/04_PCサイト機能仕様.md`: OK  
  doc の 3 値語彙と `ManualProgress` 正本の説明が設計 J と一致しています。

APPROVED