# 対応マトリクス: design-review Round 4 (APPROVED)

Round 4 は全 11 施策 APPROVE / 全体判定 **APPROVED**。Critical / Warning の追加指摘なし。
設計への差し戻しは無い。

## 最終確認 (app-design Phase 2-5: 使命・禁止事項チェック)

### 使命 (North Star) への寄与
- 撮り比べたテイクに視覚的な判別の手がかりを与え、「どれを採用するか」の判断で
  毎回動画を開く必要を減らす = **思考ゼロ・編集ゼロ**の 3 ハードルのうち「撮影判断」に効く。
- doc/05 の横持ち撮影「録画後は下部サムネイルで即確認」、doc/04 の「動画列はサムネイル表示」の
  サーバ側資産 (生成・保存・配信) が揃う (PC 面の描画は別タスク)。
- 撮影者のスキルに品質を依存させない方向と整合する (生成は全自動・失敗しても撮影は止まらない)。

### 禁止事項チェック
1. テストなしの実装完了報告 → 全 11 施策にテスト計画あり。**不変条件は Architecture 目録
   (dedup / lease / DirectFetch / S3 面分類 / IDOR) への登録まで施策に含めている** (S6)。
2. PHPStan の widen / baseline → 集計は「既に level 10 を通っている形」から外さない設計に変更済み
   (Round 2 対応)。`@phpstan-ignore` も baseline も使わない。
3. dev DB への破壊操作 → migration は列追加のみ。`migrate:fresh` 等は使わない。
4. `response()->json()` の直書き → 新 endpoint は 302 リダイレクト、shape は
   `CaptureTakeData` (DTO) → `CaptureTakeResource` (JsonResource) に一元化。
5. LLM 呼び出しの Prism 直呼び → 本タスクは LLM を使わない。
6. prompt 文字列の直書き → 該当なし。
7. `redirect()->intended()` → 使わない。
8. **必須条件未充足を理由に disabled にする UI** → サムネイルの有無でボタンを disabled にしない。
   未生成はプレースホルダ表示のみで、既存の押下時エラー方式を変えない。
9. Artifact の使用 → 使っていない (成果物はすべて devnotes 配下のファイル)。

### コーディングルール反映
- PHPStan level 10: 各施策に「PHPStan 適合チェック」節あり (戻り型 / null 安全 / generics)。
- Pest + `RefreshDatabase` グローバル適用: 全テスト計画に「個別 `DatabaseTransactions` を
  使っていないことを確認」を入れてある。
- テストデータは Factory 生成: `TakeFactory::withThumbnail()` を施策に含めた (S1)。
- DTO + JsonResource: S8 で shape を DTO に一元化し、Resource は委譲のみ。
- DESIGN.md / Atomic Design: S9 は DS token と `@lucide/svelte` のみ、SVG 直書きなし、
  単方向 import (features/capture 内で完結)。専用 atom は作らない判断と、その撤回条件も明記。

## 残す未解決点 (実装時に機械検査で確定させる)
1. `DirectFetchInventory` の entry key の実文字列 (目録は exact-fit のため先回りで書かない)
2. `Cut` → `VideoManual` の relation 名
3. `writeStream()` の `ContentType` option 名と、実 S3 metadata への反映範囲 (テストでは保証しない)
