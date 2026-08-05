**ファイル別レビュー**

- `resources/js/lib/capture/camera.ts:109` / `resources/js/components/features/capture/CameraRecorder.svelte:172`  
  - 判定: **OK**  
  - `videoConstraints(mode)` の `.ts` 退避は施策1に一致。`reacquire` 時の呼出時点評価も維持できています。

- `tests/js/lib/capture/camera.test.ts:182` / `tests/js/components/features/capture/CameraRecorder.test.ts:968`  
  - 判定: **OK**  
  - R4a/C1 の意図に沿ったテスト追加で、退行ポイント（flip後のfacingMode反映）を実効的に固定できています。

- `eslint.config.js:16` / `package.json:40` / `tests/js/architecture/svelte-no-undef-gate.test.ts:1`  
  - 判定: **OK**  
  - 施策2/4に整合。`noInlineConfig`・`.svelte` の `no-undef`・globals検査・空振り防止・負のコントロールまで揃っており、gate健全性は高いです。  
  - `APP_RUNTIME_GLOBALS` 追加時にテスト期待値を同時更新する運用も明示されており、意図的なレビュー強制として妥当です。

- `resources/js/pages/Settings/Security.svelte:462`  
  - 判定: **OK**  
  - 死んだ directive 削除は施策3どおり。`{@html}` 自体は既存踏襲であり、この差分でリスク増加はありません。

- `tests/js/styles/design-md.ts:1` / `tests/js/styles/canonical-source-parity.test.ts:10`  
  - 判定: **OK**  
  - 施策5に整合。パーサ共有化は妥当。`expect(section).not.toBeNull()` 削除は `throw` に置換されており**検出力低下なし**です。

- `DESIGN.md:15` / `resources/css/tokens.css:31` / `tests/js/styles/inventory.ts:36` / `tests/js/architecture/contrast-invariant.test.ts:1`  
  - 判定: **概ねOK**  
  - 施策6に整合し、`danger` 是正と gate 追加は妥当。DESIGN canonical と token 同期も守れています。  
  - **[Warning]** `tests/js/architecture/contrast-invariant.test.ts:76`  
    `fg: string` / `bg: string` 注釈は TS2367 回避のための局所的 widen で、現状の検査実効性は保っていますが、禁止事項2の精神上はグレーです。  
    （代替: 現状集合が素であることを前提に `filter` 自体を削除し、必要なら「集合が重複していない」専用テストを追加）
  - **[Suggestion]** 設計書上の「mail theme に danger 直書きなし」は事実誤認なので、`resources/views/vendor/mail/html/themes/template.css` を対象外と明記する追記はした方がよいです（コード差分としては据え置き妥当）。

- `docs/template-divergence.md:373`  
  - 判定: **OK**  
  - D11 の記録内容は実装実態と整合しています。

**補足（質問4件への回答）**
- 1) string注釈は**非ブロッカーだがWarning**（上記理由）。  
- 2) mail theme据え置きは**スコープ的に妥当**。ただし説明文の正確性は要修正。  
- 3) rounded assertion は**検出力維持**。  
- 4) `public/capture-sw.js` lint対象外据え置きは**設計契約どおり**。

**全体判定: APPROVED**