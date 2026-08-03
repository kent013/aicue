# 対応マトリクス: design-review Round 2

## [Critical] 施策2: JSON 204 応答では鍵は「次の Inertia 応答」まで残るので、その間の popstate は保護されない
- 判断: **全面的に対応する（反論しない。前回の説明が不正確だった）**
- 根拠: 指摘が正しい。`Inertia::clearHistory()` はサーバ session にフラグを積むだけで、
  `sessionStorage` の鍵を消すのは**クライアントが `clearHistory: true` を含む Inertia 応答を
  受け取った瞬間**。204 を受けて画面遷移しないまま戻る操作をすれば、鍵は生きており復元できる。
  「無条件実行だけで JSON logout も保護できる」という前回の書き方は誤り。
  無条件実行は**必要条件であって十分条件ではない**。
- 補足事実（実コードで確認）: このアプリの**ログアウト UI は 1 本しかない**。
  `resources/js/components/templates/AppLayout.svelte` の docblock に
  「ログアウト POST はこのレイアウトの単一ハンドラに一本化する」と明記され、実体は
  `router.post('/logout')` = Inertia visit。302 を XHR が追従して着地の Inertia 応答
  (`clearHistory: true` 入り) を必ず受け取る。
  リポジトリ内で JSON logout を叩いているのは
  `tests/Browser/AuthenticatedPageBfcacheTest.php::bfcacheLogoutInBrowser()` (経路 B の再現補助) だけ。
- 対応内容:
  (a) `LogoutResponse` の docblock を
      「無条件実行は**必要条件**。204 応答では次の Inertia 応答を受けるまでクライアント鍵は残る」
      に修正し、アプリのログアウト UI が単一 Inertia 経路であること (= 実運用では即時に届くこと) と、
      JSON 経路がテスト補助であることを明記する。
  (b) 施策 6 の文書で経路 C の保証条件を
      「**`clearHistory: true` を含む Inertia 応答を受信したタブ**」に厳密化する ([Warning] 施策6 と同根)。

## [Warning] 施策2: docblock の表現修正
- 判断: 対応する（上記 (a) に統合）

## [Suggestion] 施策2: JSON logout 後の Feature テストの位置づけ
- 判断: 対応する（テスト名を「次の Inertia 応答で clearHistory が消費される」に限定し、
  「JSON logout 経路が安全」と誤読させない。テスト内コメントにも書く）

## [Warning] 施策3: `Inertia::getVersion()` を事前に読むのは不安定
- 判断: **対応する（提案より強い方法を採る）**
- 根拠: 指摘のとおり不安定。理由はより具体的で、
  `Inertia::version()` は `HandleInertiaRequests::version()` から **リクエスト処理中に**
  クロージャで設定される (`vendor/inertiajs/inertia-laravel/src/Middleware.php` L112-114)。
  よってリクエスト前に `Inertia::getVersion()` を読むと空文字になり得る一方、
  実処理中の version は manifest 由来のハッシュになり、
  `X-Inertia-Version` 不一致 (Middleware.php L149) で 409 に落ちる。
  「string なら付ける」だけでは、この**タイミング差**は解決しない。
- 対応内容: version は**サーバ応答から取得する**。
  先に `GET /dashboard` の page ペイロードから `version` を読み、その値を
  以降の `X-Inertia-Version` に使う (サーバの自己申告値なので必ず一致する)。

## [Suggestion] 施策3: 302 追従は Browser テストの責務であることをコメントに書く
- 判断: 対応する（Feature テストのコメントに明記）

## [Warning] 施策4: MutationObserver が同一タスク内の追加→削除を取り逃す
- 判断: **対応する**
- 根拠: 妥当。callback は microtask でまとめて呼ばれるため、
  callback 時点の `document.body.innerText` には既に無い可能性がある。
- 対応内容: callback で
  (1) 現在の `document.body.innerText`、
  (2) 各 `MutationRecord` の `addedNodes[].textContent`、
  (3) `characterDataOldValue: true` を指定した上での `record.oldValue`
  の 3 つを検査する。

## [Warning] 施策4: 「描画されない」ではなく「DOM に出現しない」の検証である
- 判断: 対応する（コメントの表現を「途中の DOM 出現を検出」に修正。
  本件の PII は Svelte の通常テキストノードとして描画されるため実用上十分、と根拠も添える）

## [Warning] 施策4: red 確認は 2 段階に分ける必要がある
- 判断: **対応する**
- 根拠: 妥当。実装前は正のコントロール 1 (ArrayBuffer) が先に落ちるため、
  同じ実行で `__piiSeen === true` までは到達しない。
- 対応内容: 実装順序の記述を 2 段階に分ける。
  (1) 正のコントロール 1 を一時的に外した状態でテストを走らせ、
      `__piiSeen === true` / `/login` に倒れないことを確認 (= F-4-01 の再現)、
  (2) 正のコントロール 1 を戻し、暗号化不成立で fail することを確認。
  その後に実装して green にする。

## [Warning] 施策6: 保証条件を「ログアウトを実行したタブ」から厳密化する
- 判断: 対応する（上記 Critical (b) と同一。文書の主語を
  「`clearHistory: true` を含む Inertia 応答を受信したタブ」にする）

## [Suggestion] 施策4: ArrayBuffer 前提のヘルパ化
- 判断: 前回どおり見送り（判定式は 1 箇所。前提はコメントで明示済み）
