# 対応マトリクス: conceptual-review Round 3

## [Warning] R4 が「テストファースト」と「characterization test」を混同している (観点 2)
- 判断: 対応する
- 根拠: 完全に妥当。既存挙動 (flip 後に最新 facingMode で `getUserMedia`) を固定する
  component test は**移動前に green** でなければならない。
  移動前に red なら、それは「現行挙動を固定できていない」= characterization として無効。
- 対応内容: R4 を 2 本に分割する。
  - **R4a (Red)**: 未実装の `videoConstraints(mode)` 単体テスト → 実装前 fail
  - **R4b (characterization / Green→Green)**: flip 後の `getUserMedia` が最新モードを使う
    component test → **移動前に green を確認**してから移動し、移動後も green を維持

## [Warning] 代表ファイル 1 件の `calculateConfigForFile()` では全 .svelte を保証できない (観点 3)
- 判断: 対応する
- 根拠: 妥当。特定ファイル向け override で `no-undef` を解除しても、
  代表ファイルだけ見る検査は通過してしまう。gate の名前
  (`svelte-no-undef-gate` = 「.svelte の no-undef」) が果たすべき役割を果たしていない。
- 対応内容: `resources/js` 配下の `.svelte` を**全件列挙**し、
  各ファイルの実効設定を `assertSvelteNoUndefConfig` に通す。
  **列挙結果が 0 件なら fail** させる (走査が空振りしていないことの確認)。
  新規ファイルは自動的に列挙対象に入るので deny-by-default になる。
  走査は既存の `pages-path-case-invariant.test.ts` と同じ
  `fs.readdir(recursive: true)` の作法に寄せる。

## [Suggestion] その他 (使命整合・4.5:1 のプロジェクト基準化・キャッシュ禁止制約・globals 完全一致・スコープ・型安全性)
- 判断: 対応不要（すべて肯定的評価）
