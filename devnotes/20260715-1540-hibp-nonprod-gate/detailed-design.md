# 詳細設計: HIBP(uncompromised)照合を非本番環境で無効化 (hibp-nonprod-gate)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告(不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件（本施策に直結）

- **production では必ず `uncompromised()`(HIBP) が有効**。config/env/`fake_externals` で無効化できてはならない(fail-secure)。
- `fake_externals` は `ProductionEnvGuard` により production で `true` になれない(deploy 時 fail-fast)。
- 上記を Unit/Architecture(既存 `ProductionEnvGuardTest`)テストで固定する。

### コーディングルール

- **PHP 8.4**(`composer.json` = `"php": "^8.4"`)。型付きクラス定数(`const array` / `const int`)は既存前例あり(`PasswordPolicy::MIN_LENGTH`、`FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS`)。
- **PHPStan level 10** 必須(`composer phpstan`)。
- **Pest** + **RefreshDatabase** グローバル適用 + `--parallel`(個別 `DatabaseTransactions` 禁止)。
- `declare(strict_types=1)` + 日本語コメント。アーリーリターン推奨。
- コードフォーマット: `composer fix`(Pint)。

## 概念設計リファレンス

- [devnotes/20260715-1540-hibp-nonprod-gate/conceptual-design.md](./conceptual-design.md)(概念設計 APPROVED / conceptual-review Round 2)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | HIBP 付与判定を denylist(fail-secure)へ反転し単一述語に集約 | `app/Support/PasswordPolicy.php` | Medium |
| 2 | 述語 env matrix + 本番 fail-secure 不変条件の Unit テスト | `tests/Unit/Support/PasswordPolicyTest.php` | Medium |
| 3 | 登録 POST が pwnedpasswords.com を呼ばない Feature 非退行テスト | `tests/Feature/Auth/RegistrationTest.php` | Medium |

---

## 施策1: HIBP 付与判定を denylist へ反転し単一述語 `shouldCheckPwned()` に集約

### 変更箇所
- ファイル: `app/Support/PasswordPolicy.php`(現行 L22-33 の `rule()` docblock + 実装、定数追加)

### 波及変更
- TypeScript 型定義: なし(バックエンドのみ。フォーム表示要件 `describe()` は不変)。
- API Resource/DTO: なし(`Password` fluent rule のみ。DTO 非関与)。
- テストファイル: `tests/Unit/Support/PasswordPolicyTest.php`(施策2)、`tests/Feature/Auth/RegistrationTest.php`(施策3)。
- 呼び出し側(`AppServiceProvider::boot()` の `Password::defaults()`、`CreateNewUser` / `ResetUserPassword` / `UpdateUserPassword` / `CreateAdminCommand`)は **`PasswordPolicy::rule()` / `Password::default()` 経由のため無変更**。本番挙動は不変(uncompromised 有効のまま)。

### 現行コード
```php
/**
 * 強度ルールを構築する。
 *
 * min12 + mixedCase + numbers は全環境共通。HIBP 漏洩照合 (uncompromised) は
 * テスト実行時のみ除外する (外部依存 / flaky 回避。local/staging/prod では有効)。
 */
public static function rule(): Password
{
    $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();

    return App::runningUnitTests() ? $rule : $rule->uncompromised();
}
```

### 変更後コード
```php
/**
 * HIBP 漏洩照合 (uncompromised) を無効化する APP_ENV 値の denylist (SSOT)。
 *
 * fail-secure: 有効化する env の allowlist ではなく無効化する env の denylist を採り、
 * 未知 env (staging/preprod/qa/review 等の実運用・準本番ミラー) を既定 ON に倒す。
 * 値は APP_ENV (App::environment()) と照合する env 名であり host 名ではない
 * (設計根拠の詳細は conceptual-design.md を参照)。
 *
 * @var list<string>
 */
private const array PWNED_CHECK_DISABLED_APP_ENVS = ['local', 'testing', 'bughunt.local'];

/**
 * 現在の実行環境で HIBP 漏洩照合 (uncompromised) を付与すべきかを判定する単一述語。
 *
 * - production は不変条件として無条件で有効 (denylist 編集ミスでも外れない fail-secure guard)。
 * - それ以外は APP_ENV が denylist (既知の開発/テスト env) に無い場合のみ有効 = 未知 env は既定 ON。
 * fake_externals は判定に用いない: fake が有効化され得る env (local/testing/bughunt.local) は
 * すべて denylist に含まれ推移的に無効になるため、責務は APP_ENV のみに閉じる。
 */
public static function shouldCheckPwned(): bool
{
    // 本番は不変条件: 無条件で有効。万一 denylist に production を誤って加えても外れない guard。
    if (App::environment('production')) {
        return true;
    }

    // 既知の開発 / テスト env のみ無効。未知 env は既定 ON (fail-secure)。
    return ! App::environment(self::PWNED_CHECK_DISABLED_APP_ENVS);
}

/**
 * 強度ルールを構築する。min12 + mixedCase + numbers は全環境共通。
 * HIBP 漏洩照合は shouldCheckPwned() が true の環境でのみ付与する (判定根拠は同メソッド参照)。
 */
public static function rule(): Password
{
    $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();

    return self::shouldCheckPwned() ? $rule->uncompromised() : $rule;
}
```

- `App::runningUnitTests()`(env=`testing`)は denylist に含まれ `false` → 既存挙動を包含。`Illuminate\Support\Facades\App` の import は既存のまま流用(`config()` 依存が消えたため追加 import は不要)。
- **fake_externals 分岐を削除した理由**: fake_externals の allowlist(`FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS = ['local','testing','bughunt.local']`)は本 denylist と同一。fake が実際に install され得る env はすべて denylist に含まれ HIBP は既に無効になる。逆に denylist 非該当 env(staging 等)は allowlist 外で fake が install されないため、そこで fake_externals フラグを見て HIBP を外すのは「fake が動いていないのに HIBP を落とす」fail-open になる。責務を APP_ENV に閉じることで brief 要件「fake_externals=true では付与しない」を推移的に満たしつつ fail-secure を保つ。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている(`shouldCheckPwned(): bool` / `rule(): Password`)。
- [x] null 安全: `config()` 依存が消え、判定は `App::environment()` の bool のみ(narrowing 不要)。
- [x] DTO 非関与(配列返却なし。`Password` fluent rule を返す)。
- [x] Generics: 定数に `@var list<string>` を付与し `App::environment(list<string>)` の可変長 string 引数を満たす。
- [x] 型付き定数 `const array` は PHP 8.4 で有効(既存前例あり)。

### テスト計画
- [x] バグ修正の再現/固定: 施策2・3 参照。
- [x] 既存 `tests/Unit/Support/PasswordPolicyTest.php` の更新(削除・上書きせず拡充。既存4ケースは非退行)。
- [x] 個別 `DatabaseTransactions` 不使用(Unit テストは DB 非依存。Feature は RefreshDatabase グローバル)。

### リスク
- **本番で HIBP が外れる**: 述語 1(production 先行 return true)で構造的に排除。施策2 の fail-secure テストで固定。
- **未知 env で HIBP が静かに外れる**: denylist 反転で既定 ON。施策2 の未知 env ケースで固定。
- **フレームワーク結合**: 主テストは public 述語の振る舞い。`rule()` の reflection は配線確認の最小 1 本のみ(施策2)。

---

## 施策2: 述語 env matrix + 本番 fail-secure 不変条件の Unit テスト

### 変更箇所
- ファイル: `tests/Unit/Support/PasswordPolicyTest.php`(既存の reflection helper・4 テストは維持し追加)。

### 波及変更
- TypeScript 型定義 / API Resource / DTO: なし。

### 現行テスト（維持する既存資産）
- `readPasswordRuleProperty()` helper(reflection で `uncompromised` 等を外部照会なしに読む)。
- `describe()` 決定論 / `rules()` 強度 / 「テスト環境で uncompromised 非含」/「非テスト(production)で uncompromised 含」/`Password::defaults()` 配線。
- 既存「非テスト環境で uncompromised を含む」は `app()->instance('env', 'production')` で production を模擬しており新設計でも成立(production→true)。**削除・上書きしない**。

### env 切替ヘルパー（復元漏れ防止の共通化）
`app()->instance('env', ...)` の復元漏れを防ぐため、テストファイル内に `finally` で必ず**差し替え前の env** へ戻すファイル固有名ヘルパーを置き、全 env matrix テストをこれ経由に統一する:

