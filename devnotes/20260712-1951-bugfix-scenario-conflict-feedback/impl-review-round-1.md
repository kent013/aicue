**ファイル別判定**

- `app/Http/Controllers/Capture/CaptureManualController.php` — **APPROVE**
- `app/Http/Controllers/Projects/VideoManualController.php` — **APPROVE**
- `config/seo.php` — **APPROVE**
- `resources/js/components/features/manual/ScenarioEditor.svelte` — **APPROVE**
- `tests/Feature/Projects/ManualPageTitleTest.php` — **APPROVE**
- `tests/js/components/features/manual/ScenarioEditor.test.ts` — **APPROVE**

**指摘事項**

- [Suggestion] `ScenarioEditor.svelte` の `unreachableFailureView()` は現在の方針（`$derived` 内で throw しない）と整合しており妥当です。将来 `SaveFailure.kind` を追加する際の見落とし防止として、`FailureView` の `testId` 命名規約をテスト側に 1 ケース（kind 網羅）で固定化しておくと、運用時の回帰検知がさらに強くなります。  
- [Suggestion] `ManualPageTitleTest` は `<title>` と `Inertia shared prop title` の両方を押さえており設計意図に一致しています。将来的に `seo.title_separator` 変更があり得るなら、期待文字列の組み立てをヘルパー化して保守性を上げる余地があります（現状でも問題なし）。

**レビュー総評**

- 詳細設計（F-02/F-05）との一致性は高く、保存ロジック・409 契約を変えずに失敗フィードバックの知覚性改善を実現できています。
- 403 分岐追加、409 analyzing 時 CTA 抑止、操作点直近表示、focus/scroll 制御のテスト担保まで含め、要件を過不足なく満たしています。
- DTO/JsonResource・セキュリティ不変条件・DESIGN.md/Atomic Design の観点でも、提示差分内で違反は見当たりません。
- テスト実績（Vitest/Pest/PHPStan 含む）も十分です。

**全体判定**

- **APPROVED**