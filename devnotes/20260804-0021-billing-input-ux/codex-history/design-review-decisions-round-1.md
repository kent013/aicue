# 対応マトリクス: design-review Round 1

Codex (gpt-5.3-codex / high) の全体判定は **CHANGES_REQUESTED**。
施策 1/2/4 = APPROVE、施策 3/5 = REQUEST_CHANGES。Critical 0 件 / Warning 1 件 / Suggestion 3 件。

## [Warning] (施策 3/5) `form-novalidate` テストの正規表現走査は偽陽性/偽陰性を生む

- 判断: **対応する**
- 根拠: 指摘は正しい。生テキストの `<form\b` 走査は `<script>` 内の文字列やコメント中の
  `<form` を拾い(偽陽性)、`novalidate` を先頭以外に書いた正しいフォームを落とす(偽陰性)。
  「先頭属性に固定する」書式規約は、そもそも正規表現で判定できるようにするための都合であり、
  AST を使えば不要になる。
- 対応内容:
  1. テスト実装を `svelte/compiler` の `parse(source, { modern: true })` による AST 走査へ変更。
     `RegularElement` かつ `name === "form"` のノードで `attributes` に
     `Attribute` / `name === "novalidate"` があるかを判定し、`node.start` から行番号を算出する。
     `parent` キーを辿らない再帰で循環参照を回避する。
  2. **実現性を実測**(svelte 5.56.3): `resources/js` の `.svelte` 99 ファイルが全て parse でき、
     検出した form 要素は **33 個**で grep 結果と完全一致することを確認した。詳細設計に明記。
  3. 「`novalidate` を `<form` の直後に書く」は**機械強制をやめ、可読性上の慣習**に格下げ。
     根拠が消えた制約を機械で縛らない (Codex の「規約を維持したまま」案より一歩進めた判断で、
     機械が見るのは有無だけにする)。DESIGN.md の追記文からも位置の指定を削除した。

## [Suggestion] (施策 1) 入力 atom の `class` に `bg-*` を渡さない lint/architecture ルールを追加

- 判断: **見送る** (根拠を設計に明記)
- 根拠: 現に違反 call site はゼロ (`class` prop の実使用は `PasswordInput.svelte:47` の `pr-10` のみ)。
  存在しない問題のための機構追加はオーバーエンジニアリング (思考原則 2)。
  背景を上書きしたい call site が実際に現れたときは、ルールを足す前に「なぜ atom の面を
  上書きしたいのか」を問うべき。
- 対応内容: 施策 1 の「リスク」節にこの判断と理由を追記した。

## [Suggestion] (施策 2) DESIGN.md に「不変条件が同じなら既存実装は許容、新規は `$derived` 推奨」を明文化

- 判断: **対応する**
- 根拠: 指摘のとおり。canonical なのは不変条件であって実装形ではない、と明示しないと
  先行 2 実装が「規約違反」に読まれてしまう。
- 対応内容: 施策 4 の DESIGN.md §FormField 追記文を修正
  (「canonical なのはこの不変条件であって実装形ではない」「先行実装は同じ不変条件を満たしており
  そのまま許容する」「新規は `$derived` 形で書く」を明記)。

## [Suggestion] (施策 5) ブラウザ E2E を 1 本足して「invalid email でも submit ハンドラに到達」を smoke 化

- 判断: **見送る** (根拠を設計に明記)
- 根拠:
  (a) `tests/Browser` は現状 2 本で、Chromium + WebKit の 2 レーン実行が契約
      (`docs/testing-browser.md` / AGENTS.md ドメイン固有規約 3)。1 本の追加は実行コスト 2 倍で入る。
  (b) 守りたい不変条件は「**全 33 form** が native validation に依存しない」であり、
      1 画面の E2E ではほかの 32 form を守れない。網羅的に守れるのは 5-1 の architecture テスト。
  (c) E2E が担保するのは「`novalidate` が付いたブラウザは本当にブロックしないか」だが、
      これは HTML 仕様に属する事実でアプリ側の回帰点ではない。
- 対応内容: 5-5 の注記にこの回答を追記した。

## [Suggestion] 論点回答 1/2/4 (設計判断は妥当)

- 判断: **そのまま維持** (変更なし)
