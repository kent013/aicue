# 対応マトリクス: conceptual-review Round 1

## [Critical] 型専用 interface を ESLint `globals` に足すのは value/type space の混同 (観点 3 / 5 / 7、3 件は同一指摘)
- 判断: **対応する**（全面的に受け入れ）
- 根拠: 指摘のとおり。`globals` に `MediaTrackConstraints` を `readonly` で載せると、
  同名を**実行時の値**として誤用した場合も `no-undef` が黙る。
  gate を入れる同じ PR で gate に穴を開ける設計であり、baseline gate の趣旨に反する。
  また「型専用 interface は globals へ追記する」を**運用ルール化**すると、
  穴が今後も増え続ける構造になる（AGENTS.md 禁止事項 2「PHPStan エラーの widen」と同種の悪手）。
- 対応内容:
  1. `globals` に載せるのは **実行時グローバルのみ** (`...globals.browser`) とし、
     型専用名は 1 件も追加しない。config には
     「型専用名を globals に足さないこと」を**禁止として**コメントに明記する。
  2. 唯一の実測違反 `CameraRecorder.svelte:168` の `MediaTrackConstraints` は、
     **`videoConstraints()` を `resources/js/lib/capture/camera.ts` へ移す**ことで解消する。
     同ファイルは既に `FacingMode` / `classifyGetUserMediaError` 等を export しており
     `CameraRecorder.svelte` が import 済み。`.ts` なので **tsc の型検査対象**になり、
     型名は `.svelte` から消える（`no-undef` の鋭さを一切削らない）。
     副次効果として、これまで型検査の外にあった constraints 構築が tsc 配下に入る。
  3. 将来 `.svelte` の型注釈に WebIDL dictionary 等の型専用名が必要になった場合の
     運用ルールを **globals 追記ではなく**「`.ts` 側で `export type X = MediaTrackConstraints;`
     と別名 export し、`.svelte` からは `import type` で参照する」に定める。
     `import type` は module 参照なので `no-undef` の対象外であり、
     かつ実行時誤用は引き続き検出される。

## [Warning] 「`.svelte` の型検査の空白地帯を閉じる」は過大表現 (観点 1 / 4)
- 判断: 対応する
- 根拠: 妥当。本バッチが閉じるのは「未定義識別子 (runtime identifier) の検出機構がゼロ」という
  一点であり、props/event の型不整合やテンプレート式の型崩れは埋まらない。
- 対応内容: 背景・期待効果の記述を「未定義識別子事故の予防」に限定。
  `.svelte` の型検査経路 (svelte-check 等) の導入は**別 backlog**として申し送りへ切り出す。

## [Warning] `noInlineConfig` 下の例外 (config override) の許可基準を先に固定せよ (観点 2 / 5)
- 判断: 対応する
- 根拠: 妥当。基準がないと「config override なら何でも良い」に流れる。
- 対応内容: 「inline disable 禁止 / 例外は config の file-scoped override に集約」という
  運用契約と、override を認める 3 条件（(a) 抑制対象が 1 ファイルに閉じている
  (b) なぜ安全かがコード側コメントで説明されている (c) config 側に理由と再検討条件を書く）を
  設計に明記し、`svelte-no-undef-gate` の記述と `docs/template-divergence.md` に固定する。

## [Warning] `svelte-no-undef-gate` の ESLint API 静的検査は brittle (観点 3)
- 判断: 対応する
- 根拠: 妥当。config オブジェクトの形状マッチも、内部 API 依存もどちらも脆い。
- 対応内容: **実ファイル fixture に対する実効設定の解決結果**を検査する方式に固定し
  (`ESLint#calculateConfigForFile` = 公開 API)、
  さらに `pages-path-case-invariant.test.ts` の作法に倣って
  **負のコントロール**（no-undef を落とした config を解決させると検出が点灯する）と
  **正のコントロール**（実 config なら通る）を置く。

## [Warning] `contrast-invariant` の名前が「コントラスト全般」と誤読される (観点 5)
- 判断: 対応する
- 根拠: 妥当。非テキスト (1.4.11) と alpha 合成をスコープ外にしている以上、
  名前と説明で境界を明示しないと「検査済み」の誤ったシグナルになる。
- 対応内容: ファイル名は台帳の正典パス (`contrast-invariant.test.ts`) を維持しつつ、
  describe 名を「不透明ペアのテキストコントラスト (WCAG 2.2 SC 1.4.3 AA)」とし、
  inventory に **PENDING_CONTRAST_PAIRS**（非テキスト / alpha 合成）を
  理由付きで宣言して「未検査であることが見える」形にする。

## [Warning] lint baseline と contrast baseline は受け入れ条件を分けよ (観点 6)
- 判断: 対応する
- 根拠: 妥当。失敗時の切り分けが違う。
- 対応内容: 詳細設計で受け入れ条件を 2 系統に分離して記述する。

## [Suggestion] 共有ヘルパの返却型と frontmatter schema を明示せよ (観点 7)
- 判断: 対応する
- 対応内容: `tests/js/styles/design-md.ts` の公開 API と返却型を詳細設計で明示する。

## [Suggestion] その他 (使命整合・スコープ・danger 是正の妥当性)
- 判断: 対応不要（肯定的評価）
