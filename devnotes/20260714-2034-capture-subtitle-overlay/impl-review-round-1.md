**レビュー結果（T047）**

`resources/js/components/features/capture/SubtitleOverlay.svelte`  
- 判定: **OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `Props.secondary` は設計どおり `string` で、空判定のみ `trim()`、描画は元文字列を使用しており S1 準拠。
  - `visible===false` または両方空で非描画、`pointer-events-none absolute inset-0 ... justify-between p-3`、`line-clamp-2/3`、`data-testid` すべて一致。
  - overlay ルートに id を置いておらず、IDREF 問題回避方針に一致。
  - DOM 別レイヤーの presentational 実装で、MediaStream 非包含（焼込なし）要件に整合。

`resources/js/components/features/capture/CameraRecorder.svelte`  
- 判定: **OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `subtitlePrimary?`/`subtitleSecondary?` に既定値 (`null`/`""`) を付与し後方互換を担保、S2 準拠。
  - `showSubtitles = $state(true)`、`$derived` で `aria-label` 連動、`aria-pressed` も適切。
  - トグルは `disabled` なしで禁止事項 8 を満たす。
  - 既存録画ロジック（MediaRecorder 系）への変更は見当たらず、影響局所化できている。
  - DS 的にも `rounded-sm`・トークン系クラス利用で新規 hex 直書きなし。

`resources/js/pages/Capture/Show.svelte`  
- 判定: **OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `selectedCut.subtitle_primary` / `subtitle_secondary` の配線は S3 どおり。
  - `{:else}`（selectedCut 非 null）配下での利用となっており NPE リスクを増やしていない。

`tests/js/components/features/capture/SubtitleOverlay.test.ts`  
- 判定: **OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - S4 の要求（trim 後空判定、元文字列描画、表示条件、構造クラス）を網羅。
  - `line-clamp`、DOM順（上帯→下帯）まで確認しており妥当。

`tests/js/components/features/capture/CameraRecorder.test.ts`  
- 判定: **OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - S5 の追記方針（既存ケース無改変）に整合。
  - 後方互換、トグルの `aria-pressed` / `aria-label` 遷移、`disabled` なしの確認を満たす。

**総評**
- 設計 S1〜S5 への一致性、Svelte runes の使い方、後方互換、DS/Atomic 準拠、禁止事項 8、セキュリティ不変条件（焼込なし）いずれも問題なし。
- 既存テスト改変・削除の兆候もなし。提示されたテスト結果とも整合。

**最終判定: APPROVED**