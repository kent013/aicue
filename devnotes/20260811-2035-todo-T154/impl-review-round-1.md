提供差分と記載されたテスト結果ベースでは、マージ阻止の指摘はありません。コマンド実行は禁止条件に従い未実施です。

**ファイル別判定**

- `app/Services/Manual/CurrentRenderArtifact.php`: 問題なし。保持ポリシーと同じ「同 manual × kind の最新 succeeded」を選び、`output_path=null` で旧世代へ戻らない点も設計通り。
- `app/Http/Controllers/Projects/ManualRenderController.php`: 問題なし。層 2 の 404 が認可より前、`kind` ごとの ability match、完成動画の published 判定、現行世代照合はいずれも設計通り。`isLatestSucceededPreview` も残っていません。
- `app/Http/Controllers/Projects/ManualDownloadController.php`: 問題なし。download 側も `CurrentRenderArtifact` に載り、旧世代 fallback が消えています。
- `app/Http/Controllers/Projects/VideoManualController.php`: 問題なし。`finishedJob` は published + download ability + 現行世代でだけ出ており、props に成果物パスや署名 URL を出していません。
- `resources/js/types/manual.ts`: 問題なし。`finishedJob` が必須キーとして追加され、旧 shape との並走なし。
- `resources/js/pages/Manuals/Show.svelte`: 問題なし。props pass-through のみで責務が増えていません。
- `resources/js/components/features/manual/RenderPanel.svelte`: 問題なし。表示条件が `finishedJob !== null` のみで、`canManage` や `status` を UI 側に積んでいません。hex / SVG 直書きもなし。
- `app/Enums/Security/RenderArtifactSelectionKind.php` / `app/Support/Security/RenderArtifactSelectionInventory.php`: 問題なし。deny-by-default の区分と根拠は過剰主張を避けています。
- `tests/Architecture/CurrentRenderArtifactInventoryTest.php`: 問題なし。母集団非空、exact-fit、Canonical cap、SupersessionCriterion 前提検査があり、偽グリーン対策は十分です。
- `tests/Feature/Manual/FinishedVideoPlaybackTest.php`: 問題なし。完成動画 endpoint、props、cross-org / cross-manual、旧世代、null 最新世代を押さえています。
- `tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php` / `tests/Support/Policies/DivergentVideoManualPolicy.php`: 問題なし。policy 差し替えで写像を直接観測し、`afterEach` で戻しているため並列実行下でもプロセス内残留リスクは抑えられています。
- `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`: 問題なし。preview 側の既存契約を残したまま、render 側だけ published 条件へ更新されています。
- `tests/Browser/FinishedVideoPlaybackTest.php`: 問題なし。DOM 契約に限定し、S3 実再生を保証しない範囲も明記されています。
- `tests/js/components/features/manual/RenderPanel.test.ts` / `tests/js/pages/ManualsShow.test.ts`: 問題なし。UI が独自条件を積まないこと、job id 込み playback URL、黒背景注記なしを固定できています。

[Warning] 提供差分には `docs/architecture.md` と `AGENTS.md` が含まれていないため、施策 7 はこのレビューでは確認不能です。実際の PR に docs 差分がある前提なら問題ありませんが、未更新なら設計の完了条件からは外れます。

**全体判定: APPROVED**  
ただし、施策 7 のドキュメント差分だけはマージ前に別途確認してください。