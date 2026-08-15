# 詳細設計: passkey-config-hardening

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

本施策は教材設計そのものではなく、**現場作業者が撮影 PWA へログインし続けられること (認証手段の可用性・継続性)** を守る基盤改善である。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。解析対象に **`config/` が含まれる**ため、
  変更する `config/fortify.php` も level 10 を通す必要がある（`env()` は `mixed` を返すので必ず絞り込む）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成
- **DTO + JsonResource** パターン
- アーリーリターン推奨
- コードフォーマット: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260815-1111-passkey-config-hardening/conceptual-design.md`（Codex 概念レビュー Round 3 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | パスキー設定ブロックの明示（`config/fortify.php` の `passkeys` ブロック） | `config/fortify.php` | High |
| 2 | 設定事故ガード（`PasskeyConfigValidator` + `ProductionEnvGuard`） | `app/Support/PasskeyConfigValidator.php`（新規）, `app/Support/ProductionEnvGuard.php` | High |
| 3 | `.env.example` への提示 | `.env.example`, `tests/Architecture/EnvExampleInvariantTest.php` | High |
| 4 | `laravel/passkeys` の版 pin | `composer.json`, `composer.lock`, `tests/Architecture/PasskeyPackageContractTest.php` | High |
| 5 | 運用契約の記述（docs / AGENTS.md） | `docs/auth-security-mechanisms.md`, `AGENTS.md` | Medium |

**実装順序**: 1 → 2 → 3 → 4 → 5（2 は 1 の config キーに依存する。4 は独立だが lock 更新を伴うので最後にまとめる方が競合が少ない）。

---

## 施策 1: パスキー設定ブロックの明示（`config/fortify.php` に `passkeys` ブロックを追加）

> ⚠ **設計途中で判明した重要な事実（Round 2 の実測で判明。当初案は誤りだったので破棄した）**
>
> 当初案は「アプリ側 `config/passkeys.php` を新設して vendor 既定を上書きする」だった。**これは動かない。**
> `Laravel\Fortify\FortifyServiceProvider::register()` は `configurePasskeys()` を呼び、
> そこで **`passkeys.*` を無条件に上書きする**:
>
> ```php
> // vendor/laravel/fortify/src/FortifyServiceProvider.php L121-141（実測）
> config([
>     'passkeys.relying_party_id' => config('fortify.passkeys.relying_party_id', parse_url(config('app.url'), PHP_URL_HOST)),
>     'passkeys.allowed_origins'  => config('fortify.passkeys.allowed_origins', [config('app.url')]),
>     'passkeys.user_handle_secret' => config('fortify.passkeys.user_handle_secret', config('app.key')),
>     'passkeys.timeout' => config('fortify.passkeys.timeout', 60000),
>     'passkeys.guard' => config('fortify.guard', 'web'),
>     'passkeys.middleware' => config('fortify.middleware', ['web']),
>     'passkeys.management_middleware' => config('fortify-options.passkeys.confirmPassword', true) ? ['password.confirm'] : [],
>     'passkeys.redirect' => Fortify::redirects('login'),
>     'passkeys.throttle' => $this->passkeyThrottleMiddleware(),
> ]);
> ```
>
> `config([...])` は**絶対値の設定**なので、アプリの `config/passkeys.php` に何を書いても
> これらのキーは Fortify の値で潰される（`config/fortify.php` に `passkeys` キーは
> **現状 1 つも無い**ため、実際に効くのは右辺の fallback = `APP_URL` / `APP_KEY` 導出である）。
>
> **したがって正しい宣言場所は `config/fortify.php` の `passkeys` ブロック**である。
> これは Fortify が用意している拡張点そのもの（思考原則 1: フレームワークのレンジ内でやる）。
> `config/passkeys.php` は**新設しない**（置いても効かない死んだ設定になり、
> 「設定したのに効かない」という新種の事故を作る）。

### 変更箇所

- ファイル: `config/fortify.php`（`features` ブロックの後ろに `passkeys` ブロックを追加）

### 波及変更

- TypeScript 型定義: なし（設定はサーバ側のみ。クライアントは `resources/js/lib/passkeys.ts` が
  ブラウザ API を叩くだけで RP ID を知らない）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Config/ConfigHardeningTest.php`（env 派生の固定）、
  `tests/Architecture/PasskeyPackageContractTest.php`（**Fortify の写像が生きていること**を
  sentinel で検査 + config cache 往復 + **Fortify 結線後の実効キーが揃っていること**）

### 現行コード

`config/fortify.php` に `passkeys` キーは無い。したがって実効値は Fortify の fallback:

```php
'passkeys.relying_party_id'   = parse_url(config('app.url'), PHP_URL_HOST)  // host が無いと null
'passkeys.allowed_origins'    = [config('app.url')]                        // 末尾スラッシュ等は webauthn-lib 側で正規化
'passkeys.user_handle_secret' = config('app.key')                          // ← APP_KEY と直結
```

読み出し側（vendor）:

```php
Passkeys::relyingPartyId()  => Config::string('passkeys.relying_party_id');   // null なら例外
Passkeys::allowedOrigins()  => Config::array('passkeys.allowed_origins', []); // 空なら RuntimeException
PasskeyAuthenticatable::getPasskeyUserHandle() => hash_hmac('sha256', "{table}|{id}", Config::string('passkeys.user_handle_secret'), binary: true);
```

`laravel/passkeys` 側の既定（`vendor/laravel/passkeys/config/passkeys.php`）は
`PasskeysServiceProvider::register()` の `mergeConfigFrom` で入るが、
上記 3 値については Fortify の `config([...])` が後から**必ず**上書きする。

### 変更後コード

`config/fortify.php` の末尾（`features` ブロックの後）に追加する。
**ファイル冒頭のコメント欄外で変数を組み立てず、`return [...]` の直前に置く**
（既存 `config/trusted_hosts.php` / `config/trustedproxy.php` と同じ形）:

```php
/*
|--------------------------------------------------------------------------
| パスキー (WebAuthn) の設定
|--------------------------------------------------------------------------
|
| ⚠ **宣言場所がここである理由**: Laravel\Fortify\FortifyServiceProvider::register() の
| configurePasskeys() が `passkeys.*` を `config('fortify.passkeys.*')` から
| **無条件に上書きする**ため、アプリ側 config/passkeys.php を置いても効かない。
| Fortify が読むこのキーが唯一の宣言点である。
|
| ⚠ **他の config ファイルを config() で読まない**（読み込み順に依存するため）。
| ここでは env() だけを見る（APP_KEY / APP_URL は config/app.php と同じ env を読む）。
|
| 既定値は APP_URL / APP_KEY からの導出で、同一オリジン PWA (v1 スコープ) では
| 通常 env の宣言なしで正しく動く。ただし **PASSKEYS_USER_HANDLE_SECRET だけは
| production で宣言が必須**（未宣言だと APP_KEY ローテートで登録済みパスキーが全件無効）。
| 検査は App\Support\PasskeyConfigValidator (ProductionEnvGuard 経由) が起動時に行う。
| 運用契約は docs/auth-security-mechanisms.md §5。
|
*/

$appUrl = parse_url((string) env('APP_URL', ''));

$appUrlScheme = is_array($appUrl) && is_string($appUrl['scheme'] ?? null) ? strtolower($appUrl['scheme']) : '';
$appUrlHost = is_array($appUrl) && is_string($appUrl['host'] ?? null) ? strtolower($appUrl['host']) : '';
$appUrlPort = is_array($appUrl) && is_int($appUrl['port'] ?? null) ? ':'.$appUrl['port'] : '';

// APP_URL の origin (scheme://host[:port])。path / query は落とす。
$derivedOrigin = ($appUrlScheme !== '' && $appUrlHost !== '')
    ? $appUrlScheme.'://'.$appUrlHost.$appUrlPort
    : '';

$declaredRelyingPartyId = env('PASSKEYS_RELYING_PARTY_ID');
$declaredRelyingPartyId = is_string($declaredRelyingPartyId) ? strtolower(trim($declaredRelyingPartyId)) : '';

$declaredOrigins = env('PASSKEYS_ALLOWED_ORIGINS');
$declaredOrigins = is_string($declaredOrigins) ? trim($declaredOrigins) : '';

// 宣言があれば CSV を trim + **小文字化**して保持する (空要素は落とさない)。
// ★小文字化は load-bearing である。webauthn-lib の照合は
//   `in_array($normalizedOrigin, $this->fullOrigins, true)` = **strict な文字列比較**で
//   (vendor/web-auth/webauthn-lib/src/CeremonyStep/CheckAllowedOrigins.php 実測)、
//   ブラウザは常に小文字の origin を申告する。`HTTPS://App.Example.com` と書かれた設定は
//   一致せず**全ての手続きが無言で失敗する**ため、宣言の時点で小文字へ正規化する
//   (scheme と host は RFC 3986 上 case-insensitive なので、正規化は意味を変えない)。
// 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
// (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
$rawAllowedOrigins = $declaredOrigins !== ''
    ? array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $declaredOrigins))
    : [$derivedOrigin];

// ⚠ **値そのものは trim しない**。「既にパスキーがある環境は現行 APP_KEY の値を
//    そのまま宣言すれば維持できる」という運用契約を守るため
//    (APP_KEY に前後空白が含まれていた場合、trim すると別の鍵になり全件無効になる)。
//    trim を使うのは「宣言されたか (空白だけではないか)」の判定にだけ留める。
$declaredUserHandleSecret = env('PASSKEYS_USER_HANDLE_SECRET');
$declaredUserHandleSecret = is_string($declaredUserHandleSecret) ? $declaredUserHandleSecret : '';
$userHandleSecretDeclared = trim($declaredUserHandleSecret) !== '';

// ... config/fortify.php の return 配列の中（'features' => [...] の後ろ）に置く:

    'passkeys' => [
        /*
        | 身元の識別子 (relying party id)。パスキーはこの値に束縛され、
        | 一致するドメインでしか検証できない。host のみ (scheme / port を含めない)。
        | 未宣言なら APP_URL の host。Fortify が passkeys.relying_party_id へ写す。
        */
        'relying_party_id' => $declaredRelyingPartyId !== '' ? $declaredRelyingPartyId : $appUrlHost,

        /*
        | 許可する接続元 (allowed origins)。ブラウザが申告した origin がこの列に無ければ
        | WebAuthn の手続きを受け付けない。`scheme://host[:port]` 形式。
        | Fortify が passkeys.allowed_origins へ写し、webauthn-lib が読む。**空要素を除いた列**。
        */
        'allowed_origins' => array_values(array_filter(
            $rawAllowedOrigins,
            static fn (string $v): bool => $v !== '',
        )),

        /*
        | フィルタ前の接続元列 (trim・小文字化済み。**空要素を保持する**)。
        | ここでの「生」は「env の原文」ではなく「空要素を除去する前」の意味である。config 段で落ちた空要素を
        | 起動時 fail-fast で表面化させるために PasskeyConfigValidator が読む
        | (trustedproxy.raw_proxies と同じ役割)。**Fortify は本キーを読まない**
        | (検査専用。passkeys.* へは写らない)。
        */
        'raw_allowed_origins' => $rawAllowedOrigins,

        /*
        | 利用者ハンドルの導出鍵。hash_hmac の鍵として使われ、**変わると
        | 登録済みパスキーが全件無効になる**。未宣言なら APP_KEY に倒れるため、
        | APP_KEY ローテートがパスキー全件失効を意味してしまう。
        | production では宣言必須 (PasskeyConfigValidator が起動時に検査)。
        */
        'user_handle_secret' => $userHandleSecretDeclared
            ? $declaredUserHandleSecret
            : (string) env('APP_KEY', ''),

        /*
        | 導出鍵が **APP_KEY と独立して宣言されたか**。値の一致では判定しない
        | (既存パスキーを維持するために現行 APP_KEY と同じ値を意図して宣言する
        |  移行が正当なため)。config:cache 後も真偽値として残る。
        | **Fortify は本キーを読まない** (検査専用)。
        */
        'user_handle_secret_declared' => $userHandleSecretDeclared,
    ],
```

**`timeout` は宣言しない**。Fortify は `config('fortify.passkeys.timeout', 60000)` を読むが、
事故が観測されていない値を先回りで持たない（今必要なものだけ作る）。

### PHPStan適合チェック

- [x] `env()` の戻り値 `mixed` を `is_string` / `is_int` で必ず絞り込む
- [x] `parse_url()` の戻り値（`array|string|int|false|null`）を `is_array` で絞り込む
- [x] `array_filter` のコールバックは `static fn (string $v): bool`（`$rawAllowedOrigins` が `list<string>` であることが直前の `array_map` で確定している）
- [x] 配列を返す config ファイルなので DTO 化は対象外（Laravel の config 契約）

### テスト計画

- [ ] 新規: `tests/Feature/Config/ConfigHardeningTest.php` に追記（**既存 helper
      `evaluateConfigFileWithEnv()` が同ファイル内にあるため、そこへ足すのが唯一の正しい置き場**）。
      対象ファイルは **`fortify.php`** で、検査するのは戻り値の `passkeys` ブロック。
      （注: `config/fortify.php` の `features` は `Features::passkeys(['confirmPassword' => false])` を
      評価する = `fortify-options.passkeys` へ書き込む副作用がある。書き込まれる値は本番 config と同一なので
      テストへの影響は無いが、helper の再評価がこの副作用を伴うことは把握しておく）
  - `PASSKEYS_*` 全て未設定 + `APP_URL=https://app.example.com/sub` →
    `relying_party_id === 'app.example.com'` / `allowed_origins === ['https://app.example.com']`
    （path が落ちること）/ `user_handle_secret_declared === false`
  - `APP_URL=http://localhost:8000` → `allowed_origins === ['http://localhost:8000']`（port が残ること）
  - `APP_URL=` （host 無し）→ `relying_party_id === ''` / `allowed_origins === []`（例外を投げず空に倒れる）
  - `APP_URL=not-a-url`（scheme も host も無い文字列）→ 同上（`parse_url` が `path` だけを返す経路。
    config は例外を投げず空に倒れ、production では施策 2 が落とす）
  - `PASSKEYS_RELYING_PARTY_ID=App.Example.COM` → 小文字化されて入る
  - `PASSKEYS_ALLOWED_ORIGINS='https://a.example.com, https://b.example.com'` →
    2 件（前後の空白が trim される）
  - `PASSKEYS_ALLOWED_ORIGINS='HTTPS://App.Example.com'` → `'https://app.example.com'` に正規化される
    （**webauthn-lib の strict 比較に一致させるための load-bearing な小文字化**。
    ここを外すと運用者が大文字で書いた瞬間に全手続きが無言で失敗する）
  - `PASSKEYS_ALLOWED_ORIGINS='https://a.example.com,'`（末尾カンマ）→
    `allowed_origins` は 1 件 / `raw_allowed_origins` は 2 件で 2 件目が `''`（**落とした事実が残る**）
  - `PASSKEYS_USER_HANDLE_SECRET` 宣言時 → `user_handle_secret_declared === true` かつ値が入る
  - `PASSKEYS_USER_HANDLE_SECRET='   '`（空白のみ）→ `declared === false`（未宣言と同じ扱い）
  - **`PASSKEYS_USER_HANDLE_SECRET=' abc… '`（前後に空白を含む値）→ 値が trim されずそのまま入る**
    （「現行 `APP_KEY` の値をそのまま宣言すれば既存パスキーを維持できる」という運用契約の固定。
    ここで trim すると鍵が変わり全件無効になる）
