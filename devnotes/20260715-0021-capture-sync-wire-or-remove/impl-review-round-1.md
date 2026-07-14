**レビュー結果（T052: capture.manuals.sync 廃止）**

以下、提示された差分テキストのみを根拠に判定します（コマンド実行・書込なし）。

- `.claude/skills/app-bug-hunt/operations.md`  
  - 判定: **OK**  
  - `capture.manuals.sync` 行削除は `routes/web.php` の route 削除と整合。  
  - 指摘: なし

- `routes/web.php`  
  - 判定: **OK**  
  - `CaptureSyncController` import と `manuals.sync` route が同時削除され、dangling 参照なし。  
  - 指摘: なし

- `app/Http/Controllers/Capture/CaptureSyncController.php`（削除）  
  - 判定: **OK**  
  - route 側から切り離し済みで安全。  
  - 指摘: なし

- `app/Services/Capture/CaptureSyncService.php`（削除）  
  - 判定: **OK**  
  - Controller 削除とペアで到達不能化済み。共有 DTO (`CaptureManualDetailData` 等) は保持されており巻き込み削除なし。  
  - 指摘: なし

- `app/Http/Requests/Capture/SyncCaptureTakesRequest.php`（削除）  
  - 判定: **OK**  
  - FormRequest 動的走査系テストとの整合説明も妥当。  
  - 指摘: なし

- `app/Http/Resources/Capture/CaptureSyncResultResource.php`（削除）  
  - 判定: **OK**  
  - 呼び出し元削除済み。  
  - 指摘: なし

- `app/DataTransferObjects/Capture/CaptureSyncInput.php`（削除）  
  - 判定: **OK**  
  - 専用 DTO のみ削除で閉じている。  
  - 指摘: なし

- `app/DataTransferObjects/Capture/CaptureSyncResultData.php`（削除）  
  - 判定: **OK**  
  - 専用 DTO のみ削除で閉じている。  
  - 指摘: なし

- `app/DataTransferObjects/Capture/ClientTakeFingerprint.php`（削除）  
  - 判定: **OK**  
  - sync 専用概念の削除として一貫。  
  - 指摘: なし

- `resources/js/types/capture.ts`  
  - 判定: **OK**  
  - `SyncResult` 定義削除のみ。未参照 export 削除として安全。  
  - 指摘: なし

- `tests/Architecture/NestedRouteIdorDefenseTest.php`  
  - 判定: **OK**  
  - inventory から `capture.manuals.sync` を除去し、route 削除と 1:1 同期。  
  - 指摘: なし

- `tests/Feature/Capture/CaptureSyncTest.php`（削除）  
  - 判定: **OK**  
  - 廃止 endpoint 専用テスト削除として妥当。代替経路は既存テストで維持という設計判断と整合。  
  - 指摘: なし

- `doc/08_システムアーキテクチャ設計.md`  
  - 判定: **OK**  
  - sync 固有記述のみ削除し、重複排除ロジック等の共通原則は維持。  
  - 指摘: なし

- `doc/10_実装仕様.md`  
  - 判定: **OK**  
  - route 表から sync 削除、§10.8-8 を「廃止」注記へ置換し、payload-ID 照合原則の存続先も明記。  
  - 指摘: なし

**観点別総評**

- 設計一致（施策0〜7）: **満たす**  
- 削除安全性: **満たす**（専用シンボル閉包、共有 DTO 非削除）  
- PHPStan 適合性: **満たす**（提示結果 No errors）  
- IDOR / operations / route:list 整合: **満たす**（drift 0 の主張と差分が一致）  
- canonical spec 整合: **満たす**（sync 固有のみ除去）  
- テスト網羅性: **満たす**（廃止 endpoint 専用テスト削除 + 全体 green）  
- セキュリティ: **満たす**（攻撃面縮小方向、追加経路なし）

**指摘分類**

- Critical: なし  
- Warning: なし  
- Suggestion: なし（このPRスコープでは十分に完結）

**全体判定: APPROVED**