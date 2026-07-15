# 詳細設計レビュー Round 3

Round 2 の残件(施策2 のテスト隔離復元、施策3 の `preventStrayRequests()` 撤去)を反映しました。施策1 は APPROVE 済みのため据え置きです。

## 対応サマリー

### [Warning] 施策2: `withAppEnv()` が元の値でなく 'testing' 固定復元 → 対応
- ヘルパーを差し替え前 env を保存して復元する形に変更し、名前衝突回避のため `withPasswordPolicyAppEnv()` へ改名:

```php
function withPasswordPolicyAppEnv(string $env, Closure $assertion): void
{
    $original = app()->environment();
    app()->instance('env', $env);
    try {
        $assertion();
    } finally {
        app()->instance('env', $original);
    }
}
```

### [Warning] 施策2: `fake_externals` も元 config 値へ復元 → 対応
- `$original = config('testing.fake_externals')` を保存し `finally` で `config(['testing.fake_externals' => $original])` に復元(ハードコード false を廃止)。

### [Critical] 施策3: `preventStrayRequests()` 残置で過検出未解消 → 対応
- `preventStrayRequests()` を撤去し、HIBP URL のみ fake:

```php
Http::fake([
    'api.pwnedpasswords.com/*' => Http::response('', 200),
]);

$response = $this->post('/register', [
    'name' => 'Test User',
    'email' => 'newuser@example.com',
    'password' => 'SecurePass1234',
    'terms_accepted' => '1',
]);

$response->assertSessionHasNoErrors();
$response->assertRedirect(route('verification.notice'));

Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.pwnedpasswords.com'));
```

## 確認したい点
1. 施策2・3 の残 Warning/Critical が解消しているか。
2. 全体 APPROVED に到達できるか。残件があれば具体的に。

---

## 更新後の詳細設計書(該当施策の最終形)

### 施策2: env 切替ヘルパー + 追加テスト(最終形)

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
- [x] `withAppEnv(string $env, Closure $assertion): void` に型注釈。`Closure` を use。
- [x] `->with([...])` datasets の引数型は `string`(closure 引数に型注釈)。
- [x] reflection helper は既存(型は mixed 返却で既存踏襲)。

### テスト計画
- [x] `app()->instance('env', ...)` の後始末は `withAppEnv()` の `finally` で一元化(復元漏れ防止)。
- [x] `config(['testing.fake_externals' => ...])` を使うケースも `finally` で false へ復帰。
- [x] 実 HIBP 照会は発生しない(述語は環境判定のみ、reflection はプロパティ読みのみ)。

### リスク
- env 復元漏れによるプロセス汚染 → `withAppEnv()` ヘルパーで構造的に排除。

---

### 施策3: 追加テスト(最終形)

## 施策3: 登録 POST が外部 HIBP を呼ばない Feature 非退行テスト

### 変更箇所
- ファイル: `tests/Feature/Auth/RegistrationTest.php`(既存の登録テストに 1 ケース追加)。

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
