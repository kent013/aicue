# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

# 禁止事項

1. テストなしの実装完了報告(Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

# セキュリティ不変条件

1. tenant キー不信 / 2. 子は親に属する(404 先行) / 3. cross-org 不可 / 4. untrusted 文字列は UserInput 型 / 5. laratrust_team_id 明示 / 6. PII は CipherSweet + whereBlind / 7. 課金冪等性 / 8. 外部 URL 取得は SSRF 検査経由

【思考原則】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest(RefreshDatabase グローバル + `--parallel`)
- DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null安全性)
2. 既存コードとの整合性(命名規約、パターン、API)
3. PHPStan level 10 適合性(型安全性、generics、Assert)
4. テスト計画の網羅性(各施策に Pest テスト、RefreshDatabase 準拠)
5. 副作用・後退リスク
6. 波及変更の網羅性
7. **セキュリティ(本施策の核)**: production で HIBP=uncompromised が必ず有効であること(fail-secure)。fake_externals が本番で HIBP を外す経路が無いこと。denylist 反転の妥当性。
8. テスト実装の落とし穴(`app()->instance('env', ...)` の後始末、`Http::preventStrayRequests()` の副作用過検出、reflection 依存の脆さ)

【本設計の背景】
- 現状 `PasswordPolicy::rule()` は `App::runningUnitTests() ? $rule : $rule->uncompromised()` で、ユニットテスト時のみ HIBP を外す。そのため local / bughunt.local / feature ブラウザテストで実 HIBP へ HTTPS が飛び 10〜14 秒の遅延 (bug-hunt F-4-01) を招いている。
- 概念設計は conceptual-review Round 2 で APPROVED 済み。allowlist ではなく **denylist**(HIBP を無効化する既知の開発/テスト env を列挙、未知 env は既定 ON)を採用して fail-secure 化した。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| 3 | 登録/リセット POST が外部 HIBP を呼ばない Feature 非退行テスト | `tests/Feature/Auth/RegistrationTest.php` | Medium |

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
 * HIBP 漏洩照合 (uncompromised) を無効化する既知の開発 / テスト環境の denylist (SSOT)。
 *
 * fail-secure 設計: 「HIBP を有効化する env の allowlist」ではなく「無効化する env の
 * denylist」を採る。これにより未知 env (staging / preprod / qa / review 等の実運用・
 * 準本番ミラー) は既定 ON へ倒れ、新設 env で HIBP が静かに外れる事故を防ぐ。
 * FakeExternalsServiceProvider が「fake は allowlist で倒す (未知 env では fake しない)」
 * とするのと対称の判断 (fake の安全側 = しない、HIBP 照合の安全側 = する)。
 *
 * 判定は APP_ENV (App::environment()) のみに依存し、APP_URL / host には依存しない
 * (bughunt.local は APP_ENV の値であり host 名ではない)。
 *
 * @var list<string>
 */
private const array PWNED_CHECK_DISABLED_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

/**
 * 現在の実行環境で HIBP 漏洩照合 (uncompromised) を付与すべきかを判定する単一述語。
 *
 * fail-secure 順序:
 * 1. production は不変条件として無条件で有効 (denylist / fake_externals に左右されない)。
 * 2. 外部 fake を有効化した検証環境 (fake_externals=true) は外部 fake 契約に従い無効。
 * 3. denylist の既知開発 / テスト env のみ無効。それ以外 (未知 env) は既定 ON。
 */
public static function shouldCheckPwned(): bool
{
    // 1. 本番は不変条件: 無条件で有効。production では ProductionEnvGuard が
    //    fake_externals=true を deploy 時 fail-fast で拒否するため、本判定後に HIBP が
    //    外れる経路は存在しない (多層防御)。
    if (App::environment('production')) {
        return true;
    }

    // 2. 外部 fake を有効化した検証環境 (bughunt / local) は外部 fake 契約に従い無効。
    //    fake_externals の allowlist は denylist と通常一致するため冗長だが、
    //    「fake_externals=true では HIBP を呼ばない」契約を述語レベルで first-class に固定する
    //    (fake allowlist が将来変わっても契約を保つ defense in depth)。
    if (config('testing.fake_externals') === true) {
        return false;
    }

    // 3. 既知の開発 / テスト env のみ無効。未知 env は既定 ON (fail-secure)。
    return ! App::environment(self::PWNED_CHECK_DISABLED_ENVIRONMENTS);
}

