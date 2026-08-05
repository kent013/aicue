ありがとうございます。提示テキストのみでレビューしました（コマンド実行・書き込みなし）。

**施策別判定**
- **施策1 (`videoConstraints` を `.ts` へ移動)**: **APPROVE**  
  - ロジック同値性の説明が十分で、`reacquireWithFacing()` の代入直後評価とも整合しています（`resources/js/components/features/capture/CameraRecorder.svelte:447`）。  
  - Red/characterization の分離も妥当です（`tests/js/lib/capture/camera.test.ts:1`, `tests/js/components/features/capture/CameraRecorder.test.ts:951`）。

- **施策2 (`no-undef` + `noInlineConfig`)**: **APPROVE**  
  - `.svelte` の未定義識別子検知を lint 側で担保する判断は正しいです（TS strict 補完として合理的）。  
  - `globals` 明示導入も pnpm の解決性観点で妥当です（`eslint.config.js:1`）。

- **施策3 (死んだ `eslint-disable` 撤去)**: **APPROVE**  
  - 実効のない directive 除去として正当。`{@html}` の意図説明コメントは維持されており、挙動変更なし（`resources/js/pages/Settings/Security.svelte:457`）。  
  - [Suggestion] `{@html}` の許可箇所を将来増やさないため、別バッチで allowlist 系 gate を追加するとより堅牢です。

- **施策4 (`svelte-no-undef-gate` 新設)**: **REQUEST_CHANGES**  
  - [Warning] 設計意図は「`noInlineConfig` を全体禁止」ですが、検証対象が `.svelte` のみだと将来 `*.ts` 側の file-scoped override を見逃せます（`tests/js/architecture/svelte-no-undef-gate.test.ts:1`）。  
    - **修正案**: 同テストで `resources/js` 配下の `.ts` も列挙対象に含め、`linterOptions.noInlineConfig === true` を全対象で検証してください。  
  - [Suggestion] `calculateConfigForFile()` が `undefined` を返すケースに備えて、明示エラー文を出すガードを入れると診断性が上がります。

- **施策5 (DESIGN.md パーサ共有化)**: **APPROVE**  
  - 重複排除の範囲が適切で、既存 assertion 非変更方針も妥当（`tests/js/styles/design-md.ts:1`, `tests/js/styles/canonical-source-parity.test.ts:1`）。

- **施策6 (`contrast-invariant` + `danger` 是正)**: **APPROVE**  
  - ペア集合の設計（opaque text に限定）・deny-by-default・負のコントロールが揃っており、gate として成立しています（`tests/js/architecture/contrast-invariant.test.ts:1`）。  
  - `danger` を red-700 に揃える判断は体系整合 + AA確保の両面で妥当（`DESIGN.md:18`, `resources/css/tokens.css:31`）。  
  - [Suggestion] テスト名/説明に「opaque text 限定」をさらに強調し、非テキスト 1.4.11 未対応の誤読を防ぐと運用が安定します。

- **施策7 (申し送り記録のみ)**: **APPROVE**  
  - スコープ管理が明確で、現バッチでやらない線引きも妥当です。

**全体判定**
- **CHANGES_REQUESTED**  
  - 主理由は施策4の [Warning] 1件（`noInlineConfig` の検証スコープ不足）。  
  - それ以外は、既存整合性・TypeScript strict・テスト戦略・DESIGN token 同期の観点で良好です。