# Round 3: 概念設計の修正版 (2 回目)

Round 2 の Warning 2 件に対応した。対応マトリクスと修正後の該当箇所を示す。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Warning] RP ID の検査条件 `[A-Za-z0-9.-]+` では host 形式を保証できない
- 判断: 対応する
- 根拠: 指摘のとおり `-example.com` / `example..com` / `example.com.` が通る。
  「host 形式でないなら停止する」と書きながら判定が緩いのは、検査が形骸化する典型。
  既存の `TrustedHostsConfigValidator` は同じ包含正規表現だが、あちらは
  「scheme / port / path の混入を弾く」目的であり、こちらは
  **RP ID がパスキーの束縛先そのもの**なので厳密さの要求が違う。
- 対応内容: DNS ラベル単位の検査に差し替えた (各ラベル 1〜63 文字・英数字とハイフン・
  先頭末尾がハイフンでない・空ラベル無し・末尾ドット不可・全体 253 文字以下)。
  許可する接続元の host にも同じ検査を通す。

## [Warning] 「空要素は違反」と「config 段で落とす」が整合していない
- 判断: 対応する
- 根拠: 指摘のとおり。正規化して隠すのは「設定事故を起動時に検出する」という目的と正反対。
  Round 1 では機構を増やさない判断で raw を持たせなかったが、
  既存の `trustedproxy.raw_proxies` がまさにこの用途の作法として存在する以上、
  新しい機構ではなく**既存の型に寄せる**方が正しい。
- 対応内容: `raw_allowed_origins` (trim のみ・空要素も保持) を expose し、
  validator が空要素を違反として報告する形にした。framework が読む `allowed_origins` は
  従来どおり空要素を除いた列。

## [Suggestion] config:cache 下で真偽値が成立する / 実行時に env() を再参照しない
- 判断: 対応する (設計の当初意図どおり。明文化する)
- 対応内容: 詳細設計で「Guard は config 経由でのみ読む (env() 直読み禁止)」と明記し、
  config cache 往復テストで固定する。

## [Suggestion] stringList() が不正要素を黙って除去しないことをテストで固定
- 判断: 対応する
- 対応内容: 詳細設計のテスト計画に「非 string 要素が混ざった場合の挙動」を追加する。

---

## 修正後の概念設計 (全文)

# 概念設計: passkey-config-hardening

## 背景・課題

パスキー (WebAuthn) は **単独でログインできる強い資格**であり、その正しさは 3 つの設定値に依存する。

| 値 | 役割 | 現状 (HEAD 実測) |
|----|------|------------------|
| relying party id (身元の識別子) | パスキーがどのドメインに束縛されるか | vendor 既定 `parse_url(config('app.url'), PHP_URL_HOST)` |
| allowed origins (許可する接続元) | どの接続元からの WebAuthn 手続きを受け入れるか | vendor 既定 `[config('app.url')]` |
| user handle secret (利用者ハンドルの導出鍵) | 利用者ハンドル (`hash_hmac`) の鍵 | vendor 既定 `env('PASSKEYS_USER_HANDLE_SECRET', config('app.key'))` |

実測した現状:

- `config/passkeys.php` は**アプリ側に存在しない**。3 値ともパッケージ既定
  (`vendor/laravel/passkeys/config/passkeys.php`) のまま。
- `.env.example` に `PASSKEY` を含む行は **0 件**。
  代わりに「パスキーは専用の env を持たない」という説明段落が置かれている。
- `ProductionEnvGuard` は 13 項目を本番起動時に fail-fast 検査するが、**パスキーの 3 値は 1 つも見ていない**。
- `docs/auth-security-mechanisms.md` §5 の「運用上の注意」に
  「`APP_KEY` をローテートすると利用者ハンドルが変わり登録済みパスキーが**全件無効**になる。
  鍵ローテートを行う場合は `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を
  `config/passkeys.php` に持たせる**設計変更が必要**」と、必要な設計変更が未着手のまま記録されている。

このため次の設定事故が **起動時には検出できず、利用者がパスキーを使う瞬間まで表面化しない**:

1. `APP_URL` が本番で誤っている / scheme が `http` / host が `localhost` のまま → RP ID と許可する接続元が
   まとめて誤り、登録は成功するのに検証が全件失敗する (あるいは意図しないドメインにパスキーが束縛される)。
2. `APP_URL` が host を持たない値 → `Config::string('passkeys.relying_party_id')` が
   手続きの実行時に例外になり **500** になる (起動時ではない)。
3. `PASSKEYS_USER_HANDLE_SECRET` 未宣言のまま `APP_KEY` をローテート →
   **登録済みパスキーが全件無効**。利用者から見ると「昨日まで使えた指紋認証が今日から通らない」。

もう 1 つの課題が **版の固定**である。`laravel/passkeys` は `laravel/fortify` の推移依存として入っており
(実測: 制約 `^1.37` → `laravel/fortify v1.37.2` → その要求 `laravel/passkeys ^0.2.0` → 解決値 **v0.2.1**)、
`composer.json` に**直接の要求が無い**。しかしアプリは `Laravel\Passkeys\*` を
Provider / Response / binder / 契約検査など **10 ファイル以上で直接 import している**。
`laravel/passkeys` は 0.x であり semver の後方互換保証が無いため、
`0.3.0` が入ると設定キー名・契約インタフェース・route 名が予告なく変わりうる。
既存の契約検査 `tests/Architecture/PasskeyPackageContractTest.php` は 9 本あるが、
**どの版に対して検証済みなのかを固定する検査を持たない**。

## 改善アイデア

**施策 A (設定の明示と本番 fail-fast)**

1. アプリ側 `config/passkeys.php` を新設し、上記 3 値を**明示的に宣言**する
   (vendor の `mergeConfigFrom` は上位キー単位でアプリ側が勝つため、この 3 キーだけを持つ最小のファイルにする)。
   既定値は現行と同じく `APP_URL` / `APP_KEY` からの導出を残しつつ、
   `PASSKEYS_RELYING_PARTY_ID` / `PASSKEYS_ALLOWED_ORIGINS` / `PASSKEYS_USER_HANDLE_SECRET` の env で上書きできるようにする。
2. `app/Support/PasskeyConfigValidator.php` (純粋クラス・`final`・`RuntimeException`) を新設し、
   `ProductionEnvGuard::violations()` から呼ぶ。**`TrustedProxiesConfigValidator` / `TrustedHostsConfigValidator` と完全に同形**
   (引数は `string` / `list<string>` / `bool` に固定し、`mixed` からの絞り込みは Guard 側の既存 `stringList()` に任せる)。
   検査は **`Features::enabled(Features::passkeys())` が真のときだけ**行う
   (`config/fortify.php` の `Features::passkeys(['confirmPassword' => false])` が唯一の有効化点。
   機能を止めている環境に設定を要求しない)。本番で次のいずれかなら起動を止める:
   - RP ID が空 / **DNS ラベル検証**に通らない / ラベルが 1 つだけ (`localhost` 等) / IPv4 リテラル
     (DNS ラベル検証 = ドットで分割した各ラベルが 1〜63 文字・英数字とハイフンのみ・先頭と末尾がハイフンでない・
     空ラベルを含まない (`example..com` / `.example.com` / 末尾ドットを弾く)・全体 253 文字以下。
     `[A-Za-z0-9.-]+` の包含検査だけでは `-example.com` / `example..com` / `example.com.` が通ってしまうため、
     **ラベル単位で検査する**)
   - 許可する接続元の宣言に**空要素がある** (`https://a.example.com,,` のような末尾カンマ = 設定の書き損じ。
     黙って除去せず違反にする)
   - 許可する接続元が空 / `https://host[:port]` 形式でない (scheme は小文字 `https` のみ) /
     path・query・fragment・userinfo・末尾スラッシュを含む / host が DNS ラベル検証に通らない
   - 接続元の host が RP ID と一致せず、その下位ドメインでもない (WebAuthn が要求する関係)
   - 利用者ハンドルの導出鍵が**宣言されていない** (`PASSKEYS_USER_HANDLE_SECRET` 未設定 = `APP_KEY` 由来のまま) / 空 / 32 文字未満

   **「宣言されているか」で判定し、「`APP_KEY` と値が違うか」では判定しない**。
   守りたいのは「導出鍵が `APP_KEY` から独立して宣言されていること」であり、値の不一致はその代理にすぎない。
   代理で判定すると、既存のパスキーを維持するために**現行 `APP_KEY` と同じ値を意図して宣言する**移行が弾かれ、
   導入そのものが不可能になる (Codex 概念レビュー Round 1 [Critical])。
   宣言の有無は `config/passkeys.php` が `user_handle_secret_declared` として expose する
   (`trusted_hosts.raw_wildcard_suffixes` / `trustedproxy.raw_proxies` と同じ
   「config 段の事実を起動時検査へ渡す」作法。`config:cache` 後も値が残る)。
