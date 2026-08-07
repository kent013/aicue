# 対応マトリクス: impl-review Round 1

## [Critical] `tests/js/pages/Error.test.ts` の「Inertia Link ではない」テストが空振りし得る

- 判断: **対応する**
- 根拠: 指摘のとおりだった。`@inertiajs/svelte` 3.3.1 の `dist/link.js` を確認したところ、
  `Link` が付ける `data-*` は**読み込み中の `data-loading` だけ**で、通常時は素の `<a href>` として
  描画される。したがって `tagName === "A"` / `href` / `data-inertia === null` の 3 点は
  「Button に `inertia` を付ける」退行を 1 つも検出できない (実測でも green のままだった)。
  S5 の中核契約 (419 の CSRF token 再取得には document の作り直しが要る) が固定できていなかった。
- 対応内容:
  1. `tests/js/support/InertiaLinkStub.svelte` を新設。描画されると `data-testid="inertia-link-stub"` を残す。
  2. `tests/js/pages/Error.test.ts` で `vi.mock("@inertiajs/svelte", …)` により `Link` をスタブへ差し替え、
     Error ページ描画時に**スタブが 1 つも現れないこと**を表明する。
  3. **負のコントロールを併置**: `Button` を `inertia: true` で直接描画してスタブが現れることを表明する
     (mock 自体が効いていない場合の空振りを塞ぐ)。
  4. mutation で検証済み: `Error.svelte` の `<Button href={...}>` を `<Button href={...} inertia>` に変えると
     `戻り先が通常の <a href> で描画される (Inertia Link を使わない)` が **1 failed** になり、元に戻すと green。

## [Warning] `Button` の既定挙動 (href 時に native anchor か) が差分に無く断定できない

- 判断: **対応する** (上記 Critical の対応で同時に解消)
- 根拠: `resources/js/components/atoms/Button.svelte` は本タスクで変更していないため差分に現れないが、
  実装は `{#if href !== undefined && inertia}` → Inertia `Link` / `{:else if href !== undefined}` → 素の `<a>`
  という分岐で、`inertia` の既定値は `false`。よって Error ページは native anchor になる。
  ただし「差分だけでは確認できない」という指摘自体が正しく、テストで固定されていなければ
  Button 側の既定値が変わった瞬間に契約が黙って壊れる。
- 対応内容: 上記の負のコントロール付きスタブ検査が、Button 側の分岐の変化も含めて
  「Error ページの戻り先は Inertia Link を経由しない」を behavioral に固定する。

## その他

- 全ファイル「指摘なし」。設計との一致性・PHPStan 適合・DTO/Inertia props 一本化・
  セキュリティ (存在オラクル / open redirect / キャッシュ表現) について新たな指摘は無し。
