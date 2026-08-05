# 対応マトリクス: conceptual-review Round 1

## [Critical] スコープ外 1 (`verified` / 2FA 強制 gate の同型残存) を exemption 凍結で済ませるのは弱い

- 判断: **対応する (凍結ではなく閉じる方針へ変更)**
- 根拠: 指摘を受けて「なぜ閉じられないと思ったのか」を再検査した結果、前提が誤っていた。
  Laravel には middleware priority list (`$middleware->appendToPriorityList()`) があり、
  本リポジトリは既に `McpConsentOrganizationBinder` で同 API を使っている
  (`bootstrap/app.php:168-171`)。テナント guard を `SubstituteBindings` の**直後**に pin すれば、
  `verified` / 2FA 強制 / 課金ゲート / Inertia version mismatch (409) の**すべて**が guard より後になる。
  唯一の懸念だった「cross-org 404 応答に `SecurityHeaders` / `NoStoreCacheHeaders` /
  `EncryptHistory` が乗らなくなる」波及も、**既に同じ契約が確立済み**であることを実査で確認した:
  `tests/Feature/Security/SecurityHeadersTest.php:163-171`
  「binding 失敗 404 には Permissions-Policy が一切付かない (SecurityHeaders は
  SubstituteBindings より内側のため到達せず、ヘッダは付かない = fail-safe)」。
  したがって cross-org 404 が binding 失敗 404 と同じ扱いになるのは**既存契約との一致**であり、
  むしろ「不在と cross-org が応答ヘッダまで完全同一になる」という副次的な改善になる。
- 対応内容: 概念設計を全面改訂。新 S2「middleware priority list によるテナント guard の
  SubstituteBindings 直後 pin」を新設し、スコープ外 1 を削除。
  web 側の exemption は 0 件、API 側は `ResolveApiActor` の 1 件のみになる。
  残る 1 件は Codex 指摘に従い構造化メタデータ (risk / owner / remediation / revisit) 付きで登録する。

## [Critical] `ShortCircuits` / `Transparent` の 2 分類では条件付き短絡を扱い切れない

- 判断: **一部対応 / 一部反論**
- 反論根拠: 「route parameter に依存せず同一応答になる short-circuit は oracle になりにくい」は
  本アプリの構造では成立しない。比較対象は「別の id に対する応答」ではなく
  **`SubstituteBindings` が返す不在 404** である。actor 状態にしか依存しない短絡 (例: `verified` の 302) でも
  「実在する他組織 id = 302 / 不在 id = 404」に分岐するため、**route 非依存でも 1 bit 漏れる**。
  実際 W-1 (課金ゲート 402/302) と W-2 (recent-auth 302/409) はいずれも actor 状態のみに依存する短絡であり、
  それでもオラクルになっている。したがって「route 依存かどうか」で緩めてはならない。
- 対応根拠 (分類粒度): ただし「粗すぎて過検出になる」という懸念自体は正しい。
  そこで**分類を細分化するのではなく、判定基準を `SubstituteBindings` の位置に相対化**する。
  `SubstituteBindings` **より前**に走る短絡 (Authenticate の 302 / Throttle の 429 / CSRF 419 /
  AuthenticateSession の logout) は、不在 id もまだ 404 になっていないため**構造的に oracle になり得ない**。
  よって規則は「**SubstituteBindings とテナント guard の間に ShortCircuits middleware を置かない**」となり、
  2 分類のままで過検出も見逃しも起きない。
- 対応内容: S4 の不変条件を上記の相対判定に書き換え。exemption レコードは Codex 提案どおり
  `risk` / `owner` / `remediation_todo` / `revisit_condition` を必須項目にする
  (理由 30 文字以上という長さ基準は廃止)。

## [Warning] S1 の順序表から `SubstituteBindings` が抜けている

- 判断: 対応する
- 対応内容: 順序表に `SubstituteBindings` を明記。あわせて S4 で「テストは
  `gatherMiddleware()` (宣言順) ではなく `Router::gatherRouteMiddleware()` (priority 適用後の実行順)
  を検査する」と明記した。

## [Warning] S2 の「{project} 不在 route で完全 no-op」をテストで固定せよ

- 判断: 対応する
- 対応内容: priority pin 方式に変わっても同じ懸念が残る (guard が全 web route の早い位置に来るため)。
  「{project} を持たない route では `$request->route('project')` が Project でなく素通し」を
  Feature テストで固定する項目を S4 のテスト計画に追加。

## [Warning] S5 の TrustProxies 実装・config key の実装確認が必要

- 判断: 対応する (実査済みの根拠を設計に明記)
- 対応内容: `vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php`
  の `setTrustedProxyIpAddresses()` が `$this->proxies() ?: config('trustedproxy.proxies')` を読むこと、
  `proxies()` は `static::$alwaysTrustProxies ?: $this->proxies` であり
  **`TrustProxies::at()` を呼ばない限り config へ落ちる**ことを設計に転記。
  `bootstrap/app.php` から `at:` を渡さない (= `Middleware::trustProxies()` が
  `TrustProxies::at()` を呼ばない) ことをテストで固定する項目を追加。
  config:cache 後も config 経由で読めることをテスト対象に含める。

## [Warning] 期待効果の「同型の穴の再発」表現

- 判断: 対応する
- 対応内容: priority pin により web の残存が無くなったため、表現を
  「exemption inventory に登録された既知例外 (現在 API の `ResolveApiActor` 1 件のみ) を除く」に修正。

## [Warning] S6 の global append が route middleware より前という前提をテストで固定

- 判断: 対応する
- 対応内容: `Middleware::getGlobalMiddleware()` が
  `array_unique(array_merge($prepends, $global, $appends))` を返すこと (実査済み) を根拠に、
  「`RedirectToHttps` が `TrustProxies` より後・route middleware より前」を
  解決後の global middleware 列で検証する Architecture テストを追加。

## [Warning] `TrustedProxiesConfigValidator` の型

- 判断: 対応する
- 対応内容: config は `list<non-empty-string>` を返し、`*` / `**` / `REMOTE_ADDR` / 空要素 /
  空白のみ要素を明示的に扱う。生値 (`raw_proxies`) も `trusted_hosts` と同様に保持し、
  config 段で silent drop された値を production 起動時に fail-fast させる。

## [Warning] 残存リスクの TODO 登録を完了条件に含めよ

- 判断: 対応する
- 対応内容: 本設計では web の残存が消えたため対象は
  (a) API の `ResolveApiActor` exemption、(b) MCP の idempotency replay 先行、
  (c) 認証手段変更の通知ポリシー の 3 件。いずれも「完了条件に TODO 登録を含める」と明記した。

## [Suggestion] S7 の enum + subscriber map は PHPStan 10 と相性が良い

- 判断: 反映済み (変更なし)
