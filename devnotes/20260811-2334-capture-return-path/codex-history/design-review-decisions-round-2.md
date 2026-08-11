# 対応マトリクス: design-review Round 2

全体判定 CHANGES_REQUESTED。必須 3 点 + Suggestion 1 点。すべて**対応**した(反論なし)。

## [Warning] (施策 1/4) 「認可にも依らず出し分けない」は認可を無視して表示するようにも読める

- 判断: **対応する**
- 根拠: 指摘のとおり。実際には `Capture/Show` へ到達する時点で
  `Gate::authorize('view', $manual)` が成立している。正確な契約は
  「**`Capture/Show` へ到達済みの利用者に対して、追加の status / ability 条件を設けない**」であり、
  「認可に依らない」と書くと**認可を素通しする実装**に読める。セキュリティ面の文としてまずい。
- 対応内容: Svelte コメント・`docs/architecture.md` 追記・施策 1 の見出し文をすべて
  「到達済みの利用者に対し、追加の status / ability 条件を設けず常に出す」へ改めた。

## [Warning] (施策 3/4) Feature テストは「到達条件の同一性」を証明しない

- 判断: **対応する**
- 根拠: 妥当。テストが言えるのは「用意した principal と Factory 既定データについて、
  全 status で両画面に到達できた」までで、PC 側だけに新しい属性由来の制限が入っても
  Factory 既定値が引っかからなければ緑のままになる。「同一性を機械保証」は誇張である。
  また本 TODO に必要なのは同値ではなく**片方向の含意**
  (`capture.manuals.show` 到達可 ⇒ `projects.manuals.show` 到達可) だけである。
- 対応内容:
  - 施策一覧の施策 3 名を「最弱 principal に対する復路到達契約の固定」へ変更。
  - 「到達条件が同じとは何と何が同じか」節を「**何を主張するのか (同一性ではなく片方向の含意)**」へ
    書き換え、層の対応表は**設計根拠であってテストの証明対象ではない**と明記。
  - テスト名を「最弱 principal (撮影者) は…」へ変更し、docblock に「何を証明しないか」を追記。
  - 「保証しないもの」に、構造の同一性を不変条件にするなら Architecture テストが別途要ること、
    ただし**本 TODO では作らない**(リンク 1 本に対して過大 = 思考原則 2)ことを明記。
  - `docs/architecture.md` 追記も同じ言い方に揃えた。

## [Warning] (施策 2) mutation D は現在のテストでは赤くならない

- 判断: **対応する**
- 根拠: そのとおり。Lucide の `svg` は `title` を持たないため、`aria-hidden` を外しても
  リンクの accessible name は「マニュアル詳細へ」のままで、名前の検査では検出できない。
  部分一致の正規表現だったことも弱かった。
- 対応内容:
  - 名前の取得を `{ name: "マニュアル詳細へ", exact: true }` に変更(名前が汚れたら落ちる)。
  - **アイコンが名前を汚さないこと**は別契約として
    `expect(link.querySelector("svg")).toHaveAttribute("aria-hidden", "true")` で見る。
  - mutation D の記述を「**1 本目の `svg` 属性 assert だけ**が落ちる。accessible name は
    変わらないので名前の検査では検出できない」へ書き換えた。

## [Suggestion] (施策 2) `compareDocumentPosition` はビット契約を明示せよ

- 判断: **対応する**
- 対応内容: `toBeTruthy()` → `.toBe(Node.DOCUMENT_POSITION_FOLLOWING)` に変更。

## [Warning 相当の指摘] PHP enum とフロント union の同期は別途保証が要る

- 判断: **対応する**(記録のみ。新しい gate は作らない)
- 根拠: 現状 `resources/js/types/manual.ts` の docblock が「乖離検知は当面手動確認」と
  宣言済みで、これは本 TODO 以前からある既知の穴である。ここで同期 gate を新設するのは
  スコープ外(思考原則 2)。
- 対応内容: 「保証しないもの」に**ドリフトを検出しない**ことを明記した。

## [その他] mutation F の最小化

- 判断: **対応する**
- 対応内容: 「別 Inertia 画面へ差し替える」を
  「`Inertia::render('Manuals/Show', …)` を `'Manuals/Edit'` へ 1 語だけ変える」へ具体化した。