- [ ] 新規: `tests/Architecture/PasskeyPackageContractTest.php` に **3 本**
      （既存の config cache 往復テストと同じ `var_export()` → `eval()` の再現方式に揃える。
      Pest から `config:cache` を実行すると `bootstrap/cache/config.php` を書き換えて
      `--parallel` を壊すため実行しない、という既存コメントの前提も同じ）

  ```php
  /*
   * ★本設計で最も重要な検査★
   * FortifyServiceProvider::register() の configurePasskeys() が
   * `passkeys.*` を `fortify.passkeys.*` から**無条件に上書きする**。
   * この写しが切れると、アプリの宣言は**無言で無視され APP_URL / APP_KEY 由来の
   * 既定へ戻る** (= 設定したのに効かない事故。設計中に実際に踏みかけた経路)。
   * 実効値と宣言値の一致を固定する。
   */
  test('Fortify が fortify.passkeys.* を passkeys.* へ写している (fallback と区別できる値で検査)', function (): void {
      // ★**素の一致比較では偽陰性になる**。通常環境では宣言値も fallback も同じ
      //   APP_URL / APP_KEY 由来なので、Fortify が fortify.passkeys.* を読まなくなっても
      //   両者は一致してしまう (design-review R3 指摘)。
      //   fallback では絶対に生まれない sentinel を宣言してから写像を実行する。
      config([
          'fortify.passkeys.relying_party_id' => 'sentinel.example.com',
          'fortify.passkeys.allowed_origins' => ['https://sentinel.example.com'],
          'fortify.passkeys.user_handle_secret' => str_repeat('s', 32),
      ]);

      // configurePasskeys() は protected。**vendor の写像そのもの**が検査対象なので
      // Reflection で直接叩く (register() 全体を再実行すると Response contract の
      // アプリ実装への差し替えまで Fortify 既定へ戻ってしまうため、対象を最小に絞る)。
      // 名前が変わればこのテストが落ちる = 版を上げたときに写像を再確認する契機になる。
      $provider = new FortifyServiceProvider(app());
      $configure = new ReflectionMethod($provider, 'configurePasskeys');
      $configure->setAccessible(true);
      $configure->invoke($provider);

      expect(config('passkeys.relying_party_id'))->toBe('sentinel.example.com');
      expect(config('passkeys.allowed_origins'))->toBe(['https://sentinel.example.com']);
      expect(config('passkeys.user_handle_secret'))->toBe(str_repeat('s', 32));
  });

  test('config cache 往復後もアプリ側の passkeys 宣言が残る', function (): void {
      $subset = ['fortify' => config('fortify'), 'passkeys' => config('passkeys')];
      $exported = var_export($subset, true);
      /** @var array<string, mixed> $roundTripped */
      $roundTripped = eval('return '.$exported.';');

      expect(data_get($roundTripped, 'fortify.passkeys.relying_party_id'))->toBeString();
      expect(data_get($roundTripped, 'fortify.passkeys.allowed_origins'))->toBeArray();
      expect(data_get($roundTripped, 'fortify.passkeys.raw_allowed_origins'))->toBeArray();
      expect(data_get($roundTripped, 'fortify.passkeys.user_handle_secret'))->toBeString();
      expect(data_get($roundTripped, 'fortify.passkeys.user_handle_secret_declared'))->toBeBool();
      expect(data_get($roundTripped, 'passkeys.relying_party_id'))->toBeString();
  });

  /*
   * アプリは passkeys の一部キーしか宣言しない。残りは Fortify の configurePasskeys() が
   * アプリ設定から組み立てるか、laravel/passkeys の既定が供給する。
   * この結線が崩れると **management_middleware / throttle が消えて保護が外れる**ため、
   * アプリ宣言を足した後も**実効キーが揃っている**ことを明示的に固定する。
   * (management_middleware / throttle は vendor 既定値ではなく Fortify の組み立て結果である)
   */
  test('アプリ宣言を足しても Fortify 結線後の実効キーが揃っている', function (): void {
      expect(config('passkeys.timeout'))->toBe(60000);
      expect(config('passkeys.guard'))->toBe('web');
      expect(config('passkeys.middleware'))->toBe(['web']);
      expect(config('passkeys.redirect'))->toBeString();
      // confirmPassword=false のため空配列になる (既存の「password.confirm 無効化」契約と対)。
      expect(config('passkeys.management_middleware'))->toBe([]);
      // limiters.passkeys から Fortify が組み立てる (既存の throttle 契約と対)。
      expect(config('passkeys.throttle'))->toBe('throttle:passkeys');
  });
  ```

- [ ] 既存テストの更新: `config/fortify.php` を触るため、`tests/Feature/Config/ConfigHardeningTest.php` /
      `tests/Architecture/PasskeyPackageContractTest.php` の既存 fortify 系アサートが green のままであることを確認する
      （`features` / `limiters` には手を入れないので壊れない見込み）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **Fortify の `configurePasskeys()` によるキー写像と組み立て規則に依存する**
  （`fortify.passkeys.*` → `passkeys.*` の写し、`management_middleware` は
  `fortify-options.passkeys.confirmPassword` から、`throttle` は `fortify.limiters.passkeys` から組み立てられる）。
  写像が変わると宣言が無言で無視される。
  **担当範囲を混ぜない**: 施策 4 の版 pin が守るのは `laravel/passkeys` の 0.2 系だけであり、
  写像を持つ `laravel/fortify` は pin しない（1.x の semver 管理）。
  Fortify 側は `PasskeyPackageContractTest` の **sentinel を使った実効値の契約テスト**が守る。
  なお `management_middleware` / `throttle` は **vendor 既定値ではなく Fortify がアプリ設定から
  組み立てた実効値**である（誇張しない）。
- `config:cache` 生成時に env が読まれる。**cache 生成後に env を変えても反映されない**のは
  Laravel 共通の前提であり本設計固有ではない（`route:cache` の運用要件と同じ扱い）。

---

## 施策 2: 設定事故ガード（`PasskeyConfigValidator` + `ProductionEnvGuard`）

### 変更箇所

- ファイル: `app/Support/PasskeyConfigValidator.php`（新規）
- ファイル: `app/Support/ProductionEnvGuard.php`（`violations()` に追記 + docblock の一覧に追記）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Support/PasskeyConfigValidatorTest.php`（新規）、
  `tests/Feature/Support/ProductionEnvGuardTest.php`（**既存 `beforeEach` の baseline に
  パスキーの有効値を追加する必要がある**。追加しないと既存 30 本超の
  「violations は 1 件」アサートが 2 件になって落ちる = 波及変更）

### 現行コード

```php
// app/Support/ProductionEnvGuard.php（末尾）
        // client IP の信頼境界 (TrustProxies allowlist) を起動時検証。
        $proxies = $this->stringList(config('trustedproxy.proxies', []));
        $rawProxies = $this->stringList(config('trustedproxy.raw_proxies', []), keepEmpty: true);
        try {
            (new TrustedProxiesConfigValidator)->validateForProduction($proxies, $rawProxies);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }
```

### 変更後コード

**新規 `app/Support/PasskeyConfigValidator.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * パスキー (WebAuthn) 設定 (config/fortify.php の passkeys ブロック → Fortify が passkeys.* へ写す)
 * の production 起動時検証。
 *
 * `TrustedProxiesConfigValidator` / `TrustedHostsConfigValidator` と同形
 * (final / 純粋クラス / RuntimeException / ProductionEnvGuard から try-catch で写像)。
 *
 * 背景: パスキーは**単独でログインできる強い資格**であり、正しさが 3 つの設定値に依存する。
 * これらは既定で APP_URL / APP_KEY から導出されるため、設定事故が
 * **利用者が認証しようとした瞬間まで表面化しない** (登録はできるのに検証が全件失敗する、
 * あるいは APP_KEY ローテートで登録済みパスキーが全件無効になる)。
 * production では起動時に落として、デプロイ前に気づけるようにする。
 *
 * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である
 * (TRUSTED_PROXIES と同性質)。`PASSKEYS_USER_HANDLE_SECRET` を宣言せずに
 * production を起動すると fail-fast する。既にパスキーが登録済みの環境では
 * **現行 APP_KEY の値をそのまま宣言すれば既存パスキーは維持される**
 * (宣言の有無で判定し、値の一致では判定しないため)。
 * 運用契約は docs/auth-security-mechanisms.md §5。
 *
 * 限界 (誇張しない): 本 validator は **書式と相互整合**しか見ない。
 * 「その host を本当に運用しているか」「TLS 証明書があるか」は検査できない。
 * **Public Suffix List を持たないため、`co.uk` のような public suffix を身元の識別子に
 * 置いた設定は通ってしまう** (ブラウザ側は PSL を見るので実際の手続きは失敗する)。
 * したがって本 validator は **WebAuthn の完全な妥当性検査ではない**。
 * PSL 判定のために依存を足すことはしない (TrustedHostsConfigValidator と同じ判断。
 * 誤設定の結果は「パスキーが使えない」であって権限昇格ではなく、
 * 設定するのは攻撃者ではなく運用者であるため)。この限界は
 * PasskeyConfigValidatorTest に**既知の限界として明示的なテストで記録する**。
 * production の身元の識別子・接続元は **DNS 名のみ**を対象とする
 * (IPv4 / IPv6 リテラルと単一ラベルは reject する = WebAuthn の relying party id にできない)。
 */
final class PasskeyConfigValidator
{
    /** DNS 名の最大長 (末尾ドットを含まない) */
    private const MAX_DNS_NAME_LENGTH = 253;

    /** DNS ラベルの最大長 */
    private const MAX_DNS_LABEL_LENGTH = 63;

    /** 導出鍵の最小長 (短い値は typo / placeholder の可能性が高い) */
    private const MIN_USER_HANDLE_SECRET_LENGTH = 32;