```php
/**
 * APP_ENV を一時的に差し替えて assertion を実行し、差し替え前の env へ必ず復元する。
 * 並列実行のプロセス env 汚染を防ぐ。名前衝突回避のためファイル固有名にする。
 */
function withPasswordPolicyAppEnv(string $env, Closure $assertion): void
{
    $original = app()->environment(); // ハードコードせず元の env を保存
    app()->instance('env', $env);
    try {
        $assertion();
    } finally {
        app()->instance('env', $original);
    }
}
```

### 追加テスト（主: public 述語の振る舞い）
```php
test('shouldCheckPwned() は production で true (fail-secure 不変条件)', function (): void {
    withPasswordPolicyAppEnv('production', fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeTrue());
});

test('shouldCheckPwned() は未知 env (staging 等の実運用ミラー) で既定 true (fail-secure denylist)', function (string $env): void {
    withPasswordPolicyAppEnv($env, fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeTrue());
})->with(['staging', 'preprod', 'review']);

test('shouldCheckPwned() は既知の開発/テスト env で false', function (string $env): void {
    withPasswordPolicyAppEnv($env, fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeFalse());
})->with(['local', 'testing', 'bughunt.local']);

test('shouldCheckPwned() は fake_externals=true の denylist env で false (brief 要件を推移的に固定)', function (): void {
    // fake が install され得る env (local) は denylist に含まれ、fake_externals に関係なく false。
    $original = config('testing.fake_externals');
    config(['testing.fake_externals' => true]);
    try {
        withPasswordPolicyAppEnv('local', fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeFalse());
    } finally {
        config(['testing.fake_externals' => $original]);
    }
});

test('shouldCheckPwned() は PasswordPolicy が fake_externals に結合しないことを固定 (fail-secure decoupling)', function (): void {
    // denylist 非該当 env (staging) に stray fake_externals=true を設定しても HIBP は ON のまま。
    // (staging は fake allowlist 外で fake が install されないため、ここで OFF にするのは fail-open)
    $original = config('testing.fake_externals');
    config(['testing.fake_externals' => true]);
    try {
        withPasswordPolicyAppEnv('staging', fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeTrue());
    } finally {
        config(['testing.fake_externals' => $original]);
    }
});

test('rule() は述語結果を uncompromised 付与へ配線している (reflection 最小 1 本・補助)', function (): void {
    // 既定 testing env では述語 false → uncompromised 非付与。production では付与。
    expect(readPasswordRuleProperty(PasswordPolicy::rule(), 'uncompromised'))->toBeFalse();
    withPasswordPolicyAppEnv('production', fn () => expect(
        readPasswordRuleProperty(PasswordPolicy::rule(), 'uncompromised')
    )->toBeTrue());
});
```

- **本番不変条件の二重固定**: 上記 production テストに加え、既存 `tests/Feature/Support/ProductionEnvGuardTest.php`(`testing.fake_externals=true` を violation にする)が「production で fake_externals を有効化できない」経路を既に担保している旨を、本テストファイル冒頭コメントで参照する(新規テスト追加は不要 = 既存資産の非退行で足りる)。
- **reflection は最小 1 本**に限定(`rule()` の配線確認のみ)。env matrix の主判定はすべて public 述語 `shouldCheckPwned()` の振る舞いで固定し、Laravel 内部実装への結合を避ける。
- 既存の「非テスト(production)で uncompromised を含む」テストは新設計でも成立し維持(削除・上書きしない)。

### PHPStan適合チェック
- [x] `withPasswordPolicyAppEnv(string $env, Closure $assertion): void` に型注釈。`Closure` を use。
- [x] `->with([...])` datasets の引数型は `string`(closure 引数に型注釈)。
- [x] reflection helper は既存(型は mixed 返却で既存踏襲)。

### テスト計画
- [x] `app()->instance('env', ...)` の後始末は `withPasswordPolicyAppEnv()` の `finally` で一元化し、差し替え前の env へ復元(復元漏れ防止)。
- [x] `config(['testing.fake_externals' => ...])` を使うケースも `finally` で元の値へ復元。
- [x] 実 HIBP 照会は発生しない(述語は環境判定のみ、reflection はプロパティ読みのみ)。

### リスク
- env 復元漏れによるプロセス汚染 → `withPasswordPolicyAppEnv()` ヘルパーで構造的に排除。

---

## 施策3: 登録 POST が外部 HIBP を呼ばない Feature 非退行テスト

### 変更箇所
- ファイル: `tests/Feature/Auth/RegistrationTest.php`(既存の登録テストに 1 ケース追加)。