3. `.env.example` に 3 つのキーを追記し、既存の「専用の env を持たない」段落を書き換える。
   `tests/Architecture/EnvExampleInvariantTest.php` の作法に合わせ、
   **本番で未宣言なら起動が止まるキー** (`PASSKEYS_USER_HANDLE_SECRET`) の提示を検査で固定する。

**施策 B (版 pin)**

4. `composer.json` に `laravel/passkeys` の直接要求を追加する
   (直接 import しているパッケージは直接要求するのが Composer の作法。
   家系 6 本のうち aicue だけがこれを持たない)。
5. `tests/Architecture/PasskeyPackageContractTest.php` に版 pin の検査を 2 本足す。
   **`composer.lock` の解決値**と **`composer.json` の制約**の両方を見る (根拠は後述)。

## 期待効果

- 使命への貢献: 撮影 PWA (同一オリジン・セッション認証) の主戦場はスマホであり、
  パスキーは現場作業者が最も摩擦なくログインできる手段である。
  本施策が守るのは**認証手段の可用性と継続性** (現場作業者がログイン不能になる事故を防ぎ、
  撮影 PWA への到達性を保つこと) であって、教材設計そのものではない。
- 設定事故の検出時点が「利用者が認証しようとした瞬間 (本番・個別ユーザー)」から
  「デプロイ前の起動時 (`production:preflight` で機械判定)」へ前倒しになる。
  ただし **`APP_URL` 由来の派生を許す以上、「env 明示必須と同等の安全性」にはならない**。
  得られるのは「`APP_URL` の危険な値 (host 無し / `http` / `localhost` / RP ID との不整合) を
  起動時に検出する」ところまでである。
- `APP_KEY` ローテートとパスキーの生存が**分離**される (現在は連動していて、
  ローテート = 全パスキー無効という地雷が docs に記録されたまま放置されている)。
- パッケージが 0.3 系に上がったとき、契約検査 9 本の前提を再確認する前に
  **無言で入ることがなくなる**。

## 実装方針（概要）

| # | 変更 | 種別 |
|---|------|------|
| A-1 | `config/passkeys.php` 新設 (3 キー + 宣言の有無 + 生の接続元列) | 新規 |
| A-2 | `app/Support/PasskeyConfigValidator.php` 新設 | 新規 |
| A-3 | `app/Support/ProductionEnvGuard.php` に検査を追加 (feature flag が有効なときだけ) | 変更 |
| A-4 | `.env.example` の追記・既存段落の書き換え | 変更 |
| A-5 | `docs/auth-security-mechanisms.md` §5 運用上の注意の更新 / `AGENTS.md` の運用要件に 1 段落 | 変更 |
| B-1 | `composer.json` に `laravel/passkeys` の直接要求 (+ `composer.lock` の更新) | 変更 |
| B-2 | `tests/Architecture/PasskeyPackageContractTest.php` に版 pin 検査 2 本 | 変更 |

テスト (施策ごとに必須):

- `tests/Unit/Support/PasskeyConfigValidatorTest.php` (新規): 純粋クラスの全検査の正常系・異常系。
- `tests/Feature/Support/ProductionEnvGuardTest.php`: baseline に有効値を足し、1 項目ずつ崩す検査を追加。
  feature flag が無効なら検査ごと skip されることも固定する。
