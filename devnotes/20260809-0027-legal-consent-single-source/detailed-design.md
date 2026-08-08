# 詳細設計: legal-consent-single-source (同意バージョン解決点の単一化と gate)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。解析対象は `app` / `config` / `database` / `routes`（`tests` は対象外）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` で Feature/Unit にグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（本設計は HTTP 応答を持たないため該当なし）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント
- **namespace 宣言の無いファイル（Pest テスト）で非複合 `use` を書かない**
  （`NoNonCompoundGlobalUseTest` が検出。`RecursiveIteratorIterator` / `InvalidArgumentException`
  等の global クラスは `use` を書かず素で参照する）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 1 で **APPROVED**）
- [conceptual-review-round-1.md](./conceptual-review-round-1.md)
- [codex-history/conceptual-review-decisions-round-1.md](./codex-history/conceptual-review-decisions-round-1.md)
- 一次入力: [recon-brief.md](./recon-brief.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 解決点クラス `LegalConsent` の新設 | `app/Support/Legal/LegalConsent.php`（新規） | 高 |
| 2 | 呼び出し側 3 本を正準形へ | `app/Actions/Fortify/CreateNewUser.php` / `app/Services/Auth/SocialAccountService.php` / `app/Actions/Inquiry/CreateInquiryAction.php` | 高 |
| 3 | fixture の 4 つ目の出所を潰す | `database/factories/InquiryFactory.php` | 中 |
| 4 | Architecture gate の新設 | `tests/Architecture/LegalConsentVersionSingleSourceTest.php`（新規） | 高 |
| 5 | Unit テストの新設 | `tests/Unit/Support/Legal/LegalConsentTest.php`（新規） | 高 |

施策 1〜3 は **同一コミットで完結させる**（AGENTS.md 思考原則 3: 後方互換の並走を残さない）。
施策 4 は 1〜3 が終わった状態でのみ green になる（先に書けば fail する = テストファースト）。

---

## 施策 1: 解決点クラス `LegalConsent` の新設

### 変更箇所

- ファイル: `app/Support/Legal/LegalConsent.php`（**新規**。`app/Support/Legal/` ディレクトリも新規）

### 波及変更

- TypeScript 型定義: **なし**（フロントに露出しない）
- API Resource/DTO: **なし**（HTTP 応答を持たない。DTO 化する値ではなく設定スカラ 1 個）
- テストファイル: `tests/Unit/Support/Legal/LegalConsentTest.php`（施策 5）/
  `tests/Architecture/LegalConsentVersionSingleSourceTest.php`（施策 4）
- ドキュメント: **なし**（新モデルではないため `docs/architecture.md` / `docs/factories.md` の追記は不要）

### 現行コード

不在（`app/Support/` 配下に `Legal` ディレクトリは無く、`LegalConsent` の文字列は
`app/` `tests/` `resources/` `config/` `database/` 全体で 0 件 = 実読確認済み）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Legal;

use Webmozart\Assert\Assert;

/**
 * 利用規約・プライバシーポリシーの同意バージョンの **単一解決点 (SSOT)**。
 *
 * 同意バージョンは users.consent_version / inquiries.consent_version へ
 * 「どの版に同意したか」の証跡として forceFill で記録される。記録側が config を
 * 個別に直読すると形が分岐し (実際に 3 形へ分岐していた)、空版の fail-fast が
 * 一部経路にしか掛からない状態が生まれる。**版を決める場所をここ 1 箇所に閉じる**。
 *
 * - 状態も DB 参照も持たない (設定アクセサ + fail-fast のみ)。
 * - 空版 ('') は設定漏れであり、空版で証跡を書くと「どの版に同意したか」が事後に
 *   決定不能になる。よって**書き込み時点で fail-fast** する (CreateInquiryAction が
 *   単独で持っていた不変条件を全経路へ昇格させたもの)。
 * - 呼び出し元は tests/Architecture/LegalConsentVersionSingleSourceTest.php が
 *   exact-fit の inventory で固定する (新しい同意書き込み経路は登録が必須)。
 *
 * 対象外: 課金の自動購入同意 (config('billing.auto_recharge.consent_version')) は
 * 名前が似ているだけの別概念であり、本クラスは一切関与しない。
 */
final class LegalConsent
{
    /**
     * 現在の同意バージョン。
     *
     * @return non-empty-string
     *
     * @throws \InvalidArgumentException 未設定 / 非文字列 / 空文字のとき
     */
    public static function version(): string
    {
        $version = config()->string('legal.consent_version');
        Assert::stringNotEmpty($version, 'legal.consent_version must be configured');

        return $version;
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`string` + `@return non-empty-string`）
- [x] null 安全（`Webmozart\Assert\Assert` 使用。非文字列は `config()->string()` が先に落とす）
- [x] DTO を返している（該当なし。設定スカラ 1 個であり DTO 化しない = 思考原則 2）
- [x] Generics の型パラメータが正しい（該当なし）

**実測による確定（Codex Warning 対応）**: 本リポジトリの larastan 3.10 + webmozart/assert 2.4 で
`Assert::stringNotEmpty()` は `non-empty-string` に narrowing する。probe ファイルを一時作成して
`bash scripts/phpstan.sh analyse --memory-limit=2G --level=10 <probe>` を実行し、以下を実測した:

| probe | 結果 |
|---|---|
| 上記の実装そのまま | `[OK] No errors` |
| `Assert::stringNotEmpty()` の 1 行を削除 | `Method ...::version() should return non-empty-string but returns string. (return.type)` で **error** |

つまり `@return non-empty-string` は飾りではなく、**fail-fast の存在を PHPStan が機械的に守る
第 2 の gate**である（Assert を消すと型検査が落ちる）。`@phpstan-return` への切り替えは不要。
probe ファイルは検証後に削除済み（アプリコードは 1 行も変更していない）。

### 設計判断（なぜこの形か）

| 論点 | 決定 | 根拠 |
|---|---|---|
| static か DI か | **static** | 状態を持たない設定アクセサ。既存の `App\Support\EmailNormalizer` / `PasswordPolicy` / `Environment` と同じ流儀。interface + container 登録は今必要ない（思考原則 2） |
| `final` か | **final** | 継承で版の解決を差し替える用途が無い |
| メソッド数 | **`version()` 1 本だけ** | `isPlaceholder()` / `label()` 等は現時点で呼び手がいない（思考原則 2） |
| 例外の型 | `\InvalidArgumentException` | `config()->string()`（Laravel）と `Assert::stringNotEmpty()`（webmozart）が**どちらも同じ型**を投げるため、呼び手から見た失敗が 1 種類に揃う |
| fail-fast の位置 | **版を書き込もうとした時点**（起動時ではない） | 起動時検査は `ProductionEnvGuard` の担当でスコープ外（下記 §スコープ外 (b)） |

### リスク

- **空文字 env での挙動変更**: `LEGAL_CONSENT_VERSION=`（空文字）が設定された環境では、
  現在は `users.consent_version = ''` の証跡が静かに作られるが、変更後は登録 / SSO 登録が
  `InvalidArgumentException` で 500 になる。**これは意図した強化**（空版の証跡を書くくらいなら
  止める）であり、問い合わせ経路（`CreateInquiryAction`）では既にこの挙動である
  = **登録経路 2 本を問い合わせ経路へ合わせる統一**であって、弱める方向ではない。
  `config/legal.php:22` の既定が `'draft-1'`、`.env.example:168` と `.env.testing:77` の双方に
  `draft-1` が入っているため通常運用でこの分岐には入らない。
  **この挙動変更は実装 PR の説明へ必ず転記すること**（Codex Suggestion 対応）。
- それ以外の後退リスクは無い（新規クラスの追加のみ。既存経路の値は不変）。

---

## 施策 2: 呼び出し側 3 本を正準形へ

### 変更箇所

- `app/Actions/Fortify/CreateNewUser.php`（L94 の式 + `use` 1 行）
- `app/Services/Auth/SocialAccountService.php`（L75 の式 + `use` 1 行）
- `app/Actions/Inquiry/CreateInquiryAction.php`（L32-33 を 1 行へ。**L52 は変更しない**）

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**（`CreateInquiryData` の shape は不変）
- テストファイル: **変更なし**。`tests/Feature/Auth/RegistrationTest.php:25` と
  `tests/Feature/Inquiry/ContactSubmissionTest.php:51` は期待値を
  `config()->string('legal.consent_version')` で作っており、**意図的に揃えない**（理由は下記）
- ルート / migration / Factory（施策 3 を除く）: **なし**

### 現行コード

`app/Actions/Fortify/CreateNewUser.php` L90-96:

```php
                $user = (new User([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]))->forceFill([
                    'terms_accepted_at' => now(),
                    'consent_version' => config()->string('legal.consent_version'),
                ]);
```

`app/Services/Auth/SocialAccountService.php` L70-78:

```php
            $user = (new User([
                'name' => $socialiteUser->getName() ?? $email,
                'email' => $email,
            ]))->forceFill([
                'terms_accepted_at' => now(),
                'consent_version' => config()->string('legal.consent_version'),
                'email_verified_at' => $verifiedAt,
            ]);
```

`app/Actions/Inquiry/CreateInquiryAction.php` L30-36 / L49-54:

```php
        // 同意の証跡: 受付時刻 + 同意時点のポリシー版。空版は設定漏れなので fail-fast。
        $consentVersion = config('legal.consent_version');
        Assert::stringNotEmpty($consentVersion, 'legal.consent_version must be configured');

        // 運営宛通知の宛先を save 前に解決して fail-fast (設定破損時に孤児行を作らない)。
        $recipient = config('legal.inquiry_recipient');
        Assert::stringNotEmpty($recipient, 'legal.inquiry_recipient (or MAIL_FROM_ADDRESS) must be configured');
...
        $inquiry->forceFill([
            'terms_accepted_at' => now(),
            'consent_version' => $consentVersion,
        ]);
```

### 変更後コード

`app/Actions/Fortify/CreateNewUser.php`:

```php
// use 節へ 1 行追加 (アルファベット順: App\Rules\... の後、App\Services\... の前)
use App\Support\Legal\LegalConsent;
```

```php
                ]))->forceFill([
                    'terms_accepted_at' => now(),
                    'consent_version' => LegalConsent::version(),
                ]);
```

`app/Services/Auth/SocialAccountService.php`:

```php
// use 節へ 1 行追加 (App\Services\Security\SecurityEventRecorder の後)
use App\Support\Legal\LegalConsent;
```

```php
            ]))->forceFill([
                'terms_accepted_at' => now(),
                'consent_version' => LegalConsent::version(),
                'email_verified_at' => $verifiedAt,
            ]);
```

`app/Actions/Inquiry/CreateInquiryAction.php`:

```php
// use 節へ 1 行追加 (App\Models\Inquiry の後)
use App\Support\Legal\LegalConsent;
```

```php
        // 同意の証跡: 受付時刻 + 同意時点のポリシー版。空版の fail-fast は LegalConsent が持つ。
        $consentVersion = LegalConsent::version();
```

- `Assert::stringNotEmpty($consentVersion, ...)` の行は**削除する**（fail-fast は
  `LegalConsent::version()` の内側へ移動。**弱めていない**）。
- `use Webmozart\Assert\Assert;` は**残す**（直後の `$recipient` の Assert で使い続ける）。
- L52 の `'consent_version' => $consentVersion,` は**変更しない**（ローカル変数名を維持）。

### 並行タスクとの干渉（重要）

`app/Actions/Inquiry/CreateInquiryAction.php` は並行タスク（queue-dispatch-atomicity）が
**読むだけ**の予定。本施策が触るのは:

| 領域 | 行 | 本設計 | 並行タスク |
|---|---|---|---|
| `use` 節 | L10-16 | 1 行追加 | 触らない見込み |
| 同意版の解決 | L31-33 → 2 行 | 変更 | 触らない |
| `forceFill` | L49-53 | **不変** | 触らない |
| `dispatchNotification` 呼び出し / 定義 | L58-85 | **不変** | 関心領域 |

**行が離れており衝突しない見込み**。ただし `use` 節は両者が触りうる唯一の共有面なので、
競合したら `use` 節のみを手で解決する（自明なマージ）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（既存メソッドのシグネチャは不変）
- [x] null 安全（`LegalConsent::version()` が `non-empty-string` を返す。呼び出し側の
      Assert 削除で型が緩まない = `config('legal.consent_version')` の `mixed` を
      `non-empty-string` へ置き換えるので**むしろ強くなる**）
- [x] DTO を返している（該当なし）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画

施策 4/5 に集約（下記）。**本施策は新規テストを追加しない**。理由は「既存テストが
1 行の変更もなく green であること」が振る舞い不変の直接証拠だから（§検証手順を参照）。

### リスク

- `Assert` の import が未使用になって Pint / PHPStan が警告する可能性 → **ならない**
  （`$recipient` の Assert が残るため）。実装時に `composer fix` で確認する。
- `CreateInquiryAction` の fail-fast タイミングは**変わらない**
  （どちらも `$inquiry->save()` より前。孤児行を作らない性質を維持）。

---

## 施策 3: fixture の 4 つ目の出所を潰す

### 変更箇所

- `database/factories/InquiryFactory.php` L30

### 波及変更

- TypeScript 型定義 / API Resource/DTO: **なし**
- テストファイル: **変更なし**。`Inquiry::factory()` を使う既存 4 ファイル
  （`tests/Feature/Inquiry/InquiryModelTest.php` / `tests/Feature/Inquiry/PurgeInquiriesCommandTest.php` /
  `tests/Feature/Filament/InquiryResourceTest.php` / `tests/Feature/Inquiry/ContactSubmissionTest.php`）は
  いずれも `consent_version` の値を assert していない（実読確認済み）

### 現行コード

```php
            'terms_accepted_at' => now(),
            'consent_version' => 'draft-1',
```

### 変更後コード

```php
// use 節へ 1 行追加 (App\Models\Inquiry の後)
use App\Support\Legal\LegalConsent;
```

```php
            'terms_accepted_at' => now(),
            // 版の出所は LegalConsent に一本化する (literal を持つと config と独立した
            // 4 つ目の出所になり、config を変えても fixture が追随しない)。
            'consent_version' => LegalConsent::version(),
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`definition(): array` は不変）
- [x] null 安全（`non-empty-string` が入る）
- [x] `database/` は PHPStan の解析対象パスに含まれる（`phpstan.neon` の `paths`）ので
      level 10 の検査を受ける

### テスト計画（Codex Warning 対応）

Factory が Laravel の config 解決に依存するようになるため、**落ち方が変わらないこと**を
既存テストで実証する。以下が **1 行も変更せず green** であることを合格条件とする:

- `tests/Feature/Inquiry/InquiryModelTest.php`
- `tests/Feature/Inquiry/PurgeInquiriesCommandTest.php`
- `tests/Feature/Filament/InquiryResourceTest.php`
- `tests/Feature/Inquiry/ContactSubmissionTest.php`

**値が同一である根拠**: `.env.testing:77` に `LEGAL_CONSENT_VERSION=draft-1` があり、
`config/legal.php:22` の既定も `'draft-1'`。よってテストレーンで
`'draft-1'` と `LegalConsent::version()` は**同値**（実読確認済み）。
新規テストは追加しない（思考原則 2）。

### リスク

- Architecture lane は Factory を使わない（`tests/Pest.php:98` で `TestCase` のみ・DB 非使用）
  ため影響なし。
- `database/seeders/` からの Factory 利用は config 解決済みの artisan 実行文脈であり影響なし。

---

## 施策 4: Architecture gate の新設

### 変更箇所

- ファイル: `tests/Architecture/LegalConsentVersionSingleSourceTest.php`（**新規**）

### 波及変更

- TypeScript 型定義 / API Resource/DTO: **なし**
- 既存テストの変更: **なし**（新規ファイルの追加のみ。既存 Architecture テストの
  母集団や allowlist には触らない）

### 検出設計（走査規則をどう限定するか = 本設計の中核）

**素の識別子 `'consent_version'` では走査しない。** 実在する別 feature ——
課金の自動購入同意 `config('billing.auto_recharge.consent_version')`
（`config/billing.php:92` / `app/Http/Requests/Billing/BillingCheckoutRequest.php:74` /
`app/Http/Requests/Onboarding/ActivatePersonalRequest.php:48` /
`ticket_auto_recharges.consent_version`）—— を巻き込んで false positive になるためである
（AGENTS.md 思考原則 4: 別物の概念を「似ているから」で統合しない）。

検出のセレクタは以下の **3 語彙のみ**に限定する:

1. 文字列リテラル `legal.consent_version`（設定キー）
2. 文字列リテラル `LEGAL_CONSENT_VERSION`（env 名）
3. `LegalConsent::version` の静的呼び出し（完全修飾形を含む）
4. 文字列リテラル `draft-1`（プレースホルダ版。fixture の再発明を潰す）

走査は `token_get_all()` ベース（`CarbonOverflowArithmeticGateTest` /
`ScenarioWritePathInventoryTest` と同じ流儀）。regex ではなく token を使うのは、
**コメント・docblock・heredoc 内の記述を誤検出しない**ため。

引用符の正規化（Codex Warning 対応）: `T_CONSTANT_ENCAPSED_STRING` は引用符を含む生表記
（`'legal.consent_version'` / `"legal.consent_version"`）で返るため、
`trim($literal, "'\"")` で正規化してから比較する
（既存の `tests/Architecture/ScenarioWritePathInventoryTest.php:451` と同じ流儀）。
これが効いていることは負のコントロール T-A2 が behavioral に固定する。

| 検出 | 内容 | 母集団 | 判定 |
|---|---|---|---|
| G1 | 設定キー `legal.consent_version` の出現 | `app/**/*.php` | `app/Support/Legal/LegalConsent.php` **のみ**許可 |
| G2 | env 名 `LEGAL_CONSENT_VERSION` の出現 | `app/**/*.php` + `config/**/*.php` | `config/legal.php` **のみ**許可（かつ**そこに 1 件存在する**ことを正の側でも固定） |
| G3 | `LegalConsent::version()` の呼び出し元 | `app/**/*.php` | inventory と **exact-fit**（3 本） |
| G4 | プレースホルダ literal `draft-1` の出現 | `app/` + `database/` + `tests/` の `*.php` | **0 件**（gate 自身のみ除外。除外が空振りでないことを正の側で固定） |

**G3 を allowlist ではなく exact-fit inventory にする**のが要点。allowlist だと
「新しい経路を足しても gate は黙る」ため、**新経路の追加が必ず gate を赤くする**形にする。
G1 が config 直読を封じているので、新経路が gate を迂回して版を取る道は
（§保証しないもの に挙げた限界を除いて）残らない。

**空振り green の排除**（単一出典検査で最も起きやすい退行）:

- 母集団 floor: 走査ファイル数 > 0 / 走査 token 数 > 0 / inventory 件数 == 3
- **検出器の正の自己検証**: 実ファイル `app/Support/Legal/LegalConsent.php` を走査して
  `configKey === 1` が返ること、`app/Actions/Fortify/CreateNewUser.php` を走査して
  `versionCall > 0` が返ること。走査器が壊れて全部 0 を返すようになったら、
  G1/G2/G4 は vacuous green になるが**この検査だけは必ず赤くなる**
- **負のコントロール**: 実ファイルを書き換えず fixture 文字列に対して検出器が点灯すること
- **false positive の非発生**: billing の実ファイル 3 本を走査して 0 件であること

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 同意バージョン (legal.consent_version) の解決点は
 * App\Support\Legal\LegalConsent::version() の **1 箇所だけ**である。
 *
 * 背景: 版は users.consent_version / inquiries.consent_version へ「どの版に同意したか」の
 * 証跡として forceFill で記録される。記録側が config を個別に直読すると形が分岐し
 * (実際に 3 形へ分岐していた)、空版の fail-fast が 1 経路にしか掛からない状態が生まれる。
 * 空版で証跡を書くと「どの版に同意したか」が事後に決定不能になる = 機能の名前が果たすべき
 * 役割そのものの破れ。
 *
 * ★走査規則は **legal. 名前空間 / LEGAL_CONSENT_VERSION / LegalConsent::version / draft-1**
 *   の 4 語彙に限定する。素の 'consent_version' で走ると、実在する別 feature
 *   (課金の自動購入同意 config('billing.auto_recharge.consent_version')) を巻き込んで
 *   false positive になる。名前が似ているだけの別概念を統合しない (思考原則 4)。
 *   この非巻き込みは「名前空間の隔離」テストが実ファイルで behavioral に固定する。
 *
 * 検出方式: token_get_all ベース (CarbonOverflowArithmeticGateTest /
 * ScenarioWritePathInventoryTest と同じ流儀)。regex ではなく token を使うのは
 * コメント・docblock・heredoc 内の**記述**を誤検出しないため。
 * 文字列リテラルは引用符を trim して正規化するので 'x' と "x" を等価に扱う。
 *
 * 単一出典の検査は**空振りで green になりやすい**。そこで
 *   (1) 母集団 floor (ファイル数 / token 数 / inventory 件数)
 *   (2) 検出器の正の自己検証 (実ファイルで実際に点灯すること)
 *   (3) 負のコントロール (fixture ソースで点灯すること。実ファイルは書き換えない)
 * の 3 つを必ず同梱する。
 *
 * DB 不使用の静的検査 (Architecture lane の作法)。
 */

/** 設定キー: SSOT だけが読んでよい。 */
const LEGAL_CONSENT_CONFIG_KEY = 'legal.consent_version';

/** env 名: config/legal.php だけが読んでよい。 */
const LEGAL_CONSENT_ENV_NAME = 'LEGAL_CONSENT_VERSION';

/** プレースホルダ版: コード側に literal を残さない (config 既定値が唯一の出所)。 */
const LEGAL_CONSENT_PLACEHOLDER_VERSION = 'draft-1';

/** 単一出典クラス (repo ルート相対)。 */
const LEGAL_CONSENT_SOURCE_FILE = 'app/Support/Legal/LegalConsent.php';

/** 本 gate 自身 (G4 の唯一の除外対象)。 */
const LEGAL_CONSENT_GATE_FILE = 'tests/Architecture/LegalConsentVersionSingleSourceTest.php';

/**
 * G3 の exact-fit inventory: LegalConsent::version() を呼んでよい app/ 相対パス。
 * **allowlist ではない** — 増えても減っても fail する。新しい同意書き込み経路を
 * 足すときはここへ登録すること (= レビューの目に必ず入る)。
 *
 * @var list<string>
 */
const LEGAL_CONSENT_VERSION_CALLERS = [
    'Actions/Fortify/CreateNewUser.php',
    'Actions/Inquiry/CreateInquiryAction.php',
    'Services/Auth/SocialAccountService.php',
];

/** 文字列リテラルの生表記 ('x' / "x") を引用符抜きで比較する。 */
function legalConsentLiteralEquals(string $literal, string $expected): bool
{
    return trim($literal, "'\"") === $expected;
}

/**
 * 空白・コメントを飛ばして次の意味のあるトークン位置を返す。
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function legalConsentNextMeaningful(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * 1 ソースを走査して各語彙の出現数を返す (純関数 = 負のコントロールから直接呼べる)。
 *
 * @return array{configKey: int, envName: int, placeholder: int, versionCall: int, tokens: int}
 */
function legalConsentScanSource(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $result = ['configKey' => 0, 'envName' => 0, 'placeholder' => 0, 'versionCall' => 0, 'tokens' => 0];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token)) {
            continue;
        }
        $result['tokens']++;
        [$id, $value] = $token;

        // 文字列リテラル 3 種 (引用符の種類を問わない)
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            if (legalConsentLiteralEquals($value, LEGAL_CONSENT_CONFIG_KEY)) {
                $result['configKey']++;
            }
            if (legalConsentLiteralEquals($value, LEGAL_CONSENT_ENV_NAME)) {
                $result['envName']++;
            }
            if (legalConsentLiteralEquals($value, LEGAL_CONSENT_PLACEHOLDER_VERSION)) {
                $result['placeholder']++;
            }

            continue;
        }

        // LegalConsent::version / \App\Support\Legal\LegalConsent::version
        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }
        $segments = explode('\\', $value);
        if (end($segments) !== 'LegalConsent') {
            continue;
        }
        $doubleColon = legalConsentNextMeaningful($tokens, $i);
        if ($doubleColon === null
            || ! is_array($tokens[$doubleColon])
            || $tokens[$doubleColon][0] !== T_DOUBLE_COLON) {
            continue; // `use App\Support\Legal\LegalConsent;` 等は呼び出しではない
        }
        $method = legalConsentNextMeaningful($tokens, $doubleColon);
        if ($method !== null
            && is_array($tokens[$method])
            && $tokens[$method][0] === T_STRING
            && $tokens[$method][1] === 'version') {
            $result['versionCall']++;
        }
    }

    return $result;
}

/**
 * repo ルート相対パス => 走査結果。
 *
 * @param  list<string>  $dirs  repo ルートからの相対ディレクトリ
 * @return array<string, array{configKey: int, envName: int, placeholder: int, versionCall: int, tokens: int}>
 */
function legalConsentScanTree(array $dirs): array
{
    $root = base_path();
    $scanned = [];

    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $source = file_get_contents($absolute);
            if (! is_string($source)) {
                continue;
            }
            $scanned[substr($absolute, strlen($root) + 1)] = legalConsentScanSource($source);
        }
    }

    ksort($scanned);

    return $scanned;
}

/** 実ファイルを 1 本走査する。 */
function legalConsentScanFile(string $relative): array
{
    $source = file_get_contents(base_path($relative));
    expect($source)->toBeString();

    return legalConsentScanSource((string) $source);
}

test('G1: 設定キー legal.consent_version を読むのは LegalConsent だけである', function (): void {
    $violations = [];
    foreach (legalConsentScanTree(['app']) as $relative => $scan) {
        if ($scan['configKey'] > 0 && $relative !== LEGAL_CONSENT_SOURCE_FILE) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'config キー legal.consent_version の直読を検出しました。'
        .'同意バージョンは App\Support\Legal\LegalConsent::version() 経由で取得してください '
        .'(空版 fail-fast がそこに集約されています)。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('G2: env LEGAL_CONSENT_VERSION を読むのは config/legal.php だけである', function (): void {
    $violations = [];
    foreach (legalConsentScanTree(['app', 'config']) as $relative => $scan) {
        if ($scan['envName'] > 0 && $relative !== 'config/legal.php') {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'env(LEGAL_CONSENT_VERSION) の直読を検出しました。env を読むのは config/legal.php だけです。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    // 正の側: env 口が実在する (口ごと消えて vacuous green になるのを防ぐ)
    expect(legalConsentScanFile('config/legal.php')['envName'])->toBe(1);
});

test('G3: LegalConsent::version() の呼び出し元が inventory と exact-fit である', function (): void {
    $callers = [];
    foreach (legalConsentScanTree(['app']) as $relative => $scan) {
        if ($scan['versionCall'] > 0 && $relative !== LEGAL_CONSENT_SOURCE_FILE) {
            $callers[] = substr($relative, strlen('app/'));
        }
    }
    sort($callers);

    expect($callers)->toBe(LEGAL_CONSENT_VERSION_CALLERS,
        '同意バージョンの書き込み経路が増減しました。新しい経路なら '
        .'LEGAL_CONSENT_VERSION_CALLERS へ登録し、消えたなら目録から外してください '
        .'(allowlist ではなく exact-fit の目録です)。実測: '.implode(', ', $callers));
});

test('G4: プレースホルダ literal draft-1 が app/ database/ tests/ に残っていない', function (): void {
    $violations = [];
    foreach (legalConsentScanTree(['app', 'database', 'tests']) as $relative => $scan) {
        if ($scan['placeholder'] > 0 && $relative !== LEGAL_CONSENT_GATE_FILE) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'プレースホルダ版 draft-1 の literal を検出しました。版の出所は config/legal.php の'
        .'既定値だけです (fixture でも LegalConsent::version() を使ってください)。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    // 除外した gate 自身は実際に literal を持つ (除外が空振り = 無意味な例外になっていない)
    expect(legalConsentScanFile(LEGAL_CONSENT_GATE_FILE)['placeholder'])->toBeGreaterThan(0);
});

test('到達境界: 走査母集団が空でない (ファイル数 / token 数 / 目録件数)', function (): void {
    $scanned = legalConsentScanTree(['app', 'config', 'database', 'tests']);

    expect(count($scanned))->toBeGreaterThan(0);
    expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);
    expect(LEGAL_CONSENT_VERSION_CALLERS)->toHaveCount(3);
});

test('到達境界: 検出器が実ファイルで実際に点灯する', function (): void {
    // 走査器が壊れて常に 0 を返すと G1/G2/G4 は vacuous green になる。ここだけは必ず赤くなる。
    $source = legalConsentScanFile(LEGAL_CONSENT_SOURCE_FILE);
    expect($source['configKey'])->toBe(1);
    expect($source['versionCall'])->toBe(0); // 宣言であって呼び出しではない

    $caller = legalConsentScanFile('app/'.LEGAL_CONSENT_VERSION_CALLERS[0]);
    expect($caller['versionCall'])->toBeGreaterThan(0);
    expect($caller['configKey'])->toBe(0);
});

test('負のコントロール: 引用符の種類を問わず設定キー / env 名 / 版 literal を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run(): void {
            $a = config('legal.consent_version');
            $b = config("legal.consent_version");
            $c = env('LEGAL_CONSENT_VERSION');
            $d = env("LEGAL_CONSENT_VERSION", "draft-1");
            $e = 'draft-1';
        }
    }
    PHP;

    $scan = legalConsentScanSource($fixture);
    expect($scan['configKey'])->toBe(2);
    expect($scan['envName'])->toBe(2);
    expect($scan['placeholder'])->toBe(2);
});

test('負のコントロール: コメント / docblock 中の表記は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    /**
     * config('legal.consent_version') を直読してはならない。env('LEGAL_CONSENT_VERSION') も同様。
     * 既定値は draft-1 である。
     */
    class Fixture {
        // config('legal.consent_version') / draft-1 / LEGAL_CONSENT_VERSION
        public function run(): void {}
    }
    PHP;

    $scan = legalConsentScanSource($fixture);
    expect($scan['configKey'])->toBe(0);
    expect($scan['envName'])->toBe(0);
    expect($scan['placeholder'])->toBe(0);
    expect($scan['tokens'])->toBeGreaterThan(0); // 走査自体は生きている
});

test('負のコントロール: 呼び出しは検出し、use 文だけは呼び出しに数えない', function (): void {
    $called = <<<'PHP'
    <?php
    use App\Support\Legal\LegalConsent;
    class Fixture {
        public function run(): void {
            $a = LegalConsent::version();
            $b = \App\Support\Legal\LegalConsent::version();
        }
    }
    PHP;

    $importOnly = <<<'PHP'
    <?php
    use App\Support\Legal\LegalConsent;
    class Fixture {
        public function run(LegalConsent $consent): void {}
    }
    PHP;

    expect(legalConsentScanSource($called)['versionCall'])->toBe(2);
    expect(legalConsentScanSource($importOnly)['versionCall'])->toBe(0);
});

test('名前空間の隔離: billing の consent_version を巻き込まない', function (): void {
    // fixture (形として巻き込まないこと)
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function rules(): array {
            return [
                'consent_version' => config('billing.auto_recharge.consent_version'),
            ];
        }
    }
    PHP;
    $scan = legalConsentScanSource($fixture);
    expect($scan['configKey'])->toBe(0);
    expect($scan['envName'])->toBe(0);
    expect($scan['versionCall'])->toBe(0);

    // 実ファイル (現実の別 feature を巻き込まないこと)
    foreach ([
        'app/Http/Requests/Billing/BillingCheckoutRequest.php',
        'app/Http/Requests/Onboarding/ActivatePersonalRequest.php',
        'config/billing.php',
    ] as $relative) {
        $real = legalConsentScanFile($relative);
        expect($real['configKey'])->toBe(0, $relative);
        expect($real['envName'])->toBe(0, $relative);
        expect($real['versionCall'])->toBe(0, $relative);
    }
});
```

### PHPStan適合チェック

- `tests/` は PHPStan の解析対象外（`phpstan.neon` の `paths` は `app` / `config` /
  `database` / `routes`）。ただし docblock は既存 Architecture テストと同水準で書く
- [x] `NoNonCompoundGlobalUseTest` 対応: 本ファイルは namespace 宣言を持たないため、
      `RecursiveIteratorIterator` / `RecursiveDirectoryIterator` / `FilesystemIterator` /
      `SplFileInfo` は **`use` を書かず素で参照する**（既存 `CarbonOverflowArithmeticGateTest` と同形）
- [x] 関数名・定数名は `legalConsent*` / `LEGAL_CONSENT_*` で prefix する
      （Pest は全テストファイルを 1 プロセスへ読み込むため global 名前空間の衝突が実害になる）

### テスト計画

本施策そのものがテストである。テストケース名は上記コードの `test(...)` 文字列がそのまま正本:

| # | テストケース名 | 検証内容 |
|---|---|---|
| 1 | `G1: 設定キー legal.consent_version を読むのは LegalConsent だけである` | app/ 配下の config 直読が 0 件 |
| 2 | `G2: env LEGAL_CONSENT_VERSION を読むのは config/legal.php だけである` | app/ + config/ の env 直読が 1 箇所、かつそこに実在 |
| 3 | `G3: LegalConsent::version() の呼び出し元が inventory と exact-fit である` | 呼び出し元が 3 本ちょうど |
| 4 | `G4: プレースホルダ literal draft-1 が app/ database/ tests/ に残っていない` | fixture の再発明が 0 件、gate 自身の除外が空振りでない |
| 5 | `到達境界: 走査母集団が空でない (ファイル数 / token 数 / 目録件数)` | 空走査 green の排除 |
| 6 | `到達境界: 検出器が実ファイルで実際に点灯する` | 走査器の死亡検出 |
| 7 | `負のコントロール: 引用符の種類を問わず設定キー / env 名 / 版 literal を検出する` | `'x'` と `"x"` の等価扱い |
| 8 | `負のコントロール: コメント / docblock 中の表記は検出しない` | token 走査であることの固定 |
| 9 | `負のコントロール: 呼び出しは検出し、use 文だけは呼び出しに数えない` | 完全修飾形の検出 / import の非計上 |
| 10 | `名前空間の隔離: billing の consent_version を巻き込まない` | false positive の非発生（fixture + 実ファイル 3 本） |

- [x] 個別の `DatabaseTransactions` を使っていない（Architecture lane は DB 非使用）
- [x] `--parallel` 安全（読み取り専用のファイル走査のみ。共有状態を書かない）

### mutation で赤化を確認する手順（必須）

gate が「実際に効く」ことを、**実装完了後に手で 1 回**確認する。

**戻し方の安全手順（Codex Warning 対応）**: mutation は必ず 1 件ずつ行い、以下を守る。

1. mutation 前に `git status` / `git diff` を見て、**対象ファイルの未コミット差分が自分の変更だけ**で
   あることを確認する（並行タスクが同じ worktree を触っていないことの確認も兼ねる）
2. mutation を入れて `composer test` の該当 filter を走らせ、**期待どおり赤くなること**を確認する
3. 戻しは `git diff` を見ながら**手で戻す**（Edit で 1 行を書き戻す）
4. `git checkout -- <file>` は **「そのファイルの未コミット差分が mutation の 1 行だけ」と
   確認できたときに限り**使ってよい。未コミットの他者変更・自分の未コミット実装が
   同居している場合は**使わない**（実装ごと消える）
5. 全 mutation を戻したら `git diff` で mutation の痕跡が 0 であることを確認してから次へ進む

| # | mutation | 実行コマンド | 期待される赤 |
|---|---|---|---|
| M1 | `app/Actions/Fortify/CreateNewUser.php` の `LegalConsent::version()` を `config()->string('legal.consent_version')` に戻す | `composer test -- --filter=LegalConsentVersionSingleSource` | **G1** が `app/Actions/Fortify/CreateNewUser.php` を列挙して fail、かつ **G3** が exact-fit 不一致で fail |
| M2 | 同じ場所を `config()["legal.consent_version"]` のような**二重引用符**にする | 同上 | **G1** が fail（引用符正規化が効いている証拠） |
| M3 | 任意の app/ ファイル（例 `app/Support/Environment.php`）に `\App\Support\Legal\LegalConsent::version();` の 1 行を足す | 同上 | **G3** が exact-fit 不一致で fail（新経路の可視化） |
| M4 | `database/factories/InquiryFactory.php` の値を `'draft-1'` へ戻す | 同上 | **G4** が fail |
| M5 | `config/legal.php` の `env('LEGAL_CONSENT_VERSION', ...)` を `'draft-1'` の直値に置き換える | 同上 | **G2** の正の側（`envName === 1`）が fail |
| M6 | `legalConsentScanSource()` の `$result['configKey']++` 等を全部消して常に 0 を返させる | 同上 | **到達境界: 検出器が実ファイルで実際に点灯する** と**負のコントロール 3 本**が fail（G1/G2/G4 は vacuous green になるが、この 4 本が必ず捕まえる） |
| M7 | `app/Support/Legal/LegalConsent.php` の `Assert::stringNotEmpty()` を削除 | `composer phpstan` + `composer test -- --filter=LegalConsent` | **PHPStan が `return.type` で error**、かつ Unit テスト「空文字なら例外」が fail |

M6 と M7 が本 gate の要（「gate が空振りしていない」ことと「fail-fast が消せない」ことの担保）。

### リスク

- **走査コスト**: `app` + `config` + `database` + `tests` の全 `*.php` を最大 4 回走査する
  （テストごとに `legalConsentScanTree` を呼ぶため）。既存の
  `CarbonOverflowArithmeticGateTest` が `app` + `database` + `tests` を同様に走査しており
  実測で許容範囲。キャッシュ機構は入れない（思考原則 2）
- **exact-fit の運用コスト**: 同意書き込み経路を足すたびに目録の更新が要る。これは
  **意図した摩擦**（レビューの目に必ず入れる）であり、コストではなく機能である
- `tests/` を G4 の母集団に入れているため、将来テストが `'draft-1'` を書きたくなると
  gate に当たる。その場合は `LegalConsent::version()` か `config()` 経由で書く
  （版 literal を増やさない）

---

## 施策 5: Unit テストの新設

### 変更箇所

- ファイル: `tests/Unit/Support/Legal/LegalConsentTest.php`（**新規**）

### 波及変更

- なし（新規テストの追加のみ）

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Support\Legal\LegalConsent;

// 同意バージョンの単一解決点。空版で証跡を書かせないための fail-fast がここに集約されている。
// (呼び出し元の固定は tests/Architecture/LegalConsentVersionSingleSourceTest.php が担当)

it('config の同意バージョンをそのまま返す', function (): void {
    config(['legal.consent_version' => '2026-09-01']);

    expect(LegalConsent::version())->toBe('2026-09-01');
});

it('同意バージョンが空文字なら例外を投げる (空版の証跡を書かせない)', function (): void {
    config(['legal.consent_version' => '']);

    expect(fn (): string => LegalConsent::version())
        ->toThrow(InvalidArgumentException::class, 'legal.consent_version must be configured');
});

it('同意バージョンが未設定なら例外を投げる', function (): void {
    config(['legal.consent_version' => null]);

    // config()->string() が先に落とす (webmozart Assert と同じ InvalidArgumentException)
    expect(fn (): string => LegalConsent::version())
        ->toThrow(InvalidArgumentException::class);
});
```

### PHPStan適合チェック

- `tests/` は解析対象外。ただし `use App\Support\Legal\LegalConsent;` は**複合名**なので
  `NoNonCompoundGlobalUseTest` に抵触しない。`InvalidArgumentException` は
  **`use` を書かず素で参照する**（非複合 global use の禁止）

### テスト計画

| # | テストケース名 | 検証内容 |
|---|---|---|
| 1 | `config の同意バージョンをそのまま返す` | config の値を素通しすること（加工しない） |
| 2 | `同意バージョンが空文字なら例外を投げる (空版の証跡を書かせない)` | `InvalidArgumentException` + メッセージ一致 |
| 3 | `同意バージョンが未設定なら例外を投げる` | `config()->string()` の型検査で落ちること |

- [x] テストデータは Factory（本テストは DB を触らないため該当なし）
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）
- [x] `--parallel` 安全（`config([...])` の変更はテストごとにアプリが再構築されるためリーク無し）

### リスク

- `config(['legal.consent_version' => null])` は次テストへ漏れない（Laravel が
  テストごとにアプリケーションを作り直す）。念のため各テストで明示的に値を設定している

---

## 振る舞いが変わらないことをどう示すか

**直接証拠**: 以下の既存テストが **1 行の変更もなく green** であること。
（変更が要るなら、それは「振る舞いが変わった」ことを意味する）

| ファイル | なぜ証拠になるか |
|---|---|
| `tests/Feature/Auth/RegistrationTest.php`（L25） | 期待値を `config()->string('legal.consent_version')` で**独立に**作っている。実装が `LegalConsent::version()` に変わっても `users.consent_version` が同値であることの behavioral な証拠になる |
| `tests/Feature/Inquiry/ContactSubmissionTest.php`（L51） | 同上（`inquiries.consent_version`） |
| `tests/Feature/Auth/SocialAuth*` 系 | SSO 登録経路が壊れていないこと |
| `tests/Feature/Inquiry/InquiryModelTest.php` / `PurgeInquiriesCommandTest.php` / `tests/Feature/Filament/InquiryResourceTest.php` | Factory の `consent_version` 変更で Inquiry 生成が壊れていないこと |
| `tests/Feature/LegalPagesTest.php` | 法務ページに一切触っていないこと |

### 既存 Feature テストを「揃えない」判断（Codex に指摘されても維持する）

`RegistrationTest.php:25` / `ContactSubmissionTest.php:51` の期待値を
`LegalConsent::version()` へ揃えることは**しない**。

1. 揃えると実装とテストが**同じ 1 関数を見る**ため、その関数が壊れても両方が同時にずれて
   green のままになる（トートロジー化）。config を独立に読むことで
   「`LegalConsent` が config の値を忠実に返している」ことの behavioral な担保になる。
2. **既存テストが 1 行も変わらず green** であることが、振る舞い不変の直接証拠になる。
3. gate の母集団は `app/` であり、`tests/` の config 直読は G1 の違反ではない
   （`tests/` に掛かるのは G4 の `'draft-1'` literal 禁止だけ）。

**間接証拠**: 値が同一であることの静的根拠 —— `config/legal.php:22` の既定 `'draft-1'`、
`.env.testing:77` `LEGAL_CONSENT_VERSION=draft-1`、`.env.example:168` 同値（いずれも実読確認）。
変更は「同じ値を取り出す経路の付け替え」であり、値そのものは動かない。

**唯一の例外**（正直に書く）: `LEGAL_CONSENT_VERSION=`（空文字）が設定された環境でのみ
登録 / SSO 登録が 500 になる（現在は空版の証跡が静かに書かれる）。§施策 1 のリスク参照。

## 検証手順（実装完了の定義）

1. `composer fix`（Pint）→ 差分が出ないこと
2. `composer phpstan` → level 10 で green
3. `composer test` → 全 green（`--parallel`。`RefreshDatabase` はグローバル適用）
4. 上記 §mutation で赤化を確認する手順 M1〜M7 を実施し、**期待どおりに赤くなること**を確認して戻す
5. `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` → フロント変更が無いため
   影響しないが、AGENTS.md の検証コマンド一式として実行する

## 保証しないもの（誇張しない）

本設計の gate が保証するのは、**`app/`（一部 `config/` `database/` `tests/`）配下の PHP ソースに
おける、静的に決定可能な参照形だけ**である。以下には**無言で効かない**:

1. **間接読み**: `config('legal')['consent_version']` / `Config::get($key)` の変数キー /
   文字列連結でキーを組み立てる形は、token の完全一致に掛からない
2. **難読化**: `"legal.\x63onsent_version"` のようなエスケープを含む表記は正規化していない
   （対象キーはエスケープ文字を含まず、現実の脅威ではないため。思考原則 2）
3. **母集団外**: `resources/views/**`（blade）/ `resources/js/**`（Svelte）/ `routes/` からの
   読みは G1/G3 の母集団に入らない
4. **DB の既存値**: 既に書かれた `consent_version` 行の整合は検査せず、遡及是正もしない
5. **版と文面の対応**: 版番号が実際の規約文面に対応していることは**一切保証しない**
   （文面は未確定プレースホルダ）
6. **本番値の妥当性**: `draft-1` のまま本番が起動することは検出しない（= スコープ外 (b)）
7. **fail-fast の時点**: 空版で落ちるのは**版を書き込もうとした時点**であり、起動時ではない
8. **billing の同意版**: `billing.auto_recharge.consent_version` は**意図的に対象外**
   （別 feature。統合しない）
9. **動的呼び出し**: `$class::version()` / `call_user_func([LegalConsent::class, 'version'])` は
   G3 の検出形に入らない（実測 0 件。全 dynamic dispatch の deny 化はコストに見合わない）

## スコープ外（オーナー判断。将来入れるときの前提条件つき）

> 以下 3 点は**オーナー判断で本タスクのスコープ外と確定**している。ここに残すのは
> 「次の担当が判断できるようにするため」であり、本タスクでは**実装しない**。

### (a) `config/legal.php` の env 口を外す（spirux 形）

- **やらない理由**: 本番 / staging の `.env` に既に `LEGAL_CONSENT_VERSION` がある場合、
  env 口を外すと**設定が無言で無視される**。運用告知なしに入れられない
- **将来の前提条件**: (i) 本番 / staging の実 `.env` に当該キーが無いことを確認するか、
  値をコード既定へ移送してから外す。(ii) `.env.example:168` の削除も同時に行う
  （`tests/Architecture/EnvExampleInvariantTest.php` の既存 assertion に
  `LEGAL_CONSENT_VERSION` は無いため機械的衝突は無い = 確認済み）。
  (iii) **本設計の G2 を「`config/legal.php` にのみ存在する」から「どこにも存在しない」へ
  書き換える**必要がある（G2 の正の側 `envName === 1` が fail するため）

### (b) `ProductionEnvGuard` にプレースホルダ `draft-1` 拒否を足す（motivation 形）

- **やらない理由**: 既定値が `draft-1` のままなので、入れた瞬間に **production 起動が落ちる
  破壊的変更**になる（`TRUSTED_PROXIES` = T108 と同種）。規約文面を確定してリリースする
  タイミングで入れるのが自然
- **将来の前提条件**: (i) 実際の版番号（例 `2026-09-01`）を決めて本番 `.env` に設定してから
  guard を有効化する。(ii) `AGENTS.md` の「運用要件」節へ TRUSTED_PROXIES と同形で追記する
  （未設定なら起動が落ちる = 初回デプロイ前に設定が要る破壊的変更である旨）。
  (iii) `tests/Feature/Support/ProductionEnvGuardTest.php` に検査を追加する。
  (iv) 検査は `LegalConsent::version()` ではなく `config` を直接見ることになるため、
  **G1 の allowlist へ `app/Support/ProductionEnvGuard.php` を追加**するか、
  guard 側に専用の述語を `LegalConsent` へ足す（後者が単一出典の趣旨に合う）

### (c) 法務ページへの適用版表示 / 規約文面の確定

- **やらない理由**: 文面確定は法務の領域でこちらでは決められない。
  `resources/views/legal/terms.blade.php` の文面はプレースホルダ（「アプリ公開時に記入」）で
  最終改定日も未記入であり、版表示だけ足しても**空文面に版を貼る**ことになる
- **将来の前提条件**: (i) 文面確定が先。(ii) 表示に使う値は `LegalConsent::version()` 経由に
  する（blade で `config()` 直読しない）。ただし blade は本 gate の母集団外なので、
  版表示を入れるときは **G1 の母集団に `resources/views/` を足す**必要がある
  （blade は PHP としてパースできないため、`token_get_all` ではなく別方式が要る）。
  (iii) spirux 形の「版 ↔ 文面 hash 台帳」まで踏み込むのは文面確定後

### その他（正典 t1 も要求していない）

規約改定時の再同意フロー / 同意履歴テーブル / 文面と版番号の対応づけ /
SSO 往復中の版固定は motivation が明示的にスコープ外としており、本設計も扱わない。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 新規 3 本 + 既存 4 本の局所変更のみ。既存の設計・データモデル・API を一切変えず、既存テストは 1 行も変更しない。単独 worktree で完結し、main へのマージ順序に依存しない |
| 競合リスク | **低**。並行タスク（queue-dispatch-atomicity）と共有するのは `app/Actions/Inquiry/CreateInquiryAction.php` のみで、本設計が触るのは L10-16 の `use` 節と L31-33 だけ（相手の関心は L58 以降の `dispatchNotification`）。競合した場合も `use` 節の自明なマージで解決できる。他の 3 タスクとはファイルが完全に分かれる |
| PR 説明に必ず書くこと | 「`LEGAL_CONSENT_VERSION=`（空文字）環境でのみ登録 / SSO 登録が 500 になる挙動変更がある（意図した強化）」 |