/**
 * 強度ルールを構築する。
 *
 * min12 + mixedCase + numbers は全環境共通。HIBP 漏洩照合 (uncompromised) は
 * shouldCheckPwned() が true の環境 (production / staging 等の実運用ミラー) でのみ付与する。
 * 既知の開発 / テスト env (local / testing / bughunt.local) と fake_externals=true では
 * 外部依存 / 遅延 / flaky 回避のため付与しない。
 */
public static function rule(): Password
{
    $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();

    return self::shouldCheckPwned() ? $rule->uncompromised() : $rule;
}
```

- `App::runningUnitTests()`(env=`testing`)は述語 3 の denylist に含まれ `false` → 既存挙動を包含。`Illuminate\Support\Facades\App` の import は既存のまま流用。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている(`shouldCheckPwned(): bool` / `rule(): Password`)。
- [x] null 安全: `config('testing.fake_externals') === true` は厳密比較で `mixed` を `true` に narrowing(非 true 値=null 含むは false 扱い)。
- [x] DTO 非関与(配列返却なし。`Password` fluent rule を返す)。
- [x] Generics: 定数に `@var list<string>` を付与し `App::environment(list<string>)` の型を満たす。
- [x] 型付き定数 `const array` は PHP 8.4 で有効(既存前例あり)。

### テスト計画
- [x] バグ修正の再現/固定: 施策2・3 参照。
- [x] 既存 `tests/Unit/Support/PasswordPolicyTest.php` の更新(削除・上書きせず拡充。既存4ケースは非退行)。
- [x] 個別 `DatabaseTransactions` 不使用(Unit テストは DB 非依存。Feature は RefreshDatabase グローバル)。

### リスク
- **本番で HIBP が外れる**: 述語 1(production 先行 return true)で構造的に排除。施策2 の fail-secure テストで固定。
- **未知 env で HIBP が静かに外れる**: denylist 反転で既定 ON。施策2 の未知 env ケースで固定。
- **フレームワーク結合**: 主テストは public 述語の振る舞い。`rule()` の reflection は配線確認の補助のみ(施策2)。

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

### 追加テスト（主: public 述語の振る舞い）
```php
test('shouldCheckPwned() は production で true (不変条件)', function (): void {
    app()->instance('env', 'production');
    try {
        expect(PasswordPolicy::shouldCheckPwned())->toBeTrue();
    } finally {
        app()->instance('env', 'testing');
    }
});

test('shouldCheckPwned() は staging 等の未知 env で既定 true (fail-secure denylist)', function (string $env): void {
    app()->instance('env', $env);
    try {
        expect(PasswordPolicy::shouldCheckPwned())->toBeTrue();
    } finally {
        app()->instance('env', 'testing');
    }
})->with(['staging', 'preprod', 'review']);

test('shouldCheckPwned() は既知の開発/テスト env で false', function (string $env): void {
    app()->instance('env', $env);
    try {
        expect(PasswordPolicy::shouldCheckPwned())->toBeFalse();
    } finally {
        app()->instance('env', 'testing');
    }
})->with(['local', 'testing', 'bughunt.local']);

test('shouldCheckPwned() は非本番で fake_externals=true なら false', function (): void {
    // staging (通常 true) でも fake_externals=true が優先されて false になる。
    app()->instance('env', 'staging');
    config(['testing.fake_externals' => true]);
    try {
        expect(PasswordPolicy::shouldCheckPwned())->toBeFalse();
    } finally {
        config(['testing.fake_externals' => false]);
        app()->instance('env', 'testing');
    }
});

test('shouldCheckPwned() は production では fake_externals=true でも true (fail-secure)', function (): void {
    // 本番の不変条件: fake_externals が混入しても HIBP は外れない (述語 1 が先行)。
    app()->instance('env', 'production');
    config(['testing.fake_externals' => true]);
    try {
        expect(PasswordPolicy::shouldCheckPwned())->toBeTrue();
    } finally {
        config(['testing.fake_externals' => false]);
        app()->instance('env', 'testing');
    }
});

