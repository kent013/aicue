# 対応マトリクス: conceptual-review Round 3

## [Warning] D11 の「description がフルロードと SPA 遷移で一致する」保証は現状の SoT では実現できない

- 判断: **対応する**
- 根拠: 指摘は正しい。実測で裏を取った:
  `app/Http/Middleware/HandleInertiaRequests.php::share()` が SEO 関連で共有しているのは
  **`'title'` のみ** (L83)。description の共有 prop もクライアント反映経路も存在しない。
  したがって Blade の `<head>` が再描画されない SPA 遷移では、初回ページの
  `<meta name="description">` が残る。Round 2 で D11 に書いた
  「フルロードと SPA 遷移で一致し」は **title の性質を description にまで広げた誤り**だった。
- 対応内容: D11 の不変条件を title / description で射程を分けて書き直した:
  > `<title>` の SoT はサーバ (`SeoManager::resolveDocumentTitle`) ただ 1 つであり、
  > **フルロードと SPA 遷移で一致する** (共有 prop `title` + `document-title.ts` が同期)。
  > `<meta name="description">` は **サーバが生成する初回 HTML のみを SoT とし**、
  > クライアントから第二 SoT や重複タグを作らない。

  併せて「なぜ description の SPA 追従を保証しないのが妥当か」を明記した
  (description の読み手はクローラ = 初回 HTML を読む。SPA 遷移後の追従価値はほぼ無く、
  共有 prop + クライアント反映機構 + テストを足すのは「今必要なものだけ作る」に反する)。
  さらに §スコープ外 に「`<meta name="description">` の SPA 遷移追従」を明示追加し、
  本バッチが description について保証するのは
  「初回 HTML のサーバ SoT を、クライアントの第二 SoT / 重複タグで壊さない」ことのみ
  であると射程を確定させた。

  なお **gate 自体の実装は変わらない** (`<svelte:head>` 内の `<title>` /
  `<meta name="description">` を禁止する、で不変)。変わったのは
  「その gate が何を保証していると主張するか」の文書上の正確さのみ。

## [Suggestion] その他 (使命整合・効果・リスク・スコープ・型安全性の肯定的評価)

- 判断: 見送る (対応不要)