    /**
     * @param  string  $relyingPartyId  config 通過後の身元の識別子 (host のみ)
     * @param  list<string>  $allowedOrigins  config 通過後の許可する接続元 (空要素除去済み)
     * @param  list<string>  $rawAllowedOrigins  フィルタ前の接続元列 (trim・小文字化済み、空要素を保持)
     * @param  bool  $userHandleSecretDeclared  導出鍵が専用 env で宣言されたか
     * @param  string  $userHandleSecret  解決後の導出鍵
     *
     * @throws RuntimeException
     */
    public function validateForProduction(
        string $relyingPartyId,
        array $allowedOrigins,
        array $rawAllowedOrigins,
        bool $userHandleSecretDeclared,
        string $userHandleSecret,
    ): void {
        // 1. 身元の識別子。空 = APP_URL に host が無い (パスキーの手続きが実行時例外になる)。
        if ($relyingPartyId === '') {
            throw new RuntimeException(
                'Passkey relying party id is empty in production. '
                .'Set PASSKEYS_RELYING_PARTY_ID, or make sure APP_URL contains a host. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        // 2. 身元の識別子は production で受け付ける dotted DNS 名でなければならない。
        //    IP リテラル / localhost / 単一ラベルは WebAuthn の relying party id にできない。
        //    (public suffix かどうかはここでは見ない = PSL を持たない。docblock の限界を参照)
        if (! $this->isDnsName($relyingPartyId) || ! str_contains($relyingPartyId, '.')) {
            throw new RuntimeException(sprintf(
                'Passkey relying party id "%s" is not an accepted production DNS name. '
                .'It must be a dotted DNS name (e.g. app.example.com), not an IP address, '
                .'"localhost" or a single label. '
                .'(Public suffixes such as "co.uk" are not rejected here: this check has no Public Suffix List.)',
                $relyingPartyId,
            ));
        }

        // 3. 接続元の宣言に空要素がある = 設定の書き損じ (末尾カンマ / 連続カンマ)。
        //    config 段で落ちた事実を黙って正規化せず、起動時に表面化させる。
        foreach ($rawAllowedOrigins as $raw) {
            if (trim($raw) === '') {
                throw new RuntimeException(
                    'PASSKEYS_ALLOWED_ORIGINS contains an empty entry '
                    .'(a stray or trailing comma). List each origin exactly once as '
                    .'"https://host[:port]".'
                );
            }
        }

        // 4. 接続元が 1 件も無いと vendor が手続き実行時に例外を投げる (起動時には落ちない)。
        if ($allowedOrigins === []) {
            throw new RuntimeException(
                'Passkey allowed origins are empty in production. '
                .'Set PASSKEYS_ALLOWED_ORIGINS, or make sure APP_URL contains a scheme and host.'
            );
        }

        foreach ($allowedOrigins as $origin) {
            // 5. 書式。scheme は**小文字 https のみ** (production の WebAuthn は TLS 必須)。
            //    path / query / fragment / userinfo / 末尾スラッシュを弾く。
            //    ★大文字を通さないのは意図的である: 宣言側 (config/fortify.php) が小文字へ
            //      正規化するので、ここに大文字が届くのは「別経路が正規化せずに設定した」場合だけ。
            //      webauthn-lib は strict 比較なので、その値は**全手続きを無言で失敗させる**。
            //      黙って受理せず起動時に落とす (運用者が env へ書く大文字は config が吸収する)。
            if (preg_match('#^https://([a-z0-9.-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" is invalid. '
                    .'Each origin must be "https://dns-name[:port]" with no path, query or trailing slash. '
                    .'Plain http, IPv4/IPv6 literals and bracketed hosts are not accepted in production.',
                    $origin,
                ));
            }

            $host = $m[1];   // 正規表現が小文字だけを通すので strtolower しない
            $port = $m[2] ?? '';

            if (! $this->isDnsName($host)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" has an invalid host. '
                    .'Each label must be 1-63 alphanumeric/hyphen characters and must not start or end with a hyphen.',
                    $origin,
                ));
            }

            if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" has an out-of-range port.',
                    $origin,
                ));
            }

            // 6. WebAuthn は「身元の識別子が接続元 host と一致するか、その上位ドメインである」
            //    ことを要求する。ここが食い違うと**全ての手続きが失敗する** (登録も検証も)。
            if ($host !== $relyingPartyId && ! str_ends_with($host, '.'.$relyingPartyId)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" does not belong to the relying party id "%s". '
                    .'The origin host must equal the relying party id or be a subdomain of it, '
                    .'otherwise every passkey ceremony fails.',
                    $origin,
                    $relyingPartyId,
                ));
            }
        }

        // 7. 導出鍵は **APP_KEY から独立して宣言されている**こと。
        //    未宣言だと APP_KEY に倒れ、鍵ローテートで登録済みパスキーが全件無効になる。
        if (! $userHandleSecretDeclared) {
            throw new RuntimeException(
                'PASSKEYS_USER_HANDLE_SECRET is not set in production. '
                .'Without it the passkey user handle is derived from APP_KEY, so rotating APP_KEY '
                .'silently invalidates every registered passkey. '
                .'When migrating an environment that already has passkeys, declare the current APP_KEY value. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        if (strlen($userHandleSecret) < self::MIN_USER_HANDLE_SECRET_LENGTH) {
            throw new RuntimeException(sprintf(
                'PASSKEYS_USER_HANDLE_SECRET is shorter than %d characters. '
                .'Use a long random value (e.g. php -r "echo bin2hex(random_bytes(32));").',
                self::MIN_USER_HANDLE_SECRET_LENGTH,
            ));
        }
    }

    /**
     * DNS 名として妥当か (ラベル単位で検査する)。
     *
     * 包含正規表現 `[A-Za-z0-9.-]+` だけでは `-example.com` / `example..com` /
     * `example.com.` が通ってしまうため、ドットで分割して 1 ラベルずつ見る。
     *
     * ⚠ これは純粋な DNS 構文検証ではなく **production の WebAuthn 用に採用する DNS 名の方針**である
     * (DNS 構文自体は全数字の末尾ラベルも大文字も禁止していない)。
     *
     * **大文字を受理しない**のは、宣言側 (config/fortify.php) が小文字へ正規化するためである。
     * ここに大文字が届くのは別経路が未正規化のまま設定した場合で、その値は
     * webauthn-lib の strict 比較に一致せず**全手続きを無言で失敗させる**。
     *
     * **末尾ラベルに英字を 1 文字以上要求する**のは、`192.168.001.001` のような
     * 「filter_var では IP と認められないが実質 IP アドレスの書き損じ」を弾くため
     * (全数字の TLD は存在しない)。punycode (`xn--p1ai`) は英字を含むので通る。
     */
    private function isDnsName(string $host): bool
    {
        if ($host === '' || strlen($host) > self::MAX_DNS_NAME_LENGTH) {
            return false;
        }

        // IPv4 / IPv6 リテラルは relying party id にも origin host にも使えない。
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > self::MAX_DNS_LABEL_LENGTH) {
                return false;   // 空ラベル = 連続ドット / 先頭ドット / 末尾ドット
            }
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;   // ハイフン開始 / ハイフン終了 / 大文字 / 不正文字
            }
        }

        // 末尾ラベル (TLD 相当) は英字を含むこと。
        return preg_match('/[a-z]/', $labels[count($labels) - 1]) === 1;
    }
}
```

**`app/Support/ProductionEnvGuard.php` への追記**（`return $errors;` の直前）:

```php
        // パスキー (WebAuthn) の身元 / 接続元 / 利用者ハンドル導出鍵を起動時検証。
        // **キルスイッチが有効なときだけ**検査する (機能を止めている環境に設定を要求しない)。
        // 有効化点は config/fortify.php の Features::passkeys([...]) ただ 1 箇所。
        if (Features::enabled(Features::passkeys())) {
            $relyingPartyIdValue = config('passkeys.relying_party_id');
            $relyingPartyId = is_string($relyingPartyIdValue) ? $relyingPartyIdValue : '';
            // ★読み出し元が 2 つに分かれる理由:
            //   - 実効値 (passkeys.*) = **実際に手続きで使われる値**。Fortify の上書き後の姿を検査する。
            //   - 宣言の事実 (fortify.passkeys.raw_allowed_origins / user_handle_secret_declared) は
            //     検査専用キーで Fortify は passkeys.* へ写さない。宣言元から読むしかない。
            $originsValue = config('passkeys.allowed_origins', []);
            $rawOriginsValue = config('fortify.passkeys.raw_allowed_origins', []);
            $userHandleSecretValue = config('passkeys.user_handle_secret');
            $userHandleSecret = is_string($userHandleSecretValue) ? $userHandleSecretValue : '';
            $userHandleSecretDeclared = config('fortify.passkeys.user_handle_secret_declared') === true;

            // 文字列以外が混ざった config を **黙って除去しない** (除去すると設定破損を見逃す)。
            // trusted hosts / proxies 側の stringList() は「silent drop を raw で表面化させる」形だが、
            // passkeys は config が必ず string 列を返す設計なので、破損はそのまま violation にする。
            if (! $this->isStringList($originsValue) || ! $this->isStringList($rawOriginsValue)) {
                $errors[] = 'passkeys.allowed_origins and fortify.passkeys.raw_allowed_origins must be lists of strings.';
            } else {
                try {
                    (new PasskeyConfigValidator)->validateForProduction(
                        $relyingPartyId,
                        $originsValue,
                        $rawOriginsValue,
                        $userHandleSecretDeclared,
                        $userHandleSecret,
                    );
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
```

`ProductionEnvGuard` に private helper を 1 つ足す（`stringList()` の隣）:

```php
    /**
     * 値が string だけの list か (非 string を黙って除去せず、破損として扱うための判定)。
     *
     * @phpstan-assert-if-true list<string> $value
     */
    private function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }
```

**既存の `stringList()` は変更しない**（trusted hosts / trusted proxies の挙動を変えないため。
両者は「config 段の silent drop を raw 値で表面化する」設計で、破損の扱い方が違う）。

docblock の検査項目一覧にも 1 行足す:

```
 * - パスキー設定 (身元の識別子 / 許可する接続元 / 利用者ハンドルの導出鍵。
 *   書式・相互整合・導出鍵の独立宣言。Features::passkeys() 有効時のみ)
```

`use Laravel\Fortify\Features;` の import を追加する。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`validateForProduction(): void` / `isDnsName(): bool`）
- [x] null 安全（`config()` の `mixed` は `is_string` / `=== true` で絞り込む。
      `stringList()` は既存 helper で `list<string>` を返す）
- [x] DTO を返している（本 validator は例外を投げるだけで値を返さない = 既存 2 validator と同形）
- [x] Generics の型パラメータが正しい（`list<string>` を phpdoc で宣言）
- [x] `preg_match` の `$m` は `array<int, string>` として扱い、`$m[2] ?? ''` で null 安全
- [x] `isStringList()` に `@phpstan-assert-if-true list<string>` を付け、
      `else` 枝で `$originsValue` が `list<string>` に絞り込まれる（`mixed` のまま validator に渡さない）

### テスト計画

- [ ] 新規: `tests/Unit/Support/PasskeyConfigValidatorTest.php`
  - 正常系: `('app.example.com', ['https://app.example.com'], ['https://app.example.com'], true, str_repeat('a', 32))` が例外を投げない
  - 正常系: 接続元が下位ドメイン（`https://pwa.app.example.com`）でも通る
  - 正常系: port 付き（`https://app.example.com:8443`）が通る
  - 検査 1: 身元の識別子が空 → `'relying party id is empty'`
  - 検査 2: `localhost` / `192.0.2.1` / `192.168.001.001` / `-example.com` / `example-.com` /
    `example..com` / `.example.com` / `example.com.` / `exam ple.com` / `2001:db8::1` /
    **`APP.example.com`（大文字）**
    を **dataset** で回して全て reject（**DNS ラベル検査の負のコントロール**。
    `192.168.001.001` は `filter_var` が IP と認めないため、末尾ラベルの英字要求で落ちることを固定する。
    `APP.example.com` は **身元の識別子側の小文字限定**を固定する
    = 「env 由来の値は config が小文字化する」と「別経路の未正規化値は validator が拒否する」の
    2 つの契約を別々に固定するため、origin 側の大文字テストとは**別に必要**）
  - **既知の限界の記録**: `co.uk`（public suffix）を身元の識別子にした設定は
    **例外を投げない**ことをテストで明示的に固定する。
    テスト名は `既知の限界 (documented limitation): public suffix の身元識別子は通る` とし、「Public Suffix List を持たないため通る。
    ブラウザ側が PSL を見るので実際の手続きは失敗する」ことをコメントで説明する
    （PSL 判定を入れたら、このテストが赤くなって設計変更に気づける）
  - 検査 3: `rawAllowedOrigins` に空要素 → `'empty entry'`（`allowedOrigins` 側が有効でも落ちること）
  - 検査 4: 接続元が空 → `'allowed origins are empty'`
  - 検査 5: `http://app.example.com` / `HTTPS://app.example.com` / `https://APP.example.com` /
    `https://app.example.com/` /
    `https://app.example.com/path` / `https://user@app.example.com` / `https://app.example.com?x=1`
    を dataset で回して全て reject
  - 検査 5: port が `0` / `70000` → reject
  - 検査 6: `https://evil.example.net`（RP ID = `app.example.com`）→ `'does not belong to'`
  - 検査 6: `https://notapp.example.com` は `.app.example.com` で終わらないので reject
    （**接尾辞一致だけの実装なら通ってしまう境界。必ずテストする**）
  - 検査 7: `declared = false` → `'is not set in production'`（値が 32 文字以上でも落ちること）
  - 検査 7: `declared = true` かつ 31 文字 → 長さ違反
  - **検査の順序**: 身元の識別子が空 かつ 導出鍵も未宣言のとき、メッセージが
    「身元の識別子」であること（最初の違反で throw する既存 2 validator と同じ挙動）
- [ ] 既存テスト更新: `tests/Feature/Support/ProductionEnvGuardTest.php`
  - `beforeEach` の baseline に 5 キーを追加する。**読み出し元が 2 系統に分かれる**ので
    キー名を取り違えないこと（実効値は `passkeys.*`、検査専用は `fortify.passkeys.*`）:

    ```php
    config([
        'passkeys.relying_party_id' => 'app.example.com',
        'passkeys.allowed_origins' => ['https://app.example.com'],
        'passkeys.user_handle_secret' => str_repeat('a', 32),
        'fortify.passkeys.raw_allowed_origins' => ['https://app.example.com'],
        'fortify.passkeys.user_handle_secret_declared' => true,
    ]);
    ```
  - 新規: 導出鍵が未宣言なら violation が 1 件増える
  - 新規: 接続元が RP ID と不整合なら violation
  - 新規: **現行 config では `Features::enabled(Features::passkeys())` が真であること**
    （検査が実際に走っている前提の固定。これが偽だと以降の検査は全て空振りする）
  - 新規: **passkeys を外すと、上の不正設定でも violation が 0 件になる**
    （キルスイッチ時に設定を要求しないことの固定）。
    無効化は `Features::enabled()` の実装（`in_array($feature, config('fortify.features', []))`）に合わせ、
    `config(['fortify.features' => array_values(array_diff((array) config('fortify.features'), ['passkeys']))])`
    で行う。**`Features::passkeys([...])` は options 付きで呼ぶと `fortify-options` を書き換えるため、
    無効化には使わない**（引数なしなら副作用なく `'passkeys'` を返すだけ）
  - 新規: `passkeys.allowed_origins` に非 string（例 `['https://app.example.com', 123]`）が混ざったら
    **有効値と併存していても** violation になること（`isStringList()` による fail-closed の固定）
  - 新規: `fortify.passkeys.raw_allowed_origins` 側を壊した場合も violation になること
    （**2 系統それぞれを個別に壊す**。読み出し元の取り違えを検出する）
  - 新規: `passkeys.allowed_origins` が配列ですらない（文字列 / null）場合も violation になること
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **本番の起動を止める条件が増える**（意図した破壊的変更）。既存デプロイがあると
  `PASSKEYS_USER_HANDLE_SECRET` 未設定で起動しなくなる → 施策 3・5 の `.env.example` / docs / AGENTS.md で
  「初回デプロイ前に設定が要る」ことを明記して緩和する。
- `Features::enabled()` は `config('fortify.features')` を読む静的メソッドで、
  `ProductionEnvGuard` は `AppServiceProvider::boot()` から呼ばれる。**config は boot 時点で確定済み**なので
  順序上の問題は無い（Fortify の provider boot に依存しない）。
- production 以外では**何も検査しない**ため、local で誤設定しても気づけない。
  これは既存の `ProductionEnvGuard` 全体の性質であり、本施策で変えない（誇張しない）。

---

## 施策 3: `.env.example` への提示

### 変更箇所

- ファイル: `.env.example`（L200-204 付近の「パスキーは専用の env を持たない」段落を差し替え）
- ファイル: `tests/Architecture/EnvExampleInvariantTest.php`（提示の固定）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/EnvExampleInvariantTest.php`

### 現行コード

```
# パスキー (WebAuthn) は専用の env を持たない。Fortify が APP_URL から
# relying party id (ホスト) と allowed origins ([APP_URL]) を、user handle secret を
# APP_KEY から導出する (同一オリジン PWA 前提)。
# ⚠ APP_KEY をローテートすると既存パスキーの user handle が変わり全件無効になる。
#    運用契約は docs/auth-security-mechanisms.md §5 パスキー (WebAuthn) の「運用上の注意」。
```

### 変更後コード

```
# パスキー (WebAuthn) の設定 (config/fortify.php の passkeys ブロック)。
# 利用者ハンドルの導出鍵。**production では宣言が必須** (未宣言だと起動時に fail-fast する)。
# 未宣言のときは APP_KEY から導出されるため、APP_KEY をローテートすると
# 登録済みパスキーが全件無効になる (利用者から見ると「昨日まで使えた生体認証が通らない」)。
# **この行は空のプレースホルダである。production では必ず値を入れること** (空欄のままだと起動しない)。
# 32 文字以上のランダム値を生成して固定する: php -r "echo bin2hex(random_bytes(32));"
# 既にパスキーが登録されている環境を移行する場合は **現行 APP_KEY の値をそのまま**入れると
# 既存パスキーを維持できる (以後 APP_KEY のローテートはパスキーに影響しなくなる)。
# 運用手順は docs/auth-security-mechanisms.md §5。
PASSKEYS_USER_HANDLE_SECRET=
# 身元の識別子 (RP ID) と許可する接続元。未宣言なら APP_URL から導出する
# (RP ID = APP_URL の host、接続元 = scheme://host[:port])。
# 同一オリジン PWA 前提のため通常は宣言不要。別ホストから撮影 PWA を配信するときだけ宣言する。
# 接続元は CSV で、各 host は RP ID と一致するか RP ID の下位ドメインであること。
# PASSKEYS_RELYING_PARTY_ID=
# PASSKEYS_ALLOWED_ORIGINS=
```

**注意**: `PASSKEYS_RELYING_PARTY_ID` / `PASSKEYS_ALLOWED_ORIGINS` は
**コメントアウトのまま**提示する（空文字で置くと「宣言したが空」と紛らわしいため。
`GTM_CONTAINER_ID` と同じ既存の作法）。`PASSKEYS_USER_HANDLE_SECRET` だけは
**production で必須**なので、値なしのキーとして置き、テストで提示を固定する。

`tests/Architecture/EnvExampleInvariantTest.php` への追記:

```php
/*
 * パスキーの利用者ハンドル導出鍵。production で未宣言だと起動時 fail-fast するため
 * (App\Support\PasskeyConfigValidator)、.env.example に必ず提示して
 * 「設定し忘れてデプロイが落ちる」事故を減らす (TRUSTED_PROXIES と同じ理由)。
 */

test('.env.example に PASSKEYS_USER_HANDLE_SECRET が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    // **行頭一致**で見る (toContain だとコメント行 `# PASSKEYS_USER_HANDLE_SECRET=` でも通り、
    // 「宣言行として提示されている」ことを固定できないため)。
    expect($contents)->toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m');
});
```

### PHPStan適合チェック

- [x] tests/ は PHPStan の解析対象外（`phpstan.neon` の paths は `app` / `config` / `database` / `routes`）。
      ただし既存テストの書式（`expect($contents)->toBeString()` + `/** @var string */`）にそのまま揃える

### テスト計画

- [ ] 新規: 上記 `.env.example に PASSKEYS_USER_HANDLE_SECRET が含まれる`
- [ ] 既存テスト `コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い` が引き続き green
      （追記行に `${VAR}` を書かない。`PASSKEYS_USER_HANDLE_SECRET=` は空値なので影響なし）

### リスク

- コメントアウトしたキーは `collectUnresolvedEnvRefs()` の走査対象外（`#` 始まりは skip される）ため、
  既存テストへの影響はない。

---

## 施策 4: `laravel/passkeys` の版 pin

### 変更箇所

- ファイル: `composer.json`（`require` に直接要求を追加）
- ファイル: `composer.lock`（`composer require` により content-hash と packages 情報が更新される）
- ファイル: `tests/Architecture/PasskeyPackageContractTest.php`（検査 2 本を追加）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/PasskeyPackageContractTest.php`
- **CI**: `pnpm run audit:gate`（composer audit）が走る。解決版は変わらない（v0.2.1 のまま）ので
  advisory 判定に影響しない

### 現行コード

```jsonc
// composer.json（抜粋）
"laravel/fortify": "^1.37",
"laravel/framework": "^13.8",
"laravel/mcp": "^0.8.0",
"laravel/passport": "^13.7",
```

`laravel/passkeys` は `laravel/fortify v1.37.2` の要求（`^0.2.0`）として**推移的にのみ**入り、
解決値は **v0.2.1**。一方でアプリは `Laravel\Passkeys\*` を
`PasskeyServiceProvider` / `Passkey` モデル / Response 4 本 / `SelfScopedPasskeyBinder` /
契約検査など**多数のファイルで直接 import している**。

### 変更後コード

```jsonc
"laravel/mcp": "^0.8.0",
"laravel/passkeys": "^0.2.1",
"laravel/passport": "^13.7",
```

実装手順（worktree 内で実行し、`composer.json` と `composer.lock` を**必ず同じコミットに含める**）:

```bash
composer require "laravel/passkeys:^0.2.1" --no-scripts
```

解決版は既に v0.2.1 のため**依存は 1 つも動かない**（lock の content-hash と
`laravel/passkeys` の位置づけだけが更新される）。

`tests/Architecture/PasskeyPackageContractTest.php` への追記:

```php
/*
 * 版 pin。laravel/passkeys は **0.x** であり semver の後方互換保証が無い
 * (0.3.0 で設定キー名・contract・route 名が予告なく変わりうる)。
 * 本ファイルの他の検査 9 本と config/fortify.php の passkeys ブロックのキー名は
 * **0.2 系に対して検証する契約**であり、その前提が黙って動かないように 2 つの側面を固定する:
 *
 *   - composer.json の直接要求 = 「直接 import しているので直接要求する」設計意思と許容範囲。
 *     これが無いと laravel/fortify の推移要求が緩んだ瞬間に 0.3 系が無言で入る
 *     (aicue はかつてこの状態だった)。
 *   - composer.lock の解決値 = **いま実際に動いている版**。
 *     制約だけ見ても、lock が手で書き換えられた / platform 設定で別版が入った場合を捕まえられない。
 *
 * 0.2.x を外れるときは、本ファイルの契約検査 (route 名 7 本 / confirmPassword /
 * limiter / モデル差し替え / Response contract 4 本 / binder) と Fortify の configurePasskeys() が
 * 読むキー名 (fortify.passkeys.*) を再確認してから、この pin を更新すること。
 */

/** @return array<string, mixed> composer.json の require ブロック */
function composerRequireBlock(): array
{
    $raw = file_get_contents(base_path('composer.json'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */
    $require = $decoded['require'] ?? null;
    expect($require)->toBeArray();

    /** @var array<string, mixed> $require */
    return $require;
}

/** composer.lock の解決版 (例 "v0.2.1") を返す */
function lockedPackageVersion(string $name): ?string
{
    $raw = file_get_contents(base_path('composer.lock'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    $packages = $decoded['packages'] ?? [];
    expect($packages)->toBeArray();

    /** @var array<int, array<string, mixed>> $packages */
    foreach ($packages as $package) {
        if (($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
            return $package['version'];
        }
    }

    return null;
}

test('composer.json が laravel/passkeys を直接要求する (直接 import しているため)', function (): void {
    $require = composerRequireBlock();

    expect($require)->toHaveKey(
        'laravel/passkeys',
        'laravel/passkeys を直接 import しているのに直接要求が無い。'
        .'laravel/fortify の推移要求が緩むと 0.3 系が無言で入る'
    );

    $constraint = $require['laravel/passkeys'];
    expect($constraint)->toBeString();
    /** @var string $constraint */
    // 書き方は caret 1 種類に絞る (composer.json の他 20 件超がすべて caret のため)。
    // **前方一致では不十分**: `^0.20` / `^0.2 || ^1.0` / `^0.2.1 || ^0.3` / `^0.2@dev` が通り、
    // 特に `|| ^0.3` は「0.3 系を入れない」というこの検査の目的を破る (design-review R2 実測)。
    expect(preg_match('/^\^0\.2(?:\.\d+)?$/', $constraint))->toBe(
        1,
        "laravel/passkeys の制約は '^0.2' か '^0.2.<patch>' の形だけを許す: {$constraint}"
    );
});

test('composer.lock の laravel/passkeys が 0.2 系 (契約検査の検証済み範囲)', function (): void {
    $version = lockedPackageVersion('laravel/passkeys');

    expect($version)->toBeString('composer.lock に laravel/passkeys が無い');
    /** @var string $version */
    expect(str_starts_with(ltrim($version, 'v'), '0.2.'))->toBeTrue(
        "laravel/passkeys の解決版が 0.2 系を外れている: {$version}。"
        .'本ファイルの契約検査と fortify.passkeys.* のキー名を再確認してから pin を更新すること'
    );
});
```

**許容する制約表現**: `^0.2` または `^0.2.<patch>`（caret のみ・OR 結合なし・stability flag なし。
検査は前方一致ではなく **`/^\^0\.2(?:\.\d+)?$/` の完全一致**）。
`~0.2.1` / `0.2.*` は範囲としては同等だが**採らない** —
composer.json の既存 20 件超がすべて caret であり、表記を 1 つに揃えることで
「pin が緩んだか」を 1 行の目視で判定できる状態を保つ。
`composer/semver` を足して範囲判定する案は、依存を 1 つ増やす割に得るものが
「表記の自由度」だけなので採らない（今必要なものだけ作る）。

**判断（概念設計の論点への回答）**: **composer.lock と composer.json の両方**を見る。
`composer.json` は「直接依存という設計意思と許容範囲」、`composer.lock` は
「契約検査が実際に検証した解決版」を固定する。どちらか一方だけでは、
(a) 直接要求が消えたのに lock がたまたま 0.2 のまま、
(b) 制約は 0.2 なのに lock が別版、のどちらかを取り逃がす。
**版番号の完全一致（`v0.2.1` ちょうど）では固定しない** — patch 更新のたびにテストが赤くなり、
pin を惰性で書き換える運用に堕ちる。0.x で契約が壊れる境界は **minor** なのでそこを固定する。

### PHPStan適合チェック

- [x] tests/ は解析対象外だが、`json_decode` の戻り値は `expect()->toBeArray()` +
      `/** @var */` で明示的に絞り込む（既存 `PhpstanWrapperInvariantTest` / `GlobalTestLockInventoryTest` と同じ作法）

### テスト計画

- [ ] 新規: `composer.json が laravel/passkeys を直接要求する`
- [ ] 新規: `composer.lock の laravel/passkeys が 0.2 系`
- [ ] 既存テスト（契約検査 9 本）が引き続き green（依存は動かないので変化しないはず）
- [ ] `composer.json` 変更後に `composer validate`（`composer.lock` との整合）が通ること

### リスク

- `composer require` が `composer.lock` の広範な再生成を起こす可能性がある。
  **解決版が変わっていないこと**（`laravel/passkeys` = v0.2.1、他パッケージの version 差分が無いこと）を
  `git diff composer.lock` で確認してからコミットする。差分が広がった場合は
  `composer update laravel/passkeys --lock` などで最小差分に留める。
- worktree 運用ルール: `composer require` は task branch 上で実行可だが、
  変更した `composer.json` / `composer.lock` を**必ずコミットする**（未コミットのまま teardown すると失われる）。

---

## 施策 5: 運用契約の記述（docs / AGENTS.md）

### 変更箇所

- ファイル: `docs/auth-security-mechanisms.md`（§5 パスキー「運用上の注意」）
- ファイル: `AGENTS.md`（運用要件のブロック。`TRUSTED_PROXIES` (T108) の隣）

### 波及変更

- テストファイル: なし（docs は既存の doc 同期テストの対象外。
  `TrustedProxiesRunbookTest` に相当する pin は**新設しない** = 今必要なものだけ作る）

### 現行コード

```markdown
### 運用上の注意

- 設定は `APP_URL` から導出される (relying party id = ホスト、allowed origins = `[APP_URL]`)。
  同一オリジン PWA 前提のため専用 env は持たない。
- **`APP_KEY` をローテートすると user handle (`hash_hmac` の鍵が `APP_KEY`) が変わり、
  登録済みパスキーが全件無効になる**。鍵ローテートを行う場合は
  `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を `config/passkeys.php` に持たせる設計変更が必要。
- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
```

### 変更後コード

```markdown
### 運用上の注意

- 設定の正本は **`config/fortify.php` の `passkeys` ブロック**（身元の識別子 = relying party id /
  許可する接続元 = allowed origins / 利用者ハンドルの導出鍵）。
  身元の識別子と接続元は宣言が無ければ `APP_URL` から導出する
  (RP ID = host、接続元 = `scheme://host[:port]`)。同一オリジン PWA 前提のため通常は宣言不要。
- **production では `PASSKEYS_USER_HANDLE_SECRET` の宣言が必須**
  (未宣言 / 32 文字未満、および設定の書式・相互整合の違反は `App\Support\PasskeyConfigValidator`
  が `ProductionEnvGuard` 経由で起動時 fail-fast する = **初回デプロイ前に設定が要る破壊的変更**)。
  検査は `Features::passkeys()` が有効なときだけ走る。
- 導出鍵を宣言しないと利用者ハンドル (`hash_hmac` の鍵) が `APP_KEY` に倒れ、
  **`APP_KEY` をローテートした瞬間に登録済みパスキーが全件無効になる**。
  既にパスキーが登録されている環境では、`PASSKEYS_USER_HANDLE_SECRET` に
  **現行 `APP_KEY` の値をそのまま**宣言すれば既存パスキーは維持される
  (検査は「宣言されているか」を見ており、値が `APP_KEY` と同じかどうかは見ない)。
  以後 `APP_KEY` のローテートはパスキーに影響しない。
- 起動時検査が見るのは**書式と相互整合まで**である。「その host を実際に運用しているか」
  「証明書があるか」は検査できない。**Public Suffix List も持たない**ため、
  `co.uk` のような public suffix を身元の識別子に置いた設定は起動時には通る
  (ブラウザ側が PSL を見るので実際の手続きは失敗する)。
  したがってこの検査は **WebAuthn の完全な妥当性検査ではない** (誇張しない)。
  対象は **DNS 名のみ**で、IP リテラル・単一ラベル (`localhost`) は reject する。
- 宣言は `config/fortify.php` の `passkeys` ブロックに置く。
  **`config/passkeys.php` を新設してはいけない** — `FortifyServiceProvider::register()` の
  `configurePasskeys()` が `passkeys.*` を `fortify.passkeys.*` から**無条件に上書きする**ため、
  置いても効かない死んだ設定になる。実効値と宣言値の一致は
  `PasskeyPackageContractTest` が固定する。
- キー名は `laravel/fortify` / `laravel/passkeys` の契約であり、変わると宣言は
  **無言で効かなくなり既定へ戻る**。版 pin（`composer.json` の直接要求 +
  解決版検査）が対象にするのは **`laravel/passkeys` だけ**である
  （`laravel/fortify` は 1.x の semver 管理なので minor pin を足さない）。
  Fortify 側の写像は `PasskeyPackageContractTest` の**実効値の契約テスト**が守る。
- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
```

`AGENTS.md` の運用要件（`TRUSTED_PROXIES` の段落の直後）へ追記:

```markdown
> **運用要件 (パスキー)**: production は `PASSKEYS_USER_HANDLE_SECRET` の**明示宣言が必須**
> (未宣言 / 32 文字未満 / 身元の識別子・許可する接続元の書式不正・相互不整合は
> `PasskeyConfigValidator` が `ProductionEnvGuard` 経由で起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。宣言しないと利用者ハンドルが `APP_KEY` 由来になり、
> **`APP_KEY` ローテートで登録済みパスキーが全件無効**になる。既にパスキーがある環境は
> 現行 `APP_KEY` の値をそのまま宣言すれば維持できる。運用手順は
> `docs/auth-security-mechanisms.md` §5。
```

### PHPStan適合チェック

- [x] 対象外（ドキュメントのみ）

### テスト計画

- [ ] docs の記述に対応する**振る舞い**は施策 2 のテストが担保する
      （docs 専用の同期テストは新設しない = 今必要なものだけ作る）
- [ ] `.env.example` の提示は施策 3 のテストが固定する

### リスク

- AGENTS.md の運用要件が 1 つ増えるが、**デプロイ基盤が無い**ため守るのは人手のままである
  （AGENTS.md の既存注記と同じ扱い。存在しない基盤のための preflight 機構は作らない）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `composer.json` / `composer.lock` / `AGENTS.md` / `.env.example` という**他の設計と衝突しやすい共有ファイル**を触るため。特に `composer.lock` は行単位マージが実質不可能で、並行 worktree の同時変更は必ず手当てが要る。また 5 施策が「config → validator → env → 依存 pin → docs」と一本の依存線で連なっており、部分適用すると本番起動が落ちる状態（validator だけ入って config が無い等）を作れてしまう |
| 競合リスク | 同時進行の他 4 設計が `composer.lock` / `AGENTS.md` / `docs/` を触ると衝突する。`app/Support/ProductionEnvGuard.php` は他施策が触る可能性が低い。マージ順を後ろに回し、main へのマージ直前に rebase する |

## 使命・禁止事項チェック（最終確認）

- 使命への寄与: 撮影 PWA の主戦場はスマホであり、パスキーは現場作業者が最も摩擦なく使えるログイン手段。
  設定事故によるログイン不能を**デプロイ前**に止めることで、現場が使い続けられる状態を守る
- 禁止事項 1（テストなし実装）: 全 5 施策にテストを割り当て済み（施策 5 のみ docs で、
  対応する振る舞いは施策 2 のテストが担保）
- 禁止事項 2（PHPStan widen）: `config/` は解析対象。`env()` / `parse_url()` の `mixed` は
  `is_string` / `is_int` / `is_array` で絞る（`@phpstan-ignore` を使わない）
- 禁止事項 4（`response()->json()` 直書き）: 該当なし（HTTP 応答を触らない）
- 思考原則 1（フレームワークのレンジ内）: vendor の config キーと `PASSKEYS_USER_HANDLE_SECRET` という
  **パッケージが既に持つ env 名**をそのまま使い、独自の設定機構を作らない
- 思考原則 2（今必要なものだけ）: 事故が確認されている 3 値だけを扱い、
  `timeout` / `guard` / `middleware` 等は vendor 既定のまま。パスキー専用 runbook も新設しない
- 思考原則 3（後方互換の並走を残さない）: 期限付きの移行 flag を作らず、
  判定式そのものを「宣言の有無」に正すことで移行と恒久状態を同じ形にした