test('rule() は述語 false のとき uncompromised を付与しない (配線・補助)', function (): void {
    // 既定 testing env。reflection は配線確認の補助。
    expect(readPasswordRuleProperty(PasswordPolicy::rule(), 'uncompromised'))->toBeFalse();
});
```

- 本番不変条件の二重固定: 上記に加え、既存 `tests/Feature/Support/ProductionEnvGuardTest.php`(`testing.fake_externals=true` を violation にする)が「production で fake_externals を有効化できない」経路を既に担保している旨を、本テストファイル冒頭コメントで参照する(新規テスト追加は不要 = 既存資産の非退行で足りる)。

### PHPStan適合チェック
- [x] `->with([...])` datasets の引数型は `string`(closure 引数に型注釈)。
- [x] reflection helper は既存(型は mixed 返却で既存踏襲)。

### テスト計画
- [x] `app()->instance('env', ...)` の後始末を `finally` で `'testing'` へ必ず復帰(並列実行のプロセス汚染防止)。
- [x] `config(['testing.fake_externals' => ...])` も `finally` で false へ復帰。
- [x] 実 HIBP 照会は発生しない(述語は環境判定のみ、reflection はプロパティ読みのみ)。

### リスク
- `app()->instance('env', ...)` を復帰し損ねると後続テストの環境が汚染される → 全ケース `finally` で復帰。既存テストが同パターンを使用済みで前例あり。

---

## 施策3: 登録 POST が外部 HIBP を呼ばない Feature 非退行テスト

### 変更箇所
- ファイル: `tests/Feature/Auth/RegistrationTest.php`(既存の登録テストに 1 ケース追加)。

### 波及変更
- なし(テスト追加のみ)。

### 追加テスト
```php
test('登録 POST は非本番で外部 HIBP を呼ばない (F-4-01 非退行)', function (): void {
    // testing env では shouldCheckPwned()=false のため uncompromised が付かず、
    // Password バリデーションが api.pwnedpasswords.com へ一切リクエストしないことを固定する。
    Http::preventStrayRequests();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'newuser@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => true,
    ]);

    // 登録自体の成否は既存テストが担保。ここでは外部 HIBP 呼び出し 0 回を主張する。
    Http::assertNothingSent();
})->group('auth');
```

- `Http::preventStrayRequests()` は fake 未登録の外向き HTTP を例外化する。testing env で uncompromised が外れていれば HIBP への stray request は発生しない = テスト green。もし将来 denylist から `testing` が漏れる回帰が入れば、この POST が pwnedpasswords.com を叩き `preventStrayRequests` が fail する(F-4-01 の構造的ガード)。
- 既存の登録正常系/異常系(password 短すぎ・terms 未同意等)は不変。本ケースは外部呼び出し 0 回の受け入れ基準を追加するのみ。

### PHPStan適合チェック
- [x] `Illuminate\Support\Facades\Http` を use。追加の型パラメータ不要。

### テスト計画
- [x] RefreshDatabase グローバル適用下で登録 → user 作成の副作用は既存テストと同一(email factory 生成は不要、直接 payload)。
- [x] 個別 `DatabaseTransactions` 不使用。
- [x] `Http::preventStrayRequests()` はテスト内スコープ(Pest の各テスト分離)。

### リスク
- 他の登録副作用(メール送信等)が外向き HTTP を出すと `assertNothingSent` が過検出する可能性 → メール送信は Laravel の transport(SES API は HTTP だが testing では `mail.default=array`/`log` のため送出されない)。既存 RegistrationTest が同 payload で green のため副作用の外部 HTTP は無い前提。万一検出時は `Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pwnedpasswords.com'))` へ限定する fallback を許容する。

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


---

## 関連する現行コード

### app/Support/PasswordPolicy.php（現行 rule() 抜粋）
```php
public static function rule(): Password
{
    $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();

    return App::runningUnitTests() ? $rule : $rule->uncompromised();
}
```

### app/Providers/AppServiceProvider.php（配線・抜粋）
```php
// パスワード強度ポリシーの SSOT は App\Support\PasswordPolicy
Password::defaults(static fn (): Password => PasswordPolicy::rule());
```

### app/Support/ProductionEnvGuard.php（fake_externals 拒否・抜粋）
```php
// production で fake_externals=true を deploy 時 fail-fast で拒否する既存不変条件
if (config('testing.fake_externals') === true) {
    $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
        .'(external fakes must never be enabled in production).';
}
```

### app/Providers/FakeExternalsServiceProvider.php（allowlist 対比・抜粋）
```php
// fake は allowlist で倒す = 未知 env では fake しない(安全側)
private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
```

### tests/Unit/Support/PasswordPolicyTest.php（既存テスト・維持対象の抜粋）
```php
test('rules() は非テスト環境で uncompromised (HIBP) を含む', function (): void {
    app()->instance('env', 'production');
    try {
        $rule = PasswordPolicy::rules()[0];
        expect(readPasswordRuleProperty($rule, 'uncompromised'))->toBeTrue();
    } finally {
        app()->instance('env', 'testing');
    }
});
```
