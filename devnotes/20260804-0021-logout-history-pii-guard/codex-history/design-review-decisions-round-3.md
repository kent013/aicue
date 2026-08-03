# 対応マトリクス: design-review Round 3

## [Critical] 施策6: 「ログアウト UI は Inertia visit 一本」という不変条件が文書/コメントだけで機械的に固定されていない
- 判断: **対応する（新規施策 7 として Architecture テストを追加）**
- 根拠: 指摘のとおり。経路 C の保証はこの前提に乗っているのに、
  将来 JSON 204 の logout 導線が増えても現行の Feature / Browser テストは green のまま。
  AGENTS.md 禁止事項 1「不変条件は Architecture/Feature テストへの登録まで含めて実装済み」を満たさない。
- 対応内容: `tests/js/architecture/logout-call-site-inventory.test.ts` を新設し、
  `resources/js/` 配下から `/logout` を参照するファイルを **deny-by-default で走査**して
  inventory（`components/templates/AppLayout.svelte` の 1 件）と完全一致することを固定する。
  併せて当該ハンドラが `router.post`（Inertia visit）を使っており、
  `fetch(` / `axios` による非 Inertia 経路になっていないことも検証する。
  既存の同型テスト（`svg-inline-allowlist.test.ts` / `lucide-scoped-import.test.ts`）と同じ様式にする。
  「logout 処理を専用モジュールへ一本化する」案は、呼び出し元が 1 箇所しかない現状では
  抽象化の前倒しになるため採らない（原則 2）。inventory テストで十分に固定できる。

## [Warning] 施策2/6: 保証条件は「受信したタブ」ではなく「クライアントが page を適用したタブ」
- 判断: **対応する**
- 根拠: 実装上の境界は受信ではなく `page.set()` 冒頭の `history.clear()` の完了
  (`@inertiajs/core` `src/page.ts` L78-80)。通信断や JS 例外で適用前に中断すれば鍵は残る。
- 対応内容: 詳細設計・docblock・文書案のすべてで
  「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」に統一する。
  docblock の「302 を追従して必ず受け取る」も「**正常完了時に適用する**」に修正する。

## [Warning] 施策3: `page.version` が null の可能性
- 判断: **対応する（事実を確認した上で明示 assert する）**
- 根拠: 実装を確認したところ `ResponseFactory::render()` は
  `Response` に `$this->getVersion()` を渡し (`ResponseFactory.php` L375)、
  `getVersion(): string` は `(string) $version` を返す (`L154-161`) ため
  **page.version は常に string**（空文字はあり得る）。null にはならない。
  ただしテストが暗黙の前提に乗るのは避けるべきという指摘は妥当。
- 対応内容: `expect($version)->toBeString()` で前提を明示し、
  空文字のときは `X-Inertia-Version` ヘッダ自体を付けない（実ブラウザの挙動に揃える）。

## [Warning] 施策4: `body` 置換時に observer が外れる
- 判断: **対応する**
- 根拠: 妥当。observer の監視対象が detach されると以後の変化を拾えない。
- 対応内容: 監視対象を `document.documentElement`（subtree: true）にする。
  判定側は live 参照の `document.body?.innerText` のままにする
  （`documentElement.textContent` にすると `<script>` 等の非表示テキストまで拾い、
  偽陽性で flaky になるため。監視対象と判定対象は分けるのが正しい）。

## [Warning] 施策4: 正のコントロールの一時コメントアウトによる red 確認は手作業
- 判断: **対応する**
- 根拠: 妥当。戻し忘れが起きうる手順を設計に書くべきではない。
- 対応内容: Browser テストを **2 本に分割**する。
  (a) 「F-4-01 再現テスト」= PII 非復元 + `/login` 着地（実装前は PII 復元で fail）
  (b) 「history 暗号化が実際に効いている」= `window.history.state.page instanceof ArrayBuffer`
      （実装前は平文で fail）
  これで一時的なコード改変なしに red → green を確認できる。
  (a) は暗号化が degrade すれば PII が復元されて落ちるため、単体でも空振りしない。

## [Suggestion] 施策2 docblock の表現
- 判断: 対応する（上記 Warning に統合）

## [Suggestion] 施策3: テスト中の asset version 変化
- 判断: 対応不要（単一テスト内では変化しない。指摘も「問題なし」）