- `tests/Feature/Config/ConfigHardeningTest.php`: 既存 helper `evaluateConfigFileWithEnv()` で
  `config/passkeys.php` の env 派生 (未設定時の `APP_URL` 導出 / env 明示時の優先 / CSV 分割) を固定。
- `tests/Architecture/EnvExampleInvariantTest.php`: `PASSKEYS_USER_HANDLE_SECRET=` の提示を固定。
- `tests/Architecture/PasskeyPackageContractTest.php`: 版 pin 2 本 + 設定 3 キーが config cache 往復後も残ること +
  **vendor 既定キー (`timeout` / `guard` / `middleware` / `management_middleware` / `redirect`) が
  アプリ config の新設で消えていないこと** (`mergeConfigFrom` の上位キー単位マージに依存しているため)。

検証コマンド (依存更新を含むため実装時に必ず通す): `composer test` / `composer phpstan` / `vendor/bin/pint --test`。

## 制約・前提

- **既存の作法に寄せる**のが最優先。`TrustedProxiesConfigValidator` (純粋クラス + `ProductionEnvGuard` からの
  try/catch 写像 + `production:preflight` からの再利用 + `.env.example` の提示 + Unit/Feature テスト) が完成した型として
  既にあるため、パスキーもそこへ**そのまま**乗せる。新しい機構は作らない。
- `laravel/passkeys` の設定キー名は 0.2 系の契約である。施策 B の版 pin はこの前提の保護でもある
  (アプリ側 `config/passkeys.php` は「vendor と同じキー名」でしか効かないため、
  キー名が変わると**無言で既定へ戻る**)。
- 本リポジトリに**デプロイ定義は無い** (AGENTS.md)。したがって本施策も「人手で守る運用要件」が 1 つ増える。
  存在しないデプロイ基盤のための preflight 機構は**作らない** (既存 `production:preflight` に相乗りするだけ)。
- **これは意図的な破壊的運用変更である** (`TRUSTED_PROXIES` (T108) と同性質)。
  `PASSKEYS_USER_HANDLE_SECRET` を宣言せずに本番を起動すると fail-fast する。
  AGENTS.md の運用要件へ「初回デプロイ前に設定が要る」ことを追記し、
  `docs/auth-security-mechanisms.md` §5 に手順を書く。
- 既に本番でパスキーが登録されている環境では、`PASSKEYS_USER_HANDLE_SECRET` に
  **現行 `APP_KEY` の値をそのまま**宣言すれば既存パスキーは維持される
  (宣言の有無で判定するため、値が `APP_KEY` と同一でも検査は通る)。以後の `APP_KEY` ローテートは
  パスキーに影響しなくなる。この手順を docs に書く。
- 許可する接続元の CSV は **trim のみ**を許す。framework が読む `allowed_origins` は
  空要素を除いた列にするが、**除いた事実を隠さない**ために `raw_allowed_origins` (trim のみ・空要素も保持) を
  併せて expose し、validator が空要素を違反として報告する。
  これは `trustedproxy.proxies` / `trustedproxy.raw_proxies` と**同じ形**である
  (config 段で落ちた値を起動時 fail-fast で表面化させる既存の作法)。

## スコープ外

- ledger の施策 3b (パッケージ側の削除処理を参照する箇所) — 本件の範囲外。
- 施策 3a (所有者 FK の文字列比較) / 施策 4 (2FA 許可一覧) — 実装済み。
- `laravel/passkeys` の `timeout` / `guard` / `middleware` / `redirect` 等、
  事故が観測されていないキーの明示化 (今必要なものだけ作る)。
- パスキー専用の runbook 新設 (`docs/auth-security-mechanisms.md` §5 が正本のまま)。
- RP ID の public suffix 判定 (`TrustedHostsConfigValidator` と同じ理由で format 検査に留める)。

---

残る Critical / Warning があれば挙げ、無ければ全体判定 APPROVED を明示してほしい。