### 保証範囲
- 本 Feature テストは**登録 POST(`/register`)**の HIBP 不送出を固定する。リセット(`ResetUserPassword`)/変更(`UpdateUserPassword`)/管理者作成(`CreateAdminCommand`)は同一 SSOT(`PasswordPolicy::rule()` 経由)であり、述語レベルの保証は施策2 の Unit テスト(全 env matrix)が横断的に固定する。よって Feature 冗長化は行わず、代表経路(登録)1 本 + 述語 Unit で足りると判断する。将来リセット/変更経路に固有の HTTP 副作用懸念が生じた場合のみ Feature を追加する(スコープ外)。

### 波及変更
- なし(テスト追加のみ)。

### 追加テスト
```php
test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
    // HIBP エンドポイントのみ intercept して実ネットワークを遮断する
    // (preventStrayRequests は合法な他 HTTP まで例外化するため使わない = 過検出回避)。
    // uncompromised は NotPwnedVerifier (Http client factory 経由) のため Http::fake で捕捉できる。
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'newuser@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1', // 既存 RegistrationTest と同じ表現 (Fortify 契約)
    ]);

    // シナリオ成立を固定 (別要因の早期失敗で「未送信」だけ通るのを防ぐ)。
    // 既存「登録できる」テストと同じく verification.notice へ誘導される。
    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('verification.notice'));

    // 主アサーション: HIBP エンドポイントへの送出 0 回に限定 (合法な他 HTTP の偽陽性を避ける)。
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.pwnedpasswords.com'));
})->group('auth');
```

- `Http::fake(['api.pwnedpasswords.com/*' => ...])` で HIBP URL のみ intercept し実ネットワークを遮断。他の合法な外部 HTTP は本テストの責務外(fake しない)とし、`preventStrayRequests()` の過検出を避ける。
- 主アサーションは `Http::assertNotSent(... api.pwnedpasswords.com ...)` に限定。もし将来 denylist から `testing` が漏れる回帰が入れば、この POST が HIBP を叩き(fake が記録)主アサーションが fail する(F-4-01 の構造的ガード)。
- `assertRedirect(route('verification.notice'))` + `assertSessionHasNoErrors()` で登録成功シナリオの成立を固定。
- 既存の登録正常系/異常系(password 短すぎ・terms 未同意等)は不変。本ケースは HIBP 不送出の受け入れ基準を追加するのみ。

### PHPStan適合チェック
- [x] `Illuminate\Support\Facades\Http` / `Illuminate\Http\Client\Request` を use。closure に `: bool` 戻り型 + `Request $request` 型注釈。

### テスト計画
- [x] RefreshDatabase グローバル適用下で登録 → user 作成の副作用は既存テストと同一(直接 payload)。
- [x] 個別 `DatabaseTransactions` 不使用。
- [x] `Http::fake` / `assertNotSent` はテスト内スコープ(Pest の各テスト分離)。

### リスク
- 登録成功リダイレクト先の変化 → 既存「登録できる」テストと同一の `verification.notice` を期待し、齟齬が出れば両テストが同時に検出する(整合)。HIBP 不送出の主張が本テストの核。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更は `PasswordPolicy` 単体 + そのテスト 2 ファイルに閉じ、他施策と共有する可変点が無い。呼び出し側は API 不変(`rule()` シグネチャ維持)のため干渉しない。 |
| 競合リスク | 低。`AppServiceProvider::boot()` / Fortify Actions / `CreateAdminCommand` は無変更。並走する他設計と衝突する共有ファイルは無い。 |

## 最終確認（使命・禁止事項チェック）

- **使命寄与**: 非本番の外部依存・遅延を除去し、開発/ブラウザテスト/bug-hunt の速度・決定性を回復。SOP→シナリオ→撮影の改善サイクルを阻害しない開発基盤の是正。本番 UX は不変。
- **禁止事項非抵触**: テスト必須(施策2・3)充足。PHPStan widen なし(厳密比較 + 型付き戻り値/定数)。`response()->json()` 非関与。DB 破壊操作なし。UI 変更なし。
- **セキュリティ不変条件**: production は述語 1 で無条件 HIBP 有効(fail-secure)。`ProductionEnvGuard` が本番 `fake_externals=true` を拒否(既存)。両者をテストで固定。
- **コーディングルール反映**: PHP 8.4 typed const、PHPStan L10、Pest + RefreshDatabase + `--parallel`、既存テスト非削除。
