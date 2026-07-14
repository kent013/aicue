# 対応マトリクス: conceptual-review Round 1

Round 1 全体判定: **APPROVED**（Critical なし / Warning 5 / Suggestion 多数）。
Warning はすべて「対応する」。概念設計 `conceptual-design.md` に反映済み。

## [Warning] 観点2: 実装概要にテスト方針が未明示
- 判断: 対応する
- 根拠: 「テストなしの実装完了」は AGENTS.md 禁止事項。
- 対応内容: 概念設計に「テスト方針（概要）」節を追加。menuOpen=false 時の単一ヒット回帰、
  トグル展開/`aria-expanded`、Escape/リンク押下で閉じる、nav 不在時にボタンも出ない、を
  vitest コンポーネントテストで担保。typecheck/lint/build + 既存アーキテクチャテスト green も明記。

## [Warning] 観点3: Button atom が button 属性を forward できる前提が未確認
- 判断: 対応する
- 根拠: 実装時に詰まるリスクを事前に潰す。
- 対応内容: 「実装前の確定事項 2」に、着手時に Button.types.ts/Button.svelte で
  onclick/aria-expanded/aria-controls/type forward を確認し、不足なら atom を最小拡張
  （DESIGN.md 表と型を同一 PR 更新）と明記。素の button 手書きはしない。

## [Warning] 観点3: Contact 系 (nav なし) でハンバーガーだけ出る誤実装の余地
- 判断: 対応する
- 根拠: nav 不在ページで空トグルが出ると UX 破綻。
- 対応内容: 「実装前の確定事項 1」に、トグル/広幅 nav/狭幅パネルの 3 つすべてを
  `{#if nav}` 配下に置き、state・ハンドラも nav 有り時のみ有効化と明記。

## [Warning] 観点5: nav snippet 二重 @render の将来リスク
- 判断: 対応する
- 根拠: 将来 snippet が状態 full 化するとイベント/表示差分の温床。
- 対応内容: 「実装前の確定事項 3」に、nav snippet は「単純なリンク群を想定」する契約を
  コンポーネント JSDoc に明記し、対象ページが前提を満たすことを確認と記載。

## [Warning] 観点7: nav?: Snippet 不在分岐の徹底
- 判断: 対応する（Warning 観点3 の nav 不在対応と同一施策）
- 根拠: ランタイム条件漏れ防止。
- 対応内容: ヘッダー右側 (トグル+広幅 nav+パネル) を親コンテナ単位で `{#if nav}` に包む
  設計を「確定事項 1」で明記。

## [Suggestion] Escape 後にトグルへフォーカス復帰
- 判断: 対応する（キーボード UX を安定化）
- 対応内容: 「確定事項 4」に bind:this でボタン参照を保持し Escape close 後に focus 復帰と明記。

## その他 Suggestion（使命整合/スコープ/型安全/FE 規約の肯定的評価）
- 判断: 対応不要（設計方針の妥当性を追認する内容）。
