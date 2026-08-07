# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: CHANGES_REQUESTED / [Critical] 0 件・[Warning] 6 件・[Suggestion] 3 件。

## [Warning] 観点 2: `Error.svelte` の CTA を disabled 化しない契約を明記せよ (禁止事項 8)

- 判断: **対応する**
- 根拠: AGENTS.md 禁止事項 8 は aicue の明示禁止事項であり、エラー画面という「押せないと詰む」面で
  最も守られるべき。指摘は正当。
- 対応内容: 「戻り先はサーバ側に固定した許可一覧から出す」節に、
  (a) 戻り先は必ず 1 件以上返る (どちらの分岐も 2 件)、(b) `Error.svelte` に disabled 状態を実装しない、
  (c) 空リストが渡り得ないことを型とテスト (全 status × 認証状態) で固定する、を追記。

## [Warning] 観点 3: `Error.svelte` が遅延 chunk だと 500 時に画面自体が出ない

- 判断: **対応する** (指摘は事実として正しい)
- 根拠: `resources/js/inertia.ts` を確認したところ `import.meta.glob("./pages/**/*.svelte")` の
  **非 eager** glob で、全ページが遅延 chunk。しかも `resolvePage()` は未解決時に throw するため、
  chunk 取得失敗は SPA の例外停止になり、現状 (モーダル表示) より悪化する。
  概念設計の成功条件「今日より悪くならない」に直接抵触していた。
- 対応内容: 実装方針に `resources/js/inertia.ts` の変更を追加し、
  「`Error` ページだけ eager glob で初期 bundle に含める」節を新設。eager 維持を JS 側
  Architecture テストで固定することも明記。

## [Warning] 観点 3: respond 単一スロット gate の検出範囲が不足

- 判断: **対応する**
- 根拠: 指摘どおり。`Inertia::handleExceptionsUsing()` は内部で `$handler->respondUsing()` を
  呼ぶため (`ResponseFactory.php:397-430` で確認済み)、`->respond(` だけの走査では素通りする。
  gate が守るべきは「単一スロットを奪う登録が 1 箇所」であって「respond の文字列が 1 個」ではない。
- 対応内容: 検出対象を `->respond(` / `->respondUsing(` / `handleExceptionsUsing(` の 3 入口に拡張し、
  走査対象を `app/` + `bootstrap/` + `routes/` + `config/`、許可箇所を `bootstrap/app.php` の
  1 箇所のみに固定する旨を表で追記。

## [Warning] 観点 5: 素通しテストをケースごとに分けよ

- 判断: **対応する**
- 根拠: 素通し条件は P1〜P5 で守っている対象が異なり、1 本にまとめると「どの条件が壊れたか」が
  失敗メッセージから分からない。aicue の既存 gate も条件ごとにテストを分ける作法。
- 対応内容: 「素通し契約は条件ごとにテストを分ける」節を新設し、8 ケースの表を追加
  (409+X-Inertia-Location / 409 / 302 / 4xx+Location / 422 / api・expectsJson / admin / 非 Inertia)。

## [Warning] 観点 5: 応答正規化の適用範囲を「入力 id の echo」に限定せよ

- 判断: **対応する** (元設計の意図と同じだが、契約が散文で曖昧だった)
- 根拠: 指摘のとおり、ここが緩いと存在オラクル検査が空洞化する。契約は箇条書きで固定すべき。
- 対応内容: 3 点の契約 (テストローカルに置く / 置換対象は自分が入れた id 文字列のみ /
  置換 0 件は fail) を明示。`Tests\Support\ResponseSignature` は変更しないことも再確認。

## [Warning] 観点 6: `ApiExceptionRenderer` 変更は (c) の既存挙動に触れる → 回帰テストを同一 PR に

- 判断: **対応する**
- 根拠: 「実挙動は変わらない」は主張ではなくテストで示すべき、という指摘は正当。
  app/ 配下に HTTP-date 形式の `Retry-After` 発行箇所は 0 件と確認済みだが、
  それは「今そうである」に過ぎず、契約として固定されていない。
- 対応内容: 「API 側 (c) の回帰テストを同一 PR に入れる」節を新設。
  4 ケース (整数 / 整数文字列 / 解釈不能な文字列 / 未設定) を固定する。

## [Warning] 観点 7: DTO を作るだけで配列を手書きすると型安全性の恩恵が薄い

- 判断: **対応する**
- 根拠: 正当。props 生成の入口が 2 つあると DTO が飾りになる。
- 対応内容: 実装方針の表で `ErrorScreenData` に
  「`toInertiaProps(): array` が props 生成の唯一の入口」と明記。array shape の phpdoc は
  詳細設計で具体化する。

## [Suggestion] 観点 1: 方針は North Star に直接貢献する

- 判断: **見送る** (指摘ではなく肯定的評価のため対応不要)

## [Suggestion] 観点 4: 「待ち時間が表示される」は「表示可能になる」に弱めるべき

- 判断: **対応する**
- 根拠: `Retry-After` が無い 429 経路では非表示が正しい挙動であり、断定は不正確。
- 対応内容: 期待効果の (c) 整合の項に「`Retry-After` が非負整数で存在するときに表示可能になる」
  と補足。

## [Suggestion] 観点 6: (a)(c) を混ぜない判断は適切

- 判断: **見送る** (肯定的評価のため対応不要)
