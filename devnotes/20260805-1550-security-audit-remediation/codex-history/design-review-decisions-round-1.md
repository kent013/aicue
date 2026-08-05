# 対応マトリクス: design-review Round 1

## [Critical] S3-b: controller での手動解決は middleware 短絡より遅い

- 判断: **一部反論 / 一部対応 (テスト期待値の誤りは修正)**
- 反論根拠: 存在オラクルの成立条件は「**不在 id と実在の他テナント id で応答が分岐すること**」であり、
  その分岐は `SubstituteBindings` が不在 id だけを 404 にすることから生まれる。
  `{user}` を implicit binding から外す (action 引数を `string` で受ける) と
  **SubstituteBindings は `{user}` を一切解決しない**ため、
  不在 id も実在の非メンバー id も**まったく同じ経路**を辿る。
  未契約組織のユーザーなら両方とも課金ゲートの 302、メール未確認なら両方とも 302、
  正常なユーザーなら両方とも controller の 404 になる = **分岐しない**。
  これは `{notification}` で既に採用済みのパターンであり
  (`routes/web.php` の注記「implicit binding を使わず controller が
  `$request->user()->notifications()` 経由で解決する = cross-user は構造的に 404」)、
  専用 middleware を新設するより単純で、既存の作法に一致する。
- 対応内容 (テスト期待値の誤り): 設計書のテスト計画が
  「未契約組織のユーザーで … **同一 404**」と書いていたのは誤り。
  未契約組織では両方とも 302 になる。期待値を
  「**完全同一応答** (状態に応じて 302 / 404 のいずれでも、2 ケースで一致すること)」に修正した。
- 対応内容 (機械保証の追加): `ManualOwnerScopedResolution` モードには
  「**その param が implicit binding されていないこと**」という条件が付く。
  条件が破れる (action 引数がモデル型に戻される) と即座にオラクルが復活するため、
  S4 に mode → 検査規則の対応表を追加し、
  `ManualOwnerScopedResolution` の param は
  `RouteBindingTypes` の手動解決 exclusion に登録済みであること + action 引数がモデル型でないことを
  機械検証する項目を追加した。

## [Critical] S5: `TRUSTED_PROXIES=none` が silent-drop 検査で reject される

- 判断: 対応する (指摘どおりの設計バグ)
- 対応内容: silent-drop 検査の対象から sentinel `none` を除外し、
  「`none` 単独 かつ `proxies === []`」のみ正常系と判定するよう検査順序を明示した。
  検査ロジックを擬似コードで書き下し、テストケースも `none` 単独 / `none` + 他 token の
  2 パターンを追加した。

## [Critical] S5: CIDR validation が緩すぎる

- 判断: 対応する
- 対応内容: 正規表現による判定をやめ、`App\Support\TrustedProxyToken::isValid()` を新設して
  **config 段と validator 段で同一関数**を使う。判定は
  slash で分割 → IP 部を `FILTER_VALIDATE_IP` → prefix を IPv4 は 0-32 / IPv6 は 0-128 で範囲検査。
  config:cache は評価結果 (plain array) を保存するため関数呼び出しでも問題ない。

## [Warning] S4: 解決済み middleware 文字列の正規化を仕様化せよ

- 判断: 対応する
- 対応内容: 「`explode(':', $m, 2)[0]` で parameter を落とした**具象クラス名**で分類する」と明記。
  サンプル map の `Inertia\Middleware::class` を
  `App\Http\Middleware\HandleInertiaRequests::class` に修正した
  (実装を確認: `class HandleInertiaRequests extends Middleware`)。

## [Warning] S4: named rate limiter の closure が route param を読む可能性

- 判断: 対応する (良い指摘)
- 対応内容: pre-binding 短絡の検査に「limiter 単位の振る舞い検査」を追加。
  テナント guard を持つ route が使う全 bucket (`api-read` / `api-write` / `render-trigger` /
  `passkeys`) について、**ある id で bucket を使い切ったあと別の id で 429 になる**ことを
  Feature テストで固定する (= key が route param を含まないことの behavioral proof)。

## [Warning] S1: ヘッダ完全一致比較は volatile ヘッダで不安定

- 判断: 対応する
- 対応内容: `Date` / `Set-Cookie` / `X-RateLimit-*` / `Retry-After` / request id 系を除外する
  normalize helper を経由して比較する、と明記した
  (既存の `ItemAuthorizationTest:264` は `json()` 比較のみで安定しているため変更不要)。

## [Warning] S7: vendor event の property 名が未確定

- 判断: 対応する (実物を確認して確定)
- 対応内容: `vendor/laravel/passkeys/src/Events/PasskeyRegistered.php` /
  `PasskeyDeleted.php` を確認し、いずれも
  `__construct(public Authenticatable $user, public Passkey $passkey)` であることを確定。
  設計書に転記した。`$event->passkey->getKey()` は `mixed` を返すが
  `metadata: array<string, mixed>` に入れるため PHPStan level 10 で追加のキャストは不要。
  TypeScript / Filament 側の `event_type` 直書きは
  `rg 'password_changed|two_factor_enabled|api_key_issued' resources/js app/Filament` で
  **0 件**を確認済み (label は enum 経由) → 波及変更なしと確定した。

## [Warning] S8: 「Authenticate より後だから user 単位」は保証になっていない

- 判断: 対応する
- 対応内容: `passkeys` limiter の定義 (`FortifyServiceProvider.php:188-194`。
  `$request->user()?->getAuthIdentifier()` があれば `passkey|{id}`、無ければ `passkey|{ip}`) を
  設計書に転記したうえで、**別ユーザー同士で bucket が共有されない** Feature テストを追加した。

## [Suggestion] frontend 該当なし / DTO パターン維持

- 判断: 反映済み (変更なし)
