## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PostgreSQL 18 (テストも本番同等の pgsql。sqlite 二重運用なし)
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → CustomTeam → Project 階層）

【本件固有の前提 — 重要】
本設計は「家系の機能台帳 (lctl)」という外部の正典への追従設計である。
- 正典の裁定 (AG-036〜AG-047) はオーナーが確定させたもので、設計側が採否を選べる余地は無い。
  「裁定自体が妥当か」ではなく「確定した裁定へ最小のスコープで追従できているか」を見よ。
- 「正典の不変条件（全数）と本設計での扱い」の表 (I1〜I18) がスコープの定義である。
  確定裁定の取りこぼし、未確定項目・他 feature 所有物の混入があれば [Critical] で指摘せよ。
- 概念設計は別セッションで APPROVED 済みである。本レビューは**詳細設計**の水準
  (コードの正確性・型・テスト・波及変更・セキュリティ) を見てほしい。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件。
   特に「層2 (テナント境界=404) は層3 (認可=403) より前」「層2 は binding の直後・FormRequest より前で閉じる」
   「tenant キーを payload から受け取らない」「cross-org 不可」）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/templates の責務分離、アイコンは Lucide

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: organization-tenancy-ag-catchup

家系の機能台帳 (lctl) feature `organization-tenancy` (revision `46-c1830b632b4d` /
ledger `81f0e624363b0c707a424c0695253eb6d1536451`) への追従。
aicue セルは `status: pending` / `assessment: divergence_candidate`。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応する Factory の作成も施策に含める**
- **DTO + JsonResource** パターン（AGENTS.md 参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + **PostgreSQL 18**
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数。免除簿なし）
- 月/年/四半期の加減算は `*NoOverflow` 系を明示（`CarbonOverflowArithmeticGateTest`）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — APPROVED (conceptual-review Round 4)

---

## 正典の不変条件（全数）と本設計での扱い

**この表が本設計のスコープの定義である。** 正典 (`organization-tenancy` revision 46) が
掲げる不変条件を全数列挙し、1 行ずつ「aicue の現状 / 本設計での扱い」を書く。

| # | 正典の不変条件 | 裁定 | aicue の現状 | 本設計 |
|---|---|---|---|---|
| I1 | URL に現れた資源が現在の組織に属することを**入力検証より前**の段で確かめ、違えば **404**（403 にしない） | AG-036 | **充足**（aicue 形が標準形として採用された側。`MembershipScopedOrganizationBinder` + `project.in-route-org` + `TenantBoundaryOrderingTest`） | **スコープ外**（層の位置は動かさない。org の取得元だけ変える） |
| I2 | 「いまどの組織か」は **URL だけ**で決まる。保持列と切替 endpoint は**存在してはならない**（2 方式の併存不可） | AG-037 | **未充足**（`users.current_organization_id` + `organizations.switch` + `CurrentOrganizationResolver` の自己修復） | **施策 5〜8** |
| I3 | 個人組織を**種別として区別しない**（種別フラグを撤去） | AG-038 | **未充足**（`organizations.is_personal`） | **施策 4** |
| I4 | 初期組織生成の冪等判定は「**所属組織が 0 件か**」。トランザクション内で利用者行を**行ロック**してから数える | AG-038 | **未充足**（種別フラグ判定・行ロックなし） | **施策 4** |
| I5 | 初期組織生成の**呼び出しサイトを機械検査で固定**する | AG-038 | **未充足**（検査なし） | **施策 4** |
| I6 | 識別名の文字種は**小文字英数字とハイフンのみ**。先頭末尾ハイフン不可・連続ハイフン不可。**大文字は小文字へ正規化** | AG-039b | **未充足**（`Str::slug()` 直呼び。検証なし） | **施策 1** |
| I7 | 識別名の一意性は**大文字小文字を区別しない** | AG-039c | **未充足**（通常の unique のみ） | **施策 1** |
| I8 | 識別名の規則は**値オブジェクト 1 本**に集約し、作成（利用者が選べる。省略時は組織名から導出）と改名の両経路が通る | AG-039 | **未充足** | **施策 1 / 3** |
| I9 | 予約語を持ち、**理由 3 分類**（ルート衝突 / 権威の詐称 / 構文衝突）の記載を必須にする | AG-039 | **未充足**（設定ファイルが存在しない） | **施策 2** |
| I10 | **識別名の位置に現れる固定セグメントが予約語に登録されている**ことを route 表から機械検査する | AG-039 | **未充足** | **施策 2** |
| I11 | 予約語は**保存できない**（作成・改名の両経路で拒否） | AG-039 | **未充足** | **施策 2** |
| I12 | 改名は **30 日あたり 5 回**まで。最終権威は**組織行を行ロックした後の再判定**（事前判定は画面表示のための早期拒否） | AG-046 | **未充足**（改名経路なし） | **施策 3** |
| I13 | **旧識別名は予約せず解放する**（履歴表に一意制約を張らない） | AG-046 | **未充足** | **施策 3** |
| I14 | 機械が使う経路は**不変の内部識別子**で組織を指す | AG-047 | 実態は満たすが**検査なし** | **施策 9** |
| I15 | 権限ライブラリのチーム厳格チェックが `true` | AG-040 | **充足**（`config/laratrust.php`） | **スコープ外**（検査の新設は「還流候補」＝未確定） |
| I16 | 階層は Organization → CustomTeam → Project の 3 階層 | boundary | **充足** | **スコープ外** |
| I17 | Default Team パターン（組織ごとにちょうど 1 つ） | boundary | **充足**（`OrganizationProvisioningService`） | **維持**（施策 4 で壊さない） |
| I18 | 登録トランザクション内で組織を 1 つ作る | boundary | **充足** | **維持**（施策 4） |

### スコープ外（正典が aicue に求めていない / 未確定 / 他所有）

| 項目 | 理由 |
|---|---|
| AG-036 の層の新設 | I1 のとおり充足済み。**aicue 形が標準形**として採用された側 |
| AG-040 の配布物（`TeamScopedRoleCheckInvariantTest` + 走査器） | 台帳が **「還流候補」** と明記する未確定項目。aigenba にのみ実在 |
| 正典の版の呼び名（t0 → t1 等） | 台帳が「未確定（議題）」。**キュレーターの責務** |
| lctl への書き込み（`append_event` 等） | 設計エージェントの責務外 |
| 組織そのものを削除する route | 正典 boundary が「route を持つのは aigenba と spirux の 2 本」と書く。aicue に無いことは逸脱ではない |
| 招待の役割列 | 台帳が「auth-invitation-flow 側の事実。ここでは触れない」と明記 |
| 接続の取り消し層 / MCP 組織書き込みスコープ | 所有は `mcp-org-write-scope`（AG-125） |
| 認証後の着地画面の組織アクセス状態（4 値） | 所有は auth 側（AG-113） |
| シート課金同期 / org-tree versioning / Filament 管理パネルの権限境界 | 正典 boundary が「含まない」と明記 |
| 旧 URL からの転送・並走 | 思考原則 3 + 正典の実装判断 |
| 組織別の動的 manifest | `start_url` は分岐 route で足りる（思考原則 2） |

---

## 施策一覧

**コミット順序を固定する**（識別名を堅くしてから、識別名を全 URL の前置きにする）。

| # | 施策名 | 主な変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 識別名の構文型 `OrganizationSlug` と DB 制約 | `app/Support/Organization/OrganizationSlug.php`(新) / migration(新) / `OrganizationProvisioningService` | Critical |
| 2 | 予約語（理由 3 分類必須）と保存可能型 `AssignableOrganizationSlug` | `config/organization-slug-reserved.php`(新) / `OrganizationSlugReservedWords.php`(新) / `AssignableOrganizationSlug.php`(新) / gate(新) | Critical |
| 3 | 改名経路（30 日 5 回・旧識別名は解放） | `OrganizationSlugRename` model(新) / `OrganizationSlugRenameLimiter`(新) / controller(新) / route / Svelte | High |
| 4 | 種別フラグ撤去 + 初期組織生成の行ロック冪等判定 | `OrganizationProvisioningService` / migration(新) / `Organization` / `OrganizationFactory` / gate(新) | Critical |
| 5 | 業務 route の組織 URL 配下への移設 | `routes/web.php` / 23 controller / `bootstrap/app.php` | Critical |
| 6 | 組織文脈の binding 由来化（共有プロパティ・middleware） | `HandleInertiaRequests` / `EnsureProjectBelongsToRouteOrganization`(改称) / `shared-props.ts` / `AppLayout.svelte` | Critical |
| 7 | 保持列・切替 route・自己修復の撤去 | `User` / `OrganizationSwitchController`(削除) / `CurrentOrganizationResolver`(削除) / migration(新) / `AppServiceProvider` / `NotificationCenterService` / `NotificationController` | Critical |
| 8 | 組織文脈を持たない入口の分岐 route | `OrganizationEntryController`(新) / `Organizations/Choose.svelte`(新) / route | High |
| 9 | 機械経路の組織識別子契約（2 段の全数分類） | `tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php`(新) / gate(新) | High |
| 10 | 旧 URL の走査根ベース残存検査 | gate(新) / 棚卸し | High |
| 11 | 乖離台帳の更新（D4 書き換え / 新規登録 / 採用時債務） | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | Critical |

---

## 施策 1: 識別名の構文型 `OrganizationSlug` と DB 制約

満たす不変条件: **I6 / I7 / I8**

### 変更箇所

- 新設: `app/Support/Organization/OrganizationSlug.php`
- 新設: `app/Support/Organization/OrganizationSlugConstraintViolation.php`（一意制約違反の種別識別）
- 新設 migration: `database/migrations/2026_08_23_000100_normalize_and_constrain_organization_slug.php`
- 変更: `app/Services/Organization/OrganizationProvisioningService.php`（`uniqueSlug()` の置換）
- 変更: `app/Models/Organization.php`（`slug` を `$fillable` から外し、書き込みを保存経路 1 本へ寄せる）

### 波及変更

- TypeScript型定義: なし（この施策では slug の形式が変わるだけで prop の型は不変）
- API Resource/DTO: なし
- テストファイル: `database/factories/OrganizationFactory.php`（slug 生成を値オブジェクト経由へ）/
  slug を直書きしている全テスト（`rg "'slug' =>"` で棚卸し）

### 現行コード

```php
// app/Services/Organization/OrganizationProvisioningService.php
private function uniqueSlug(string $name): string
{
    $base = Str::slug($name) ?: 'org';

    return $base.'-'.Str::lower(Str::random(6));
}
```

`Str::slug()` は日本語名から空文字を返し得るため `?: 'org'` でしのいでいるだけで、
文字種・長さ・先頭末尾/連続ハイフン・大小の正規化・予約語のいずれも検証していない。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Organization;

use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * 組織識別名の **構文** を表す不変の値オブジェクト (家系裁定 AG-039b / AG-039c)。
 *
 * ★不変条件は「構文的に妥当で正規化済み」だけである。**保存してよいことは意味しない** —
 *   予約語でないことは AssignableOrganizationSlug が担う (裁定 AG-039)。
 * ★正規化は「大文字を小文字へ倒す」だけで、それ以外の矯正はしない
 *   (記号の除去や連結を勝手にやると、利用者が入れた値と保存される値が黙って食い違う)。
 */
final readonly class OrganizationSlug
{
    /** 最短。1 文字の識別名は route の固定セグメントと見分けが付きにくいので許さない。 */
    public const int MIN_LENGTH = 3;

    /** 最長。DNS ラベル上限 (63) に合わせる (将来サブドメイン化しても破綻しない)。 */
    public const int MAX_LENGTH = 63;

    /** 小文字英数字とハイフン。先頭末尾はハイフン以外、連続ハイフンなし。 */
    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(public string $value) {}

    /** 利用者が入力した識別名から作る。大文字は小文字へ倒す。それ以外は矯正しない。 */
    public static function fromInput(string $input): self
    {
        $normalized = Str::lower(trim($input));

        if (mb_strlen($normalized) < self::MIN_LENGTH || mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('識別名の長さが範囲外である');
        }
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException('識別名の文字種が不正である');
        }

        return new self($normalized);
    }

    /**
     * 組織名から識別名を導出する (利用者が省略したとき)。
     *
     * ★日本語名は Str::slug で空になるため、**空になったら例外にせず呼び出し側へ null を返す**
     *   のではなく、ここでは「導出できなかった」ことを型で表すために null を返す。
     *   代替の識別名を決めるのは Service の責務である (値オブジェクトが 'org' を捏造しない)。
     */
    public static function deriveFromName(string $name): ?self
    {
        $candidate = Str::slug($name);
        if ($candidate === '') {
            return null;
        }

        $candidate = mb_substr($candidate, 0, self::MAX_LENGTH);
        $candidate = trim($candidate, '-');

        return mb_strlen($candidate) >= self::MIN_LENGTH ? new self($candidate) : null;
    }
}
```

migration（**順序を固定する。更新より先に検査する**）:

```php
public function up(): void
{
    // 1. 更新せずに正規化後の値を計算し、2. 衝突があれば fail-closed で止める
    $collisions = DB::table('organizations')
        ->selectRaw('lower(slug) as normalized, count(*) as c')
        ->groupBy('normalized')
        ->havingRaw('count(*) > 1')
        ->pluck('normalized');

    if ($collisions->isNotEmpty()) {
        throw new RuntimeException(
            '識別名を小文字化すると衝突する組織がある。運用で解消してから再実行すること: '
            .$collisions->implode(', '),
        );
    }

    // 3. 衝突が無い場合だけ既存値を小文字化する
    DB::statement('UPDATE organizations SET slug = lower(slug) WHERE slug <> lower(slug)');

    // 4. CHECK と UNIQUE を付与する
    DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_slug_lowercase CHECK (slug = lower(slug))');
    Schema::table('organizations', function (Blueprint $table): void {
        $table->unique('slug', 'organizations_slug_unique');
    });
}
```

> **なぜ `UNIQUE (lower(slug))` 単独にしないか**: 同じ集合は守れるが、
> 「列の値は常に小文字である」という設計意図が制約から読めず、
> 大文字混じりの値が入ったまま一意性だけ守られる状態を許してしまう。
> `CHECK` + 通常 `UNIQUE` の合成なら「小文字でない値は入らない → 保存済みは全部小文字 →
> 通常の UNIQUE が大小無視の一意性と一致する」が制約だけで読める。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`self` / `?self` / `string`）
- [x] null安全（`deriveFromName()` は導出不能を `null` で表し、呼び出し側が分岐する）
- [x] DTOを返している（`readonly` 値オブジェクト。配列返却なし）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画

- [ ] 新規 `tests/Unit/Support/Organization/OrganizationSlugTest.php` —
      正例（`acme` / `acme-corp` / `a1-b2`）と負例（空 / 2 文字 / 64 文字 / `-acme` / `acme-` /
      `ac--me` / `Acme`→正規化される / `acme_corp` / `日本語`）
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugConstraintTest.php` —
      **CHECK 制約が実際に効く**こと（値オブジェクトを迂回した `DB::table()->insert(['slug' => 'Acme'])` が落ちる）
- [ ] 新規 同上 — **並行改名で一意制約違反**になること（実 DB。違反の種別を識別できること・
      識別できない違反は隠さず再送出すること）
- [ ] 既存 `tests/Feature/Organization/DefaultTeamInvariantTest.php` の slug 期待値更新
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（`tests/Pest.php` のグローバル適用に従う）

### リスク

- 既存 dev/production DB に大小違いの重複 slug があると migration が止まる。
  **これは意図した fail-closed** であり、黙って片方を書き換えるより安全。
  実行前に `SELECT lower(slug), count(*) FROM organizations GROUP BY 1 HAVING count(*) > 1` を
  人が確認する手順を TODO の備考へ書く（**エージェントが dev DB を破壊操作しない**＝禁止事項 3）。
- `MIN_LENGTH = 3` により、既存の 2 文字以下 slug があると保存経路が通らなくなる。
  現行の生成規則は `{base}-{6文字}` なので実データは 8 文字以上になるが、
  migration の検査段で**長さ違反も同時に検出**して fail-closed にする。

---

## 施策 2: 予約語（理由 3 分類必須）と保存可能型 `AssignableOrganizationSlug`

満たす不変条件: **I9 / I10 / I11**

### 変更箇所

- 新設: `config/organization-slug-reserved.php`
- 新設: `app/Enums/Organization/SlugReservationReason.php`（backed enum）
- 新設: `app/Support/Organization/OrganizationSlugReservedWords.php`（判定器。設定を型へ変換）
- 新設: `app/Support/Organization/AssignableOrganizationSlug.php`（構文妥当 かつ 非予約語）
- 新設: `tests/Architecture/OrganizationSlugReservedWordsInvariantTest.php`
- 新設: `tests/Support/Architecture/OrganizationSlugRouteScanner.php`（走査器）

### 波及変更

- TypeScript型定義: 改名フォームのエラー表示のみ（施策 3 で扱う）
- API Resource/DTO: なし
- テストファイル: 施策 1 の Unit テストへ「予約語は昇格できない」ケースを追加

### 変更後コード（要点）

```php
// app/Enums/Organization/SlugReservationReason.php
enum SlugReservationReason: string
{
    /** ルート衝突: 識別名の位置に現れる固定セグメントと同名になる (/organizations/settings 等)。 */
    case RouteConflict = 'route_conflict';
    /** 権威の詐称: 運営・管理・支援を騙れる語 (admin / support / billing 等)。 */
    case AuthorityImpersonation = 'authority_impersonation';
    /** 構文衝突: URL・DNS・予約識別子として解釈がぶれる語 (www / api / 数字だけ 等)。 */
    case SyntaxConflict = 'syntax_conflict';
}
```

```php
// config/organization-slug-reserved.php
// ★各語は理由分類の記載が必須である (裁定 AG-039)。分類を書かずに語を足すと
//   OrganizationSlugReservedWords::load() が例外で落ちる (fail-closed)。
return [
    'admin' => 'authority_impersonation',
    'settings' => 'route_conflict',
    'api' => 'syntax_conflict',
    // ...
];
```

```php
// app/Support/Organization/AssignableOrganizationSlug.php
/**
 * **保存してよい**組織識別名。不変条件は「構文的に妥当 かつ 非予約語」。
 *
 * ★生成と昇格は別操作である。構文型 OrganizationSlug を作るのが「生成」、
 *   予約語判定器を通してこの型にするのが「昇格」。
 * ★識別名を保存できる経路はこの型を受ける 1 本だけで、構文型を保存へ渡す道は型で消えている
 *   (OrganizationSlugWritePathTest が deny-by-default で固定する)。
 */
final readonly class AssignableOrganizationSlug
{
    private function __construct(public string $value) {}

    public static function promote(OrganizationSlug $slug, OrganizationSlugReservedWords $reserved): self
    {
        if ($reserved->contains($slug)) {
            throw new ReservedOrganizationSlugException($slug, $reserved->reasonFor($slug));
        }

        return new self($slug->value);
    }
}
```

Architecture 検査（走査器の共通規約 5 条に従う）:

```
it('識別名の位置に現れる固定セグメントは全て予約語に登録されている', ...)
  - 走査根: Route::getRoutes() のうち `organizations/{organization:slug}` の
    **識別名の位置に現れうる固定セグメント** (= /organizations/ 直下の静的セグメント)
  - 母集団が空なら fail (走査根の改名・削除で空振りしても気付ける)
  - 負例: 合成 route を足して未登録セグメントを検出できること
  - 正例: 現行 route 表が緑になること
it('予約語の全件が理由 3 分類のいずれかを持つ', ...)
it('未知の理由分類は読み込み時に落ちる', ...)  // fail-closed
```

**将来の予約語追加にも同じ義務が続く**（概念設計で確定した不変条件）:
予約語一覧を追加・変更する変更は、**既存組織の識別名との衝突を検査する migration
（または同等のデプロイ前検査）を同じ変更に含め、衝突があれば fail-closed で止める**。
この義務を `config/organization-slug-reserved.php` の冒頭 docblock と
`docs/app-integration-guide.md` の該当節に書く。

### PHPStan適合チェック

- [x] 設定配列の shape を `array<string, string>` のまま持ち回らず、読み込み直後に
      `array<string, SlugReservationReason>` へ変換する（未知分類は例外）
- [x] 戻り値の型が明示されている
- [x] `promote()` は例外か型付きの値のどちらかで、`null` を混ぜない

### テスト計画

- [ ] 新規 `tests/Unit/Support/Organization/AssignableOrganizationSlugTest.php` —
      予約語の昇格が例外になること / 非予約語が昇格できること / 理由が例外に載ること
- [ ] 新規 `tests/Unit/Support/Organization/OrganizationSlugReservedWordsTest.php` —
      未知の分類・分類なしの語で読み込みが落ちること（fail-closed）
- [ ] 新規 `tests/Architecture/OrganizationSlugReservedWordsInvariantTest.php`（上記 3 本 + 負例）
- [ ] 新規 `tests/Architecture/OrganizationSlugWritePathTest.php` —
      `organizations.slug` を書ける経路が `AssignableOrganizationSlug` を受ける 1 本だけであること
      （deny-by-default。走査根が空なら fail）
- [ ] 新規 `tests/Feature/Organization/ReservedSlugRejectionTest.php` —
      **作成**（利用者入力・組織名からの導出の両方）と**改名**で予約語が拒否されること

### リスク

- 予約語一覧を厚くしすぎると正当な組織名が取れなくなる。**初版は現行 route 表の固定セグメント +
  権威の詐称の最小集合**にとどめ、「あったら便利」で足さない（思考原則 2）。
- `deriveFromName()` の導出結果が予約語になった場合、Service は**代替を選ぶのではなく
  利用者に選ばせる**（黙って `admin-a1b2c3` のような値を作らない）。

---

## 施策 3: 改名経路（30 日 5 回・旧識別名は解放）

満たす不変条件: **I12 / I13 / I8（改名経路側）**

### 変更箇所

- 新設: `app/Models/OrganizationSlugRename.php` + `database/factories/OrganizationSlugRenameFactory.php`
- 新設 migration: `create_organization_slug_renames_table`
- 新設: `app/Services/Organization/OrganizationSlugRenameLimiter.php`
- 新設: `app/Http/Controllers/Organizations/OrganizationSlugController.php`
- 新設: `app/Http/Requests/Organizations/UpdateOrganizationSlugRequest.php`
- 変更: `routes/web.php`（`PATCH /organizations/{organization:slug}/slug`）
- 変更: `resources/js/pages/Organizations/Settings.svelte`（改名 UI）

### 波及変更

- TypeScript型定義: `Organizations/Settings.svelte` の props に
  `slugRename: { remaining: number, nextAvailableAt: string|null }` を追加
- API Resource/DTO: `SlugRenameQuotaDto`（残り回数・次に改名できる時刻）
- テストファイル: `tests/js/pages/OrganizationsSettings.test.ts` の props 更新

### 設計の要点

- **履歴表に一意制約を張らない**（I13）。旧識別名は解放され、他組織が取れる。
  表は `organization_id` / `from_slug` / `to_slug` / `renamed_at` / `renamed_by_user_id` を持つ。
- **最終権威は行ロック後の再判定**（I12）:

```php
public function rename(Organization $organization, AssignableOrganizationSlug $slug, User $actor): void
{
    DB::transaction(function () use ($organization, $slug, $actor): void {
        // 行ロックしてから数える (事前判定は画面表示のための早期拒否にすぎない)
        $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

        $used = OrganizationSlugRename::query()
            ->where('organization_id', $locked->getKey())
            ->where('renamed_at', '>=', CarbonImmutable::now()->subDaysNoOverflow(self::WINDOW_DAYS))
            ->count();

        if ($used >= self::LIMIT) {
            throw new SlugRenameLimitExceededException(/* 次に改名できる時刻 */);
        }

        $from = $locked->slug;
        $locked->forceFill(['slug' => $slug->value])->save();

        OrganizationSlugRename::query()->create([...]);
    });
}
```

- 施行時は全組織が残 5 回から始まる（導入前の改名は遡及計上できない）。
  **緩い側の失敗として許容**し、`docs/` と TODO の備考に明記する
  （テンプレート・aigenba も同じ申し送り）。
- 一意制約違反（他組織が同じ識別名を先に取った）は
  `OrganizationSlugConstraintViolation`（施策 1）で**種別まで識別**して利用者向けエラーへ落とす。
  **識別できない違反は隠さず再送出する**。
- 改名の入力は「新しい識別名」だけで、**どの組織かは URL の binding が決める**
  （AGENTS.md 不変条件 1: tenant キー不信）。
- 認可は `Gate::authorize('update', $organization)`（既存の `OrganizationPolicy`）。
  **層 2（テナント境界 404 = binder）が層 3（認可 403）より前**である既存順序に乗る。

### PHPStan適合チェック

- [x] `SlugRenameQuotaDto` を返す（配列返却なし）
- [x] `firstOrFail()` の戻り値型を明示（`Organization`）
- [x] Carbon の日付演算は `subDaysNoOverflow()` を使う（`CarbonOverflowArithmeticGateTest`）
- [x] 例外は型付き（`SlugRenameLimitExceededException` / `ReservedOrganizationSlugException`）

### テスト計画

- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameTest.php` —
      改名成功で URL が変わる / 旧 URL が 404 になる / 旧識別名を**他組織が取れる**（I13）
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameLimitTest.php` —
      30 日 5 回で 6 回目が拒否される / 30 日を過ぎると回復する /
      **事前判定を通っても行ロック後の再判定で落ちる**競合ケース
- [ ] 新規 `tests/Unit/Services/Organization/OrganizationSlugRenameLimiterTest.php`
- [ ] 新規 `database/factories/OrganizationSlugRenameFactory.php`（新モデルなので必須）
- [ ] `docs/architecture.md` / `docs/factories.md` へ新モデルを追記（AGENTS.md 実装規約）

### リスク

- 改名は**そのユーザーが開いている他タブの URL を即座に無効化**する。
  UI で「改名すると現在の URL は使えなくなる」ことを事前に伝える
  （**disabled にはしない**＝禁止事項 8。押下時に確認ダイアログ）。

---

## 施策 4: 種別フラグ撤去 + 初期組織生成の行ロック冪等判定

満たす不変条件: **I3 / I4 / I5**（I17 / I18 を壊さない）

### 変更箇所

- 変更: `app/Services/Organization/OrganizationProvisioningService.php`
- 新設 migration: `drop_is_personal_from_organizations_table`
- 変更: `app/Models/Organization.php`（`is_personal` の cast / docblock）
- 変更: `app/Filament/Resources/OrganizationResource.php`（列・エントリの撤去）
- 変更: `database/factories/OrganizationFactory.php`（`personal()` state の撤去）
- 変更: `app/Http/Middleware/HandleInertiaRequests.php`（`organizationsProp` の `isPersonal` 撤去）
- 新設: `tests/Architecture/OrganizationProvisioningCallSiteTest.php`

### 波及変更

- **TypeScript型定義**: `resources/js/lib/shared-props.ts` の `isPersonal: boolean` を削除
- **API Resource/DTO**: なし
- **テストファイル**: `is_personal` を参照している 18 箇所（`RegistrationTest` /
  `SocialAuthTest` / `InvitationTest` / `RegisterPlanHandoffTest` / `SignupGrant*Test` /
  `DefaultTeamInvariantTest` / `FakeSocialiteWiringTest` / `RegistrationInvitationPrefillTest`）を
  「所属組織が 1 件ある」の検査へ書き換える
- `resources/js/pages/Organizations/Settings.svelte` の `isPersonal` prop 削除
- `tests/js/pages/OrganizationsSettings.test.ts` の更新

### 現行コード

```php
public function provision(User $creator, string $name, bool $personal = false): Organization
{
    // ...
    $organization->forceFill(['is_personal' => $personal]);
    // ...
    if ($creator->current_organization_id === null) {
        $creator->forceFill(['current_organization_id' => $organization->id])->save();
    }
}

public function provisionPersonalOrganization(User $user): Organization
{
    $existing = $user->organizations()->where('is_personal', true)->first();
    if ($existing !== null) {
        return $existing;
    }

    return $this->provision($user, "{$user->name} の組織", personal: true);
}
```

### 変更後コード

```php
/** 組織 + Laratrust Team + Default Team を原子的に生成し、creator を Owner にする。 */
public function provision(User $creator, string $name, AssignableOrganizationSlug $slug): Organization
{
    return DB::transaction(function () use ($creator, $name, $slug): Organization {
        // ... team / organization / default team / attach / addRole (現行どおり)
        // ★ current_organization_id への書き込みは施策 7 で撤去済み
        return $organization;
    });
}

/**
 * 登録時の初期組織生成 (冪等)。
 *
 * ★冪等判定は「**所属組織が 0 件かどうか**」で行う (家系裁定 AG-038。種別フラグは撤去した)。
 * ★判定はトランザクション内で**利用者の行を取り直して行ロック**し、ロック後のクエリで数える。
 *   呼び出し側が読み込み済みのリレーションに依存しない (登録の同時実行で 2 個できるのを防ぐ)。
 */
public function provisionInitialOrganization(User $user): Organization
{
    return DB::transaction(function () use ($user): Organization {
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        /** @var Organization|null $existing */
        $existing = $locked->organizations()->orderBy('organizations.id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $name = "{$locked->name} の組織";

        return $this->provision($locked, $name, $this->assignableSlugFor($name));
    });
}
```

> **`provision()` の中でも `DB::transaction()` を使う**が、Laravel はネストを savepoint として
> 扱うので原子性は保たれる。外側で行ロックしてから内側の生成が走るため、
> 「ロック後のクエリで所属を数える」順序は守られる。

### 呼び出しサイトの固定（I5）

```
tests/Architecture/OrganizationProvisioningCallSiteTest.php
  - 走査根: Tests\Support\TrackedPhpSourceFiles (git 追跡下 PHP 全数)
  - provisionInitialOrganization() を呼ぶ経路を **完全一致で 2 経路に固定**
    (登録 = CreateNewUser / ソーシャル登録の着地)
  - 行ロック構造 (lockForUpdate → 所属を数える) の構文解析固定
  - 母集団が空なら fail / 負例で検出力を裏取り
```

### PHPStan適合チェック

- [x] `firstOrFail()` の戻り値型を明示（`User` / `Organization`）
- [x] `first()` の `null` 分岐がある
- [x] `provision()` のシグネチャ変更に伴う全呼び出し元の型が合う

### テスト計画

- [ ] 更新 `tests/Feature/Organization/DefaultTeamInvariantTest.php` —
      `is_personal` の検査を「所属組織がちょうど 1 件」へ
- [ ] 新規 `tests/Feature/Organization/InitialOrganizationIdempotencyTest.php` —
      2 回呼んでも組織が 1 つ / **登録失敗時に利用者と初期組織がともに巻き戻る原子性**
- [ ] 新規 `tests/Architecture/OrganizationProvisioningCallSiteTest.php`（負例つき）
- [ ] 更新: `is_personal` を参照する既存テスト 18 箇所

### リスク

- `is_personal` は課金の「個人プラン」判定に使われていないか？ → **実測で使われていない**
  （`resources/js/pages/Onboarding/Checkout.svelte` と `Billing/Plans.svelte` の
  `isPersonal` は `plan.code === "personal"` であり、組織の種別フラグとは無関係）。
  この確認をレビューで再実施することを TODO の備考に書く。

---

## 施策 5: 業務 route の組織 URL 配下への移設

満たす不変条件: **I2**

### 変更箇所

- 変更: `routes/web.php`（約 57 本の移設）
- 変更: `ResolvesCurrentOrganization` を使う 23 クラス
- 変更: `app/Http/Concerns/ResolvesCurrentOrganization.php` → `ResolvesRouteOrganization.php`

### 移設表（prefix `/organizations/{organization:slug}` を付ける）

| 現行 | 移設後 | 本数 |
|---|---|---|
| `dashboard` | `organizations/{organization:slug}/dashboard` | 1 |
| `projects.*`（`projects` 〜 `projects.members.destroy`） | `organizations/{organization:slug}/projects/...` | 25 |
| `capture.*`（prefix `app`） | `organizations/{organization:slug}/app/...` | 12 |
| `billing.*` / `billing.tickets.*` | `organizations/{organization:slug}/billing/...` | 11 |
| `onboarding.checkout` / `onboarding.activate-personal` / `onboarding.billing-required` | `organizations/{organization:slug}/onboarding/...` | 3 |
| `notifications.*` | `organizations/{organization:slug}/notifications/...` | 4 |
| `manage.users.index` | `organizations/{organization:slug}/manage/users` | 1 |

**移設しない（組織文脈を持たない面）**: `home` / `pricing` / `legal.*` / `contact*` / `seo.*` /
`login` / `register` / `password.*` / `settings.*`（利用者個人の設定） / `passkey.*` /
`recent-auth.*` / `session.status` / `invitations.*` / `social.*` / `debug.*` /
`organizations.create` / `organizations.store` / Filament / api / ai。

**`capture.csrf-cookie` と `capture.account`** は組織配下へ移す
（アカウント確認画面は所属組織名を表示するので組織文脈が要る）。
`capture.csrf-cookie` は組織を表示しないが、**PWA の同一 origin セッション再取得のため
組織配下に置く**（PWA が持つのは組織付き URL 1 本だけになる）。

### 現行コード → 変更後コード（controller の型）

```php
// 現行
public function index(Request $request): Response
{
    $organization = $this->resolveMemberCurrentOrganization($request);
    // ...
}

// 変更後 — 組織は route binding が渡す (入力検証より前に解決済み)
public function index(Request $request, Organization $organization): Response
{
    // membership は MembershipScopedOrganizationBinder が解決クエリごとスコープ済み。
    // trait の「current 解決 + 在籍 guard」は消える。
    // ...
}
```

`ResolvesCurrentOrganization` は次のように縮む:

```php
/**
 * URL 整合 guard の helper (改称: ResolvesRouteOrganization)。
 *
 * ★current organization の解決は**存在しない** (家系裁定 AG-037。URL だけが組織を決める)。
 *   組織は route binding (MembershipScopedOrganizationBinder) が routing 層で解決し、
 *   非メンバー・不在は等しく 404 になる。
 * ★残るのは「URL 上の {project} が URL 上の組織に属するか」の 1 本だけである。
 */
trait ResolvesRouteOrganization
{
    private function resolveOrganizationProject(Organization $organization, Project $project): Project
    {
        abort_unless($organization->projects()->whereKey($project->getKey())->exists(), 404);

        return $project;
    }
}
```

### 波及変更

- TypeScript型定義: なし（route helper を使う箇所は施策 6 で扱う）
- API Resource/DTO: なし
- **テストファイル**: 旧 URL / 旧 route 名を使う Feature・Browser テスト全数
  （`rg "route\('projects\." ` / `rg "'/projects/"` 等で棚卸し。施策 10 の gate が漏れを落とす）
- `tests/Support/Routing/NestedRouteDefenseInventory.php` — **全 route 名が変わるため全面更新**。
  `{organization}` param の分類（`ScopedBinder`）を全 route に追加する。
- `.claude/skills/app-bug-hunt/inventory/annotations.toml` — route を足した/変えたら注釈も更新

### PHPStan適合チェック

- [x] controller の第 2 引数 `Organization $organization` の型が明示されている
- [x] `resolveMemberCurrentOrganization()` の呼び出しが 0 件になる（メソッドごと削除）
- [x] 未使用の `use` が残らない

### テスト計画

- [ ] 更新: 移設対象 route を叩く全 Feature テスト（URL とヘルパの書き換え）
- [ ] 新規 `tests/Feature/Routing/OrganizationScopedRouteCoverageTest.php` —
      **業務 route が 1 本残らず `{organization}` param を持つ**ことを deny-by-default で固定
      （移設漏れ・将来の追加漏れを構造的に落とす。母集団が空なら fail）
- [ ] 更新 `tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory
- [ ] 更新 `tests/Architecture/TenantBoundaryOrderingTest.php`
- [ ] 更新 `tests/Architecture/ControllerAuthorizationGateTest.php`（route の増減に追随）

### リスク

- **旧 URL は 404 になる**（転送を置かない）。保証外はリスク欄（末尾）に明記。
- 移設漏れが 1 本でもあると、その route だけ組織を決められず 500 か誤動作になる。
  → `OrganizationScopedRouteCoverageTest` で構造的に落とす。

---

## 施策 6: 組織文脈の binding 由来化

満たす不変条件: **I2**（I1 を壊さない）

### 変更箇所

- 変更: `app/Http/Middleware/HandleInertiaRequests.php`
- 改称・改修: `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php`
  → `EnsureProjectBelongsToRouteOrganization.php`（alias `project.in-route-org`）
- 変更: `bootstrap/app.php`（alias 名と priority list のクラス名）
- 新設: `app/Data/Organization/CurrentOrganizationData.php`（不変 DTO）
- 変更: `resources/js/lib/shared-props.ts` / `AppLayout.svelte` /
  `_helpers/SidebarUserMenu.svelte` / `pages/Capture/Account.svelte`

### 変更後コード

```php
// EnsureProjectBelongsToRouteOrganization
public function handle(Request $request, Closure $next): Response
{
    $project = $request->route('project');
    $organization = $request->route('organization');

    if ($project instanceof Project) {
        // 組織が URL に無いのに {project} がある = 配線ミス。fail-closed で 500
        // (黙って素通しすると cross-org が開く)。
        Assert::isInstanceOf($organization, Organization::class);
        abort_unless($organization->projects()->whereKey($project->getKey())->exists(), 404);
    }

    return $next($request);
}
```

> **priority list の位置は変えない**。`SubstituteBindings` → （API guard） → 本 guard →
> `HandleInertiaRequests` → … の鎖はそのままで、クラス名だけ差し替える。
> `TenantBoundaryOrderingTest` が解決後の middleware 列で固定しているので、
> 名前の差し替えだけでは順序契約は壊れない。

```php
// HandleInertiaRequests
'currentOrganization' => fn (): ?array => $this->currentOrganizationProp($request)?->toArray(),
```

```php
/**
 * 画面へ渡す組織文脈。**URL の binding からのみ導出する** (家系裁定 AG-037)。
 * 組織 route 以外では必ず null になる (「所属している組織のどれか」を裏口から選ばない)。
 */
private function currentOrganizationProp(Request $request): ?CurrentOrganizationData
{
    $organization = $request->route('organization');
    $user = $request->user();
    if (! $organization instanceof Organization || ! $user instanceof User) {
        return null;
    }

    // membership は binder が解決クエリごとスコープ済み。ここでの再検証は不要になった
    // (自己修復つきの解決を消したので「保持列が非所属 org を指す」状態が存在しない)。
    return new CurrentOrganizationData(
        id: $organization->id,
        name: $organization->name,
        slug: $organization->slug,
        role: $user->organizationRole($organization)?->value,
        canManageMembers: $user->can('manageMembers', $organization),
        canManageApiKeys: $user->can('manageApiKeys', $organization),
    );
}
```

### 波及変更

- **TypeScript型定義**: `resources/js/lib/shared-props.ts` —
  `CurrentOrganization` から `isPersonal` を削除（施策 4）。
  `organizations`（所属組織一覧）は**組織切替 UI ではなく分岐画面のリンク用**に用途が変わるので、
  `AppLayout.svelte` の切替 UI（L326 / L427 付近）を削除して**リンク一覧**に置き換える。
- **API Resource/DTO**: `CurrentOrganizationData`（新設）
- **テストファイル**: `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php`（27 箇所）/
  `tests/js/components/templates/AppLayout.test.ts`（17 箇所）/
  `tests/js/pages/CaptureAccount.test.ts` / `tests/Feature/Capture/CaptureAccountScreenTest.php`

### DESIGN.md / Atomic Design 準拠

- 切替 UI の削除と分岐画面の新設（施策 8）は、`atoms → molecules → organisms → templates → pages`
  の単方向 import を守る。組織選択カードは **molecule**（`atoms/Card` + `atoms/Link` の組合せ）に置く。
- 色・角丸・タイポは **DS token 経由**のみ（hex 直書きを増やさない。`ds-purity` テストが検出）。
- アイコンは `@lucide/svelte` のみ（SVG 内包を新設しない）。

### PHPStan適合チェック

- [x] `CurrentOrganizationData` は `readonly` DTO で、配列化は Inertia 境界の `toArray()` 1 か所
- [x] `$request->route('organization')` は `mixed` なので `instanceof` で絞る
- [x] `Assert::isInstanceOf()` で fail-closed（`Webmozart\Assert`）

### テスト計画

- [ ] 更新 `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php` —
      **組織 route 以外では `currentOrganization` が必ず `null`** を追加
- [ ] 新規 `tests/Feature/Security/RouteOrganizationProjectGuardTest.php` —
      cross-org の `{project}` が **FormRequest の DB ルールより前に 404**（422 にならない）
- [ ] 更新 `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` → `ProjectRouteOrgGuardTest`
      （middleware 名の変更 + 「web の `{project}` route は必ず `{organization}` も持つ」を追加）
- [ ] 更新 `tests/js/components/templates/AppLayout.test.ts`

### リスク

- 共有プロパティが `null` になる面（`/settings` 等）でナビが崩れる。
  → 既存の「org なし時 null = 非表示」の分岐がそのまま効く（`AppLayout.svelte` L94-107）。

---

## 施策 7: 保持列・切替 route・自己修復の撤去

満たす不変条件: **I2**

### 変更箇所

- 削除: `app/Http/Controllers/Organizations/OrganizationSwitchController.php`
- 削除: `app/Services/Organization/CurrentOrganizationResolver.php`
- 削除: `tests/Feature/Organization/CurrentOrganizationResolverTest.php` /
  `tests/Feature/Organization/OrganizationSwitchTest.php`
- 変更: `app/Models/User.php`（`currentOrganization()` relation の削除）
- 変更: `routes/web.php`（`organizations.switch` の削除）
- 変更: `app/Providers/AppServiceProvider.php`（render rate limiter のキー）
- 変更: `app/Services/Notification/NotificationCenterService.php`
- 変更: `app/Http/Controllers/NotificationController.php`
- 変更: `app/Http/Controllers/Organizations/OrganizationController.php`（`store` の切替書き込み）
- 変更: `app/Services/Organization/OrganizationMembershipService.php`（4 箇所）
- 変更: `app/Support/Security/MassAssignmentProtectedKeys.php`（キーの削除）
- 変更: `app/Filament/Resources/UserResource.php`（`currentOrganization.name` エントリ）
- 変更: `app/Http/Middleware/RequireActiveSubscription.php`
- 新設 migration: `drop_current_organization_id_from_users_table`

### 各所の置き換え方（実測に基づく）

| 箇所 | 現行 | 変更後 |
|---|---|---|
| `AppServiceProvider::configureRenderRateLimiter` | `$user->current_organization_id` をキーに含める | `$request->route('organization')` の主キー（レンダ route は組織配下になるので必ず在る）。無ければ `'none'` に倒す |
| `NotificationCenterService::notifyAccountDeletionRequested` | 「予約時点の current org」を表示文脈に写す | **退会は組織に属さない事象**であり、URL 文脈も無い。所属組織を `organizations.id` 昇順の先頭で選ぶのは AG-037 の裏口選択になるため**採らない**。→ **アプリ内通知を作らず、メールだけにする**（既存コードも「current org を持たないユーザーには作らない」と書いており、**その分岐を全ユーザーへ広げるだけ**） |
| `NotificationController::belongsToCurrentOrg` | 通知の org 文脈が current org と一致するか | URL 上の組織と一致するか（通知一覧が組織配下になるため） |
| `NotificationController::manualStillExists` | `$user->currentOrganization` から辿る | URL 上の `$organization` から辿る |
| `OrganizationController::store` | 作成後に current へ書き込み | **書き込まず**、`redirect()->route('organizations.settings', $organization)` のみ（作成した組織の URL へ行くので文脈は URL が持つ） |
| `OrganizationMembershipService`（招待受諾） | 受諾成立で current org を招待先へ確定 | **書き込みを削除**。受諾後の遷移先を招待先組織の URL にする |
| `OrganizationMembershipService::removeMember` | current org からの除名時に null 化 | **削除**（列が無い） |
| `OrganizationMembershipService`（退会ブロッカー） | 「現在の組織か」を根拠列とともに返す | **撤去**（判定に使っていないことを実測してから消す） |
| `RequireActiveSubscription` | `$user->currentOrganization` | `$request->route('organization')`。**組織 binding が無ければ fail-closed（500）**にし、「課金ゲート配下の全 route は組織引数を持つ」を Architecture 検査で固定する |
| `UserResource`（Filament） | `currentOrganization.name` | **所属組織の一覧**を表示（`organizations` relation） |

### 波及変更

- **TypeScript型定義**: `AppLayout.svelte` の切替フォーム削除（施策 6 と同時）
- **API Resource/DTO**: なし
- **テストファイル**: `current_organization_id` を参照する Feature テスト 40 ファイル超。
  ほぼすべてが「テスト前提として current を仕込む」ためのものなので、
  **組織付き URL を叩く形へ書き換える**と消える

### 新設する Architecture 検査

```
tests/Architecture/CurrentOrganizationColumnRemovedTest.php
  - 走査根: Tests\Support\TrackedPhpSourceFiles + resources/js + database/
  - `current_organization_id` / `currentOrganization` が
    **撤去 migration 以外に 1 件も無い**ことを固定 (母集団が空なら fail)
  - 負例: 合成入力に 1 件混ぜて検出できること

tests/Architecture/BillingGateRouteOrganizationParamTest.php
  - `require-active-subscription` 配下の全 route が `{organization}` param を持つ
    (route キャッシュとコードの世代ずれでも黙って素通りしない)
```

### PHPStan適合チェック

- [x] `User::currentOrganization()` を消したことで PHPStan が全参照を検出する（widen しない）
- [x] `RequireActiveSubscription` の `Assert::isInstanceOf()` で fail-closed
- [x] `MassAssignmentProtectedKeys` から消したキーが `MassAssignmentSafetyTest` と整合

### テスト計画

- [ ] 新規 `tests/Architecture/CurrentOrganizationColumnRemovedTest.php`（負例つき）
- [ ] 新規 `tests/Architecture/BillingGateRouteOrganizationParamTest.php`
- [ ] 新規 `tests/Feature/Billing/BillingGateWithoutOrganizationBindingTest.php` —
      組織 binding が無い状態で課金ゲートが **fail-closed（500）** になること
- [ ] 削除: `CurrentOrganizationResolverTest` / `OrganizationSwitchTest`
      （**既存テストの削除は禁止事項 3 に当たるが、これは「検査対象そのものの撤去」であり、
      同じ変更で対応する不変条件を新しい検査へ移す**。移送先を上の 2 本 + 施策 6 の
      「組織 route 以外では `currentOrganization` が null」で明示する）
- [ ] 更新: `current_organization_id` を仕込んでいる Feature テスト全数

### リスク

- **退会予約のアプリ内通知が出なくなる**（メールのみ）。
  これは「組織文脈を捏造しない」という既存の設計判断を全ユーザーへ広げたもので、
  AG-037 の裏口選択を避けるための**意図的な機能後退**である。
  `docs/template-divergence.md` の登録と TODO の備考へ明記する。

---

## 施策 8: 組織文脈を持たない入口の分岐 route

満たす不変条件: **I2**（正典 laravel-claude-template T104 が同じ問題へ採った形）

### 変更箇所

- 新設: `app/Http/Controllers/Organizations/OrganizationEntryController.php`
- 新設: `resources/js/pages/Organizations/Choose.svelte`
- 変更: `routes/web.php`（`GET /app` = PWA の `start_url` / `GET /go` = 汎用入口）

### 設計

```php
/**
 * 組織文脈を持たない入口からの分岐 (家系裁定 AG-037 と矛盾しない形)。
 *
 * ★**状態を一切保存しない**。所属が 1 組織ならその組織へ転送、複数なら選ぶ画面、
 *   0 件なら組織作成へ。保持列も切替 endpoint も作らない。
 * ★「所属している組織のどれか」を**自動で選ばない** (複数なら必ず人に選ばせる)。
 *   自動選択は保持列の再発明であり、裁定が禁じている裏口そのものである。
 */
public function __invoke(Request $request): Response|RedirectResponse
{
    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    $organizations = $user->organizations()->orderBy('organizations.name')->get();

    return match (true) {
        $organizations->count() === 1 => redirect()->route($this->target($request), [$organizations->first()]),
        $organizations->isEmpty() => redirect()->route('organizations.create'),
        default => Inertia::render('Organizations/Choose', [...]),
    };
}
```

- **`/app`（PWA の `start_url`）はこの分岐 route にする**。`manifest.webmanifest` の
  `start_url` は `/app` のまま変えない（動的 manifest を作らない＝思考原則 2）。
- 遷移先は入口ごとに違う（`/app` → `capture.home` / `/go` → `dashboard`）。
  `target()` は **route 名の固定表**から選び、query string で受け取らない
  （open redirect を作らない）。

### 波及変更

- TypeScript型定義: `Organizations/Choose.svelte` の props 型（新規）
- API Resource/DTO: なし
- テストファイル: 新規 Feature テスト / 新規 `tests/js/pages/OrganizationsChoose.test.ts`

### PHPStan適合チェック

- [x] `Response|RedirectResponse` の union を明示
- [x] `$organizations->first()` の `null` 分岐が `match` で構造的に消えている
- [x] `Assert::isInstanceOf()` で `?User` を絞る

### テスト計画

- [ ] 新規 `tests/Feature/Organization/OrganizationEntryTest.php` —
      1 組織で転送 / 複数で選択画面 / 0 件で作成画面 / **未ログインは login へ** /
      **遷移先が query string で操作できない**こと（open redirect の負例）
- [ ] 新規 `tests/js/pages/OrganizationsChoose.test.ts`
- [ ] 新規 `tests/Feature/Capture/CaptureStartUrlTest.php` — `start_url` が 200 か 302 を返すこと

### リスク

- 複数組織の利用者は PWA 起動のたびに選択画面を通る。
  **これは仕様**（自動選択は AG-037 の裏口）。ホーム画面に追加した後は
  組織付き URL をブックマークすれば 1 タップになることを画面で案内する。

---

## 施策 9: 機械経路の組織識別子契約（2 段の全数分類）

満たす不変条件: **I14**

### 変更箇所

- 新設: `app/Enums/Security/OrganizationReferenceProvenance.php`（backed enum）
- 新設: `tests/Support/Security/MachinePlaneEntryPoints.php`（第 1 段の母集団抽出器）
- 新設: `tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php`（分類台帳）
- 新設: `tests/Architecture/MachinePlaneOrganizationReferenceTest.php`（gate）
- 新設: `tests/Unit/Architecture/MachinePlaneEntryPointsTest.php`（走査器の自己検査＝負例）

### 2 段の母集団

**第 1 段 — 入口の全数抽出**（組織解決の有無に**かかわらず**全件）:

| 面 | 抽出方法 | fail-closed の条件 |
|---|---|---|
| api / ai | `Route::getRoutes()` から `api/` `ai` 由来の全 action | 母集団が空 |
| console | `Artisan::all()` の全 command + `app/Console/Commands/` の具象クラス | 走査根が不在 / 母集団が空 |
| Filament | 対象 panel に属する **application-defined の構成要素全件**（Resource / Page / RelationManager / Widget / Action …）。**「組織解決を持ち得るもの」で絞らない** | **未知の Filament 構成種別が現れたら fail** |
| MCP tool | `App\Enums\Mcp\ToolName` の全ケース + 実装クラスの突合 | enum と実装の件数不一致 |

**第 2 段 — 入口の中の解決点の全数抽出**:
各入口の中で「組織、または組織に帰属する資源を確定する**すべての解決点**」を抽出し、
**解決点ごとに** provenance を分類する。1 入口に複数あればそれぞれが独立に契約を満たす。

```php
enum OrganizationReferenceProvenance: string
{
    /** route binding の内部主キーだけ (Filament の {record} を含む)。
     *  ★request body / query string の tenant キー受け取りは**この分類では許さない**
     *    (AGENTS.md セキュリティ不変条件 1: tenant キー不信)。 */
    case PrimaryKeyBinding = 'primary_key_binding';

    /** 認証済み credential (API キー / OAuth token / MCP consent) の帰属から確定する
     *  request attribute。利用者入力を経由しない。 */
    case ActorDerived = 'actor_derived';

    /** **信頼済みの親**から tenant-scoped relation だけを辿って確定する。
     *  ★親が PrimaryKeyBinding か ActorDerived で確定していることが条件であり、
     *    親の確定方法が解決できなければ fail-closed で落ちる (再帰的 provenance)。 */
    case RelationScoped = 'relation_scoped';

    /** その入口の解決点が **0 件であることを検査した**もの。理由の記載が必須。
     *  ★「組織を扱わないと申告した」だけでは名乗れない。 */
    case NotOrganizationScoped = 'not_organization_scoped';
}
```

### 保証しないもの（docblock に書く。本書へは写さない）

効くのは 4 本の走査根から抽出できる母集団だけである。実行時に組み立てた文字列で解決する形・
vendor 内部の解決・リポジトリ外の手順には**無言で効かない**。
また **`PrimaryKeyBinding` は「操作してよい組織か」を保証しない** —
認可は `Gate::authorize` と `ControllerAuthorizationGateTest` の担当である。

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 上記 3 本（gate / 抽出器 / 抽出器の自己検査）

### PHPStan適合チェック

- [x] 抽出器は `list<EntryPoint>` を返す（`array` の素返しをしない）
- [x] 未解決を `null` ではなく**未解決だと判別できる結果**で返す（`UnresolvedReference` 型）
- [x] 台帳は `array<string, list<OrganizationReferenceProvenance>>`

### テスト計画

- [ ] 新規 `tests/Architecture/MachinePlaneOrganizationReferenceTest.php` —
      全母集団が完全一致で分類されている / 未登録・余剰・重複で赤 / 走査根が空で赤
- [ ] 新規 `tests/Unit/Architecture/MachinePlaneEntryPointsTest.php` — **負例で両方向**:
      識別名で引く形（`{organization:slug}` を web 以外 / `where('slug', $input)`）・
      表示名で引く形（`where('name', $input)`）・任意文字列で引く形を**検出できる**こと。
      許可 3 種別を**誤検出しない**こと。親の確定方法が不明な `relation_scoped` が**落ちる**こと
- [ ] 既存 `tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest.php` は据え置き
      （`getRouteKeyName()` は `id` のまま。field 無指定 binding が 0 件になったことを追加検査）

### リスク

- Filament の構成種別は vendor の更新で増える。**未知種別で fail-closed** にするので
  vendor 更新時に赤くなる。これは意図した動作（黙って母集団から漏れるより安全）で、
  対処手順を gate の docblock に書く。

---

## 施策 10: 旧 URL の走査根ベース残存検査

満たす不変条件: I2 の帰結（正典が「並走も転送も置かない」と定める形の裏取り）

### 変更箇所

- 新設: `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php`
- 新設: `tests/Support/Architecture/LegacyUrlScanRoots.php`（3 分類の台帳）

### 母集団と 3 分類（排他）

母集団は **git 追跡下ファイル全数**。そこから次の 3 つへ**排他的に**分類し、
**どれにも分類していない置き場所・形式が現れたら赤**にする。

| 分類 | 対象 |
|---|---|
| **走査する** | PHP 全層（`route()` 呼び出しと URL 直書き）/ `resources/js/` / `resources/views/` / `tests/`（Feature / Browser / `tests/js/`）/ `docs/` / `doc/` / ルート直下の `README*` / `public/*.webmanifest` / `public/*.js` / `.claude/skills/app-bug-hunt/inventory/` / 生成テンプレート |
| **走査しない（理由付き）** | バイナリ / `public/build`（生成物）/ `vendor` / `node_modules` / `devnotes`（設計の記録であり実行されない。**本設計ディレクトリの旧 URL 記述は履歴として残してよい**） |
| **未分類** | **1 件でも現れたら赤**（分類を足す変更がレビューで必ず見える） |

検出対象は「組織 prefix を持たない旧パス」（`/projects/` `/billing` `/dashboard` `/notifications`
`/manage/users` `/onboarding/` と、`/app` のうち分岐 route 以外）と、撤去した route 名
（`organizations.switch` / 移設前の `projects.*` 等）。

### 保証外（誇張しない。リスク欄にも書く）

- 利用者のブックマーク・外部サービスに登録済みの URL
- デプロイ時点で既に queue に積まれている / 送信済みのメール本文
- ブラウザの履歴・bfcache・開いたままの旧画面（次の遷移で 404 になる）

### PHPStan適合チェック

- [x] 分類台帳は `array<string, LegacyUrlScanClass>`（enum）
- [x] 未分類は例外で落とす（`null` を返して黙らない）

### テスト計画

- [ ] 新規 `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php` —
      旧 URL の残存 0 件 / 未分類の置き場所 0 件 / 母集団が空なら fail
- [ ] 負例: 合成入力に旧 URL を 1 件混ぜて検出できること・
      新 URL（`/organizations/{slug}/projects/...`）を誤検出しないこと
      （**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を負例に置く＝走査器規約 (e)）

### リスク

- 文書 (`doc/` の設計文書) に旧 URL が大量にある可能性。
  実装時に一括更新するか、**旧 URL を含む文書ファイルを件数完全一致で pin** して
  「増減のどちらでも赤」にする（`route:cache` の説明ファイル pin と同じ形）。

---

## 施策 11: 乖離台帳の更新

### 対象と判定

`docs/template-fingerprints.json` の `entries` キーに**そのパスが在るか**で共有ファイルかを判定した。
本設計が触る 60 ファイル超のうち、**共有ファイルは 2 本だけ**である:

| パス | 共有 | 採用時債務 | 本設計で変更するか | 選ぶ道 |
|---|---|---|---|---|
| `app/Http/Middleware/HandleInertiaRequests.php` | ✅ | ✅（`adoption-debt.tsv` L30） | **する**（施策 6） | **(3) 意図的逸脱として登録を書き、債務から削る** |
| `config/laratrust.php` | ✅ | ✅（同 L47） | **しない**（AG-040 は充足済み） | 触らないので債務のまま |

> 採用時債務一覧に在るパスを変更したまま債務に残す道は無い
> （突合 gate が `mutatedDebtPaths` で落とす）。`HandleInertiaRequests.php` は
> **(1) 採用時の姿へ戻す**（変更するので不可）/ **(2) テンプレートへ同期して債務から削る**
> （aicue 固有の prop が多く同期できない）が採れないため、**(3)** を選ぶ。

### 変更内容

1. **D4 の書き換え**（`docs/template-divergence.md` L214-253）
   - 対象パスを `EnsureProjectBelongsToRouteOrganization.php` →
     `EnsureProjectBelongsToRouteOrganization.php` へ
   - 「テンプレート / 本アプリ」の対比表を「controller の inline guard のみ」vs
     「`project.in-route-org` middleware + inline guard の二重防御」へ更新
   - **再判定の条件**を「web と API v1 で project の解決モデルが 1 つに揃ったとき」から
     「組織の解決が web / API とも routing 層の 1 本に揃ったとき」へ更新
     （AG-037 で web 側は URL binding になったが、API はいまも API キー由来で 2 本立てのため
     **D4 は存続する**）
2. **新規登録 D40**（`HandleInertiaRequests.php` の組織文脈の共有プロパティ）
   - 観点: テンプレートは組織文脈を URL binding から導出する共有プロパティ 1 本。
     本アプリも同じ形にするが、`organizations` / `notifications` / `invitationInbox` /
     `title` / `SessionEpoch` などアプリ固有の prop を多数持つため blob 一致しない
   - 揃え続ける不変条件: **組織 route 以外では `currentOrganization` が必ず `null`**
     （`OrganizationNavSharedPropsTest` が固定）
3. **新規登録 D41**（退会予約のアプリ内通知を作らない＝施策 7 の機能後退）
   - 揃え続ける不変条件: 組織文脈を捏造しない。メールは従来どおり届く
4. **`LedgerPins.php` の更新**
   - `DIVERGENCE_ENTRY_COUNT`: 36 → **38**（D40 / D41 の 2 件追加）
   - `ADOPTION_DEBT_COUNT`: 171 → **170**（`HandleInertiaRequests.php` の 1 行削除）
   - `adoption-debt.tsv` から該当行を削除（**昇順・末尾改行・タブ 2 列**の書式を保つ）

### テスト計画

- [ ] 既存 `tests/Architecture/TemplateDivergence*` 系 gate が緑
- [ ] 登録の宣言行 / 見出しの実数 / `LedgerPins` の 3 点一致
- [ ] `adoption-debt.tsv` のヘッダ（`template_ledger_commit`）が指紋台帳の
      `generated_at_commit` と一致したまま

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | AG-037 は **2 方式の併存を認めない裁定**であり、「保持列を残したまま URL 方式も足す」中間状態を作れない。route 移設・共有プロパティ・middleware・保持列撤去は同じコミット群で完結する必要がある。約 60 ファイル・57 route・テスト 40 ファイル超に触れるため、他 TODO と並走させると衝突が避けられない |
| 競合リスク | **極大**。`routes/web.php` / `bootstrap/app.php` / `HandleInertiaRequests` / `NestedRouteDefenseInventory` は他のほぼ全 TODO と衝突する。実装期間中は他の route 追加・controller 追加を止める（TODO の備考に明記） |
| コミット順序（固定） | 施策 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 |

---

## リスク（全体）

| # | リスク | 影響 | 緩和 |
|---|---|---|---|
| R1 | 旧 URL が 404 になる（転送を置かない） | ブックマーク・共有 URL・送信済みメールが切れる | 正典と思考原則 3 に従う判断。**リポジトリ内**の生成元は施策 10 で 0 件にする。リポジトリ外（ブックマーク / 送信済みメール / ブラウザ履歴）は**保証外**と明記 |
| R2 | 移設漏れの route が 1 本残る | その route だけ組織を決められず誤動作 | `OrganizationScopedRouteCoverageTest`（施策 5）が deny-by-default で落とす |
| R3 | migration が既存データで止まる | デプロイ失敗 | **意図した fail-closed**。事前に人が重複・長さ違反を確認する手順を TODO の備考へ。**エージェントは dev DB を破壊操作しない**（禁止事項 3） |
| R4 | 退会予約のアプリ内通知が消える | 機能後退 | 組織文脈を捏造しないための意図的判断。D41 として登録し、メールは従来どおり |
| R5 | 複数組織の利用者が PWA 起動のたびに選択画面を通る | UX 後退 | 自動選択は AG-037 の裏口なので採らない。組織付き URL のブックマークを画面で案内 |
| R6 | Filament の vendor 更新で施策 9 の gate が赤くなる | CI 停止 | 未知種別 fail-closed は意図した動作。対処手順を gate の docblock に書く |
| R7 | 変更規模が大きく、レビューで見落としが出る | 品質 | 施策ごとにコミットを分け、順序を固定する。各施策に**負例つきの機械検査**を置く |

---

## 使命・禁止事項チェック

- [x] 全施策が使命に寄与する（共用端末の誤組織撮影を構造的に防ぐ＝「思考ゼロ」の前提を守る）
- [x] 禁止事項 1（テストなし完了報告）— 全施策にテスト計画があり、不変条件は Architecture/Feature テストへ登録する
- [x] 禁止事項 2（PHPStan の widen / baseline）— なし。`User::currentOrganization()` の削除で
      PHPStan に全参照を検出させる
- [x] 禁止事項 3（dev DB の破壊操作）— migration の事前確認は**人が行う**手順として書いた
- [x] 禁止事項 4（`response()->json()` 直書き）— 改名 endpoint は Inertia。
      `capture.csrf-cookie` は既存の仕様固定 endpoint（204）で据え置き
- [x] 禁止事項 5 / 6（LLM 経路）— 触れない
- [x] 禁止事項 7（`redirect()->intended()`）— 分岐 route は `redirect()->route()` を使う
- [x] 禁止事項 8（disabled UI）— 改名の回数上限は**押下時にエラー表示**する
- [x] 禁止事項 9（Artifact）— 成果物はすべて `devnotes/` 配下のファイル
- [x] 個別の `DatabaseTransactions` を使わない（`tests/Pest.php` のグローバル適用に従う）
- [x] 新モデル 1 本（`OrganizationSlugRename`）に Factory を作り、
      `docs/architecture.md` / `docs/factories.md` へ追記する


---

## 関連する現行コード

### `app/Services/Organization/OrganizationProvisioningService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Models\CustomTeam;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 組織生成の唯一の窓口。あらゆる経路 (画面 / 登録 / シーダー / Factory) がここを通ることで
 * Default Team パターンの不変条件「どの Organization にも Default Team がちょうど 1 つ」を
 * 構造的に担保する (docs/default-team-pattern.md)。
 */
class OrganizationProvisioningService
{
    /**
     * 組織 + Laratrust Team + Default Team を原子的に生成し、creator を Owner にする。
     */
    public function provision(User $creator, string $name, bool $personal = false): Organization
    {
        return DB::transaction(function () use ($creator, $name, $personal): Organization {
            $team = new Team;
            $team->name = 'org-'.Str::lower(Str::random(12));
            $team->display_name = $name;
            $team->save();

            $organization = new Organization([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
            ]);
            $organization->laratrustTeam()->associate($team);
            $organization->forceFill(['is_personal' => $personal]);
            $organization->save();

            // Default Team (不変条件: 組織ごとにちょうど 1 つ。is_default は $fillable 外)
            $defaultTeam = new CustomTeam(['name' => $name]);
            $defaultTeam->organization()->associate($organization);
            $defaultTeam->forceFill(['is_default' => true]);
            $defaultTeam->save();

            $organization->users()->attach($creator);
            $creator->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);

            if ($creator->current_organization_id === null) {
                $creator->forceFill(['current_organization_id' => $organization->id])->save();
            }

            return $organization;
        });
    }

    /**
     * 登録時の個人用組織生成 (冪等: 既に個人組織を持っていれば no-op)。
     */
    public function provisionPersonalOrganization(User $user): Organization
    {
        /** @var Organization|null $existing */
        $existing = $user->organizations()->where('is_personal', true)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->provision($user, "{$user->name} の組織", personal: true);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';

        return $base.'-'.Str::lower(Str::random(6));
    }
}

```

### `app/Services/Organization/CurrentOrganizationResolver.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Webmozart\Assert\Assert;

/**
 * current organization の「所属再確認つき」解決 + 自己修復 (概念設計 表示組織の解決規則)。
 *
 * removeMember は current org からの除名時に current_organization_id を null 化するが
 * 「選び直す」実装は本 Service が初出。v1 の呼び出し元は DashboardController のみ
 * (他画面への展開は後続。ResolvesCurrentOrganization trait は従来どおり null=404)。
 *
 * 競合契約 (概念レビュー Round 2-4 で確定):
 * - 表示の安全性は「読み出し時の所属再確認」で担保する。current が指す org は常に
 *   pivot relation で所属を再確認してから返す = 非所属 org (dangling) を描画に出さない
 * - 書き込みは best-effort の冪等修復。単一の条件付き UPDATE
 *   (current IS NULL または観測した dangling 値のまま、かつ所属 pivot が存続) のみ
 * - UPDATE 成否によらず fresh 再取得 → 所属再確認 1 回のみ。解決不能なら null (無限再試行しない)
 */
class CurrentOrganizationResolver
{
    /** 表示組織を解決する。null = 所属組織 0 件 (または競合で解決不能) */
    public function resolve(User $user): ?Organization
    {
        // 1. current の所属再確認つき読み出し (dangling は null 扱いに倒す)
        $current = $this->membershipVerified($user, $user->current_organization_id);
        if ($current !== null) {
            return $current;
        }

        // 2. 自己修復: 決定的候補 (organizations.id 昇順の先頭)
        $observed = $user->current_organization_id; // null または dangling 値
        $candidateId = $user->organizations()->orderBy('organizations.id')->value('organizations.id');
        if ($candidateId === null) {
            return null; // 所属 0 件 → setup 表示
        }
        Assert::integerish($candidateId);

        $this->heal($user, $observed, (int) $candidateId);

        // 3. 成否によらず relation キャッシュ破棄 + fresh 再取得 → 所属再確認 (1 回のみ)
        $user->refresh();

        return $this->membershipVerified($user, $user->current_organization_id);
    }

    /**
     * 原子的条件付き UPDATE による自己修復 (内部 API。テストが競合分岐を直接固定できる seam)。
     * 観測値のまま + 所属存続のときのみ設定:
     * - 除名 tx が先に commit していれば whereHas (EXISTS) が偽 → 0 件更新 = 修復しない
     * - 観測後に別 org へ変更済みなら WHERE 不一致 → 上書きしない
     *
     * current_organization_id は保護キーだが、この UPDATE は fillable を経由しない
     * サーバ導出のみの書き込み (payload 値は一切使わない)。
     *
     * @return int 更新行数 (0 = 競合により不発。正常系の一種)
     */
    public function heal(User $user, ?int $observed, int $candidateId): int
    {
        $updated = User::query()
            ->whereKey($user->getKey())
            ->where(function (Builder $query) use ($observed): void {
                $query->whereNull('current_organization_id');
                if ($observed !== null) {
                    $query->orWhere('current_organization_id', $observed);
                }
            })
            ->whereHas('organizations', fn (Builder $query) => $query->whereKey($candidateId))
            ->update(['current_organization_id' => $candidateId]);

        // 監査ログ (GET 内の自己修復を追跡可能にする)。更新 0 件は正常な競合のため
        // debug に落としログ量を抑える (詳細レビュー Round 2 対応)
        Log::log($updated > 0 ? 'info' : 'debug', 'current organization self-heal', [
            'user_id' => $user->getKey(),
            'observed' => $observed,
            'candidate' => $candidateId,
            'updated_rows' => $updated,
        ]);

        return $updated;
    }

    /** 所属再確認つき読み出し (pivot relation 経由 = cross-org を構造的に排除) */
    private function membershipVerified(User $user, ?int $organizationId): ?Organization
    {
        if ($organizationId === null) {
            return null;
        }

        /** @var Organization|null */
        return $user->organizations()->whereKey($organizationId)->first();
    }
}

```

### `app/Http/Concerns/ResolvesCurrentOrganization.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * current organization 解決 + URL 整合 guard の helper 集。
 * 「/projects/...」「/billing」等、URL に org セグメントを含めない current org スコープの
 * ルートで使う。ユーザーの current_organization_id を解決し、未設定なら 404
 * (存在しないリソースとして扱い、組織の有無を露出しない)。
 *
 * 組織管理系ルート (/organizations/{organization:slug}/...) は current に依存せず、
 * MembershipScopedOrganizationBinder の route binding で org を解決する (本 trait 不使用)。
 */
trait ResolvesCurrentOrganization
{
    private function resolveCurrentOrganization(Request $request): Organization
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $user->currentOrganization;
        abort_if($organization === null, 404);

        return $organization;
    }

    /**
     * current org 解決 + 在籍 guard。current org が未設定なら 404、解決できても
     * **ユーザーがその org に非所属なら 404** (`current_organization_id` が退会後も
     * 残存する不整合を、**認可より前に** 存在しないリソースとして落とす = 不変条件 #2。
     * 403 で org の存在を漏らさない)。
     *
     * 組織 route (`/organizations/{organization:slug}/...`) では
     * MembershipScopedOrganizationBinder の route binding がこの層を担う。本メソッドは
     * その責務を current-org スコープ (URL に org セグメントを持たない route) へ写した受け皿。
     */
    private function resolveMemberCurrentOrganization(Request $request): Organization
    {
        $organization = $this->resolveCurrentOrganization($request);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        abort_unless(
            $organization->users()->whereKey($user->getKey())->exists(),
            404,
        );

        return $organization;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が current org に属さなければ
     * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
     * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
     *
     * web の {project} route では EnsureProjectBelongsToRouteOrganization middleware
     * (project.in-route-org) が本 guard を FormRequest の DB ルールより**前**にも実行する
     * (422/404 差分の存在オラクル防止)。controller 内の呼び出しは二重防御として維持する。
     */
    private function resolveOrganizationProject(Organization $organization, Project $project): Project
    {
        abort_unless(
            $organization->projects()->whereKey($project->getKey())->exists(),
            404,
        );

        return $project;
    }
}

```

### `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * web の `{project}` route の URL 整合 guard (middleware 層)。alias: project.in-route-org。
 *
 * cross-org の {project} を「FormRequest の DB ルールを含むあらゆるアプリコードより前に 404」
 * へ構造的に落とす。controller の inline guard (resolveOrganizationProject) は認可より前の
 * 404 を担うが、FormRequest のバリデーションは controller メソッド解決時 = inline guard より
 * **前**に走るため、project スコープの DB ルール (categories.name の unique / category の
 * exists 等) が cross-org プロジェクトに対する 422/404 差分の存在オラクルになる。
 * middleware は FormRequest 解決より前 (SubstituteBindings の後) に走るため、
 * この順序ハザードを route group 単位で構造的に閉じる。
 *
 * 適用境界:
 *  - routes/web.php の業務 route group (require-active-subscription とセット) に付与する。
 *    {project} param を持たない route では no-op (group 一括付与を許容し、将来の
 *    project 配下 route 追加時の guard 漏れを防ぐ)。
 *  - 網羅性は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する
 *    (web の {project} route は必ず本 middleware を持つ / API は持たない)。
 *  - API v1 は org を API キーから確定する別レイヤー (ResolvesApiOrganization) の責務のため
 *    対象外 (web セッションの current org 前提の本 middleware を付けてはならない)。
 *  - controller の inline guard は二重防御として残す (oauthSessions の controller 内再検査と
 *    同じ位置づけ。middleware の付け漏れ・withoutMiddleware への最終防衛線)。
 */
class EnsureProjectBelongsToRouteOrganization
{
    use ResolvesCurrentOrganization;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            $organization = $this->resolveCurrentOrganization($request);
            $this->resolveOrganizationProject($organization, $project);
        }

        return $next($request);
    }
}

```

### `app/Http/Controllers/Organizations/OrganizationSwitchController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * 組織切替 (users.current_organization_id の更新)。
 * `{organization}` は MembershipScopedOrganizationBinder が membership スコープで解決するため、
 * 所属していない組織・不在 id は等しく 404 (存在の有無は開示しない)。
 */
class OrganizationSwitchController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return redirect()->route('dashboard')->with('success', "「{$organization->name}」に切り替えました");
    }
}

```

### `app/Http/Middleware/HandleInertiaRequests.php` (L150-220)

```php
    }

    /**
     * 現在の組織 + 自分のロール + ナビ表示に必要な最小権限フラグ。
     * 権限は currentOrganization ($organization) を対象に評価し、OrganizationPolicy を
     * 唯一の真実源とする (role 直見しない)。Policy は organizationRole($organization)
     * = laratrust_team_id を明示した strict_check 判定を経由するため、別組織で付与された
     * 権限は現在組織へ漏れない (cross-org 分離。テストで固定)。
     * slug は organizations.settings / organizations.api-keys.index ({organization:slug}
     * バインド) への恒常リンク生成に必須。
     * defense-in-depth: current_organization_id が万一 (データドリフト等で) 非所属 org を
     * 指した場合に slug/name を露出しないよう、isMemberOf で membership を再検証して null に倒す。
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     role: string|null,
     *     canManageMembers: bool,
     *     canManageApiKeys: bool
     * }|null
     */
    private function currentOrganizationProp(?User $user): ?array
    {
        $organization = $user?->currentOrganization;
        if ($user === null || $organization === null) {
            return null;
        }

        // cross-org 防御: current が非所属 org を指していたら共有しない (存在秘匿)。
        if (! $user->isMemberOf($organization)) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'role' => $user->organizationRole($organization)?->value,
            // ナビ表示用の最小権限 (settings/billing は view=メンバー全員のためフラグ不要)。
            // billing 画面内の操作出し分けは既存 canManageBilling prop が担うため shared には載せない。
            'canManageMembers' => $user->can('manageMembers', $organization),
            'canManageApiKeys' => $user->can('manageApiKeys', $organization),
        ];
    }
}
```

### `app/Providers/AppServiceProvider.php` (L388-408)

```php
    }

    /**
     * レンダ/プレビュートリガー (POST .../render, .../preview) の RateLimiter。
     * preview はチケット非消費のため、この rate limit + org 同時 preview 上限
     * (RenderJobService::triggerPreview) の 2 段が無料 ffmpeg 実行の負荷上限を構造的に決める
     * (概念設計 §2 の abuse 耐性契約)。キーは user id + org id 単位。
     */
    private function configureRenderRateLimiter(): void
    {
        RateLimiter::for('render-trigger', function (Request $request): Limit {
            $user = $request->user();
            $userId = $user instanceof User ? (string) $user->id : 'guest';
            $orgId = $user instanceof User && $user->current_organization_id !== null
                ? (string) $user->current_organization_id
                : 'none';

            return Limit::perMinute(6)->by("render-trigger:actor-org:{$userId}:{$orgId}");
        });
    }

```

### `app/Services/Notification/NotificationCenterService.php` (L155-190)

```php
        });
    }

    /**
     * 退会予約 (猶予期間つき削除) の気づき通知。
     *
     * ★呼び出し位置は **予約の書き込みと同一 tx 内** (他の発火とは違う)。予約が rollback したら
     *   通知も残らないのが正しい状態であるため。
     * ★アプリ内通知は org 文脈を必須とする (`AppNotification::organizationId()` が
     *   non-nullable)。退会は組織に属さない事象なので、**予約時点の current org** を表示文脈として
     *   写す。current org を持たないユーザーには**作らない** (メールだけが届く。
     *   org 文脈を捏造しない)。
     */
    public function notifyAccountDeletionRequested(User $user, CarbonImmutable $purgeAfter): void
    {
        $this->safely(function () use ($user, $purgeAfter): void {
            $organizationId = $user->current_organization_id;
            if (! is_int($organizationId)) {
                return;
            }

            $state = AccountDeletionStateDto::fromUser($user);
            $graceDays = $state->graceDays();
            if ($graceDays === null) {
                return; // 予約が成立していない (呼び出し順の異常) ときは作らない
            }

            $user->notify(new AccountDeletionRequestedNotification(
                $organizationId,
                new AccountDeletionRequestedPayload($purgeAfter->toIso8601String(), $graceDays),
            ));
        });
    }

    // ── 読み出し・既読化 (NotificationController から委譲) ────────────────────

```

### `app/Http/Controllers/NotificationController.php` (L136-165)

```php
        return $user;
    }

    /** 通知の org 文脈 (organization_id 列) が current org と一致するか (認可判断ではない) */
    private function belongsToCurrentOrg(User $user, NotificationListItemData $item): bool
    {
        return $item->organizationId !== null
            && $item->organizationId === $user->current_organization_id;
    }

    /**
     * current org → projects() → manuals の relation 連鎖による存在解決 (exists() 1 クエリ。
     * 認可判断なし = 「認可より前の 404」層の再利用)。
     */
    private function manualStillExists(User $user, NotificationListItemData $item): bool
    {
        $organization = $user->currentOrganization;
        if ($organization === null) {
            return false;
        }

        return $organization->projects()
            ->whereKey($item->projectId())
            ->whereHas('manuals', fn (Builder $query): Builder => $query->whereKey($item->manualId()))
            ->exists();
    }
}
```

### `app/Http/Controllers/Organizations/OrganizationController.php` (L36-56)

```php
    }

    /** 新規組織作成 → provisioning (Default Team 込み) → 作成した組織へ切替 */
    public function store(StoreOrganizationRequest $request, OrganizationProvisioningService $provisioning): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $name = $request->validated('name');
        Assert::string($name);

        $organization = $provisioning->provision($user, $name);

        // 作成した組織を current にして設定画面へ (作成直後の文脈を維持する)
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return redirect()->route('organizations.settings', $organization)->with('success', '組織を作成しました');
    }

    /**
     * 組織設定画面 (name 編集 / 2FA 必須方針 / オーナー移譲)。
```

### `bootstrap/app.php` (L255-300)

```php
         | 下の web 鎖はそのための宣言であり、順序は §唯一の順序契約 と一致する。
         | 実測は TenantBoundaryOrderingTest が解決後の middleware 列で固定する。
         */
        $middleware->appendToPriorityList(
            SubstituteBindings::class,
            EnsureProjectBelongsToApiOrganization::class,
        );
        $middleware->appendToPriorityList(
            EnsureProjectBelongsToApiOrganization::class,
            EnsureProjectBelongsToRouteOrganization::class,
        );
        // テナント guard より後に走ることを確定させる web グループの鎖
        // (guard を binding 直後まで引き上げるための「後続」宣言)。
        foreach ([
            [EnsureProjectBelongsToRouteOrganization::class, HandleInertiaRequests::class],
            [HandleInertiaRequests::class, SecurityHeaders::class],
            [SecurityHeaders::class, RequireTwoFactorForEnforcedOrganizations::class],
            [RequireTwoFactorForEnforcedOrganizations::class, BlockTwoFactorDisableForEnforcedOrganizations::class],
            [BlockTwoFactorDisableForEnforcedOrganizations::class, NoStoreCacheHeadersForAuthenticatedPages::class],
            [NoStoreCacheHeadersForAuthenticatedPages::class, IssueSessionEpochCookie::class],
            [IssueSessionEpochCookie::class, EncryptHistory::class],
            [EncryptHistory::class, EnsureEmailIsVerified::class],
            [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
            // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
            // (EnsureProjectBelongsToRouteOrganization) より必ず後に置く。前に置くと
            // 「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
            // (AGENTS.md セキュリティ不変条件 10)。課金ゲートの直後に置き、未契約組織の
            // ユーザーは 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
            [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
            // bug-hunt の記録器は鎖の最後 (遮断 middleware より内側) に固定する。
            // 「短絡しうる middleware はすべて記録器より前」は
            // BughuntExecutedRouteOrderingTest が deny-by-default で強制する。
            [EnsureAccountNotPendingDeletion::class, BughuntExecutedRouteMiddleware::class],
        ] as [$after, $append]) {
            $middleware->appendToPriorityList($after, $append);
        }
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveApiActor::class,
        );

        /*
         | bug-hunt の記録器より前で走ることを確定させる「route 個別の短絡 middleware」。
         |
         | web グループの middleware は route 個別 middleware より**前**に並ぶため、
         | priority list に載っていない route 個別の短絡 (recent-auth / signed / guest 等) は
```

### `routes/web.php` (L245-360)

```php
    | スコープして解決する。非メンバー・不在 slug/id は等しく 404 (テナント存在秘匿)。
    | same-org の権限不足 403 は従来どおり Policy の責務。
    */
    Route::get('/organizations/create', [OrganizationController::class, 'create'])
        ->name('organizations.create');
    // 未認証時は /email/verify への沈黙 302 ではなく back + error flash で戻す (verified.or-back)。
    // group の 'verified' を route 単位で外し (将来の group middleware 追加で取りこぼさないため
    // group 外出しではなく withoutMiddleware で override)、verified.or-back を個別付与する。
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->withoutMiddleware('verified')
        ->middleware('verified.or-back:organization-store')
        ->name('organizations.store');
    // 切替は field 無指定 (= id) binding。非所属組織は binder が 404 に倒す
    Route::post('/organizations/{organization}/switch', [OrganizationSwitchController::class, 'store'])
        ->name('organizations.switch');
    Route::get('/organizations/{organization:slug}/settings', [OrganizationController::class, 'settings'])
        ->name('organizations.settings');
    Route::patch('/organizations/{organization:slug}', [OrganizationController::class, 'update'])
        ->name('organizations.update');
    // 招待送信も未認証時は back + error flash で戻す (verified.or-back)。organizations.store と
    // 同様に group の 'verified' を route 単位で外し verified.or-back を個別付与する。
    Route::post('/organizations/{organization:slug}/invitations', [OrganizationInvitationController::class, 'store'])
        ->withoutMiddleware('verified')
        ->middleware('verified.or-back:invite')
        ->name('organizations.invitations.store');
    // 招待取り消し (論理失効)。{invitation} は scopeBindings で $organization->invitations()
    // 経由に解決され、組織を跨ぐ取り消しは認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
    Route::delete('/organizations/{organization:slug}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])
        ->scopeBindings()
        ->name('organizations.invitations.revoke');
    /*
    | {user} は scopeBindings で $organization->users() 経由に解決する。
    | 非メンバー / 不在 id は **binding 段で等しく 404** になり、recent-auth (302) を含む
    | binding 後のどの短絡 middleware よりも前に閉じる (audit-cycle-2 High-1 横断)。
    | implicit binding のままだと「不在 = binding 404 / 実在の非メンバー = 後段短絡の 302」と
    | 分岐し、users.id の存在オラクルになっていた。
    | controller の inline guard (resolveOrganizationMember) は二重防御として残す。
    | 親 {organization:slug} は MembershipScopedOrganizationBinder が引き続き担当する
    | (scopeBindings は子解決のみに作用)。
    */
    Route::scopeBindings()->group(function (): void {
        Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
            ->name('organizations.members.update');
        Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
            ->name('organizations.members.destroy');
        // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須)
        Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
            ->middleware('recent-auth')
            ->name('organizations.members.two-factor.reset');
    });
    // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
    Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
        ->middleware('recent-auth')
        ->name('organizations.two-factor-requirement.update');
    // オーナー移譲は step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/transfer-ownership', [OrganizationOwnershipController::class, 'store'])
        ->middleware('recent-auth')
        ->name('organizations.transfer-ownership');

    /*
    | 管理メニュー (doc/04 §4.2 管理者専用)。ユーザー管理は org メンバー管理の専用画面
    | (書き込みは既存 organizations.* endpoint)。/admin/* は Filament panel が占有するため /manage/*。
    | org 管理系として課金ゲート外 (未契約でもメンバー整理可能 = organizations.members.* と整合)。
    | /manage/ 配下の route は auth+verified 必須 (ManageRouteAuthGuardTest が deny-by-default で強制)。
    */
    Route::get('/manage/users', [UserManagementController::class, 'index'])
        ->name('manage.users.index');

    /*
    | API キー (org スコープ。manageApiKeys = owner / admin)。
    | 平文キーは発行直後の flash 経由 1 度きり表示。{apiKey} は scopeBindings で
    | $organization->apiKeys() 経由の解決 (不整合は認可より前に 404。
    | NestedRouteIdorDefenseTest 登録済み)。
    */
    // 一覧 (専用画面) は閲覧のみのため recent-auth 不要
    Route::get('/organizations/{organization:slug}/api-keys', [OrganizationApiKeyController::class, 'index'])
        ->name('organizations.api-keys.index');
    // 発行 / 失効はいずれも step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/api-keys', [OrganizationApiKeyController::class, 'store'])
        ->middleware('recent-auth')
        ->name('organizations.api-keys.store');

    /*
    | OAuth セッション (CLI/MCP 接続) の組織管理経路。境界は API キー管理と同一
    | (OauthSessionPolicy::manageForOrganization = owner / admin または直接付与メンバー)。
    | 一覧は接続セッションタブ (ApiKeys/Sessions)。sessions の GET は revoke ({oauthSession}) の
    | 前に定義し、静的セグメント 'sessions' が wildcard に食われないようにする。
    */
    Route::get('/organizations/{organization:slug}/api-keys/sessions', [OrganizationOauthSessionController::class, 'index'])
        ->name('organizations.api-keys.sessions.index');
    Route::scopeBindings()->group(function (): void {
        Route::delete('/organizations/{organization:slug}/api-keys/sessions/{oauthSession}', [OrganizationOauthSessionController::class, 'destroy'])
            ->middleware('recent-auth')
            ->name('organizations.api-keys.sessions.revoke');
    });

    // {apiKey} wildcard の revoke は静的セグメント (sessions) の後に定義する。
    // {apiKey} は scopeBindings で $organization->apiKeys() 経由の解決 (不整合は認可より前に 404。
    // NestedRouteIdorDefenseTest 登録済み)。
    Route::scopeBindings()->group(function (): void {
        Route::delete('/organizations/{organization:slug}/api-keys/{apiKey}', [OrganizationApiKeyController::class, 'destroy'])
            ->middleware('recent-auth')
            ->name('organizations.api-keys.revoke');
    });

    /*
    | MCP / CLI 導入オンボーディング (組織メンバーなら閲覧可)。endpoint / 設定 JSON は
    | SnippetBuilder が config('app.url') / config('template.slug') から生成する。
    */
    Route::get('/organizations/{organization:slug}/onboarding/mcp', [OrganizationOnboardingController::class, 'mcp'])
        ->name('organizations.onboarding.mcp');
    Route::get('/organizations/{organization:slug}/onboarding/cli', [OrganizationOnboardingController::class, 'cli'])
        ->name('organizations.onboarding.cli');

    /*
    | 課金 (current org スコープ)。新規契約は Stripe Checkout、契約中プランの変更は
```

### `routes/web.php` (L460-530)

```php
        | {project} の URL 整合 guard ({project} ∈ current org) は 2 層:
        | (1) project.in-route-org middleware — cross-org を 404 に落とす (存在オラクル防止)。
        |     **実行位置は宣言順ではなく bootstrap/app.php の priority list が正本**で、
        |     SubstituteBindings の**直後** = 課金ゲート 302・verified 302・2FA 強制 302・
        |     Inertia version mismatch 409・FormRequest の DB ルールより前に走る。
        |     間に 404 以外で短絡する middleware が入ると「他組織に実在 = その短絡の応答 /
        |     不在 = 404」の存在オラクルが復活する (audit-cycle-2 High-1)。
        |     {project} を持たない route では no-op のため group 一括付与。
        |     網羅性は ProjectRouteCurrentOrgGuardTest、順序は TenantBoundaryOrderingTest が固定
        | (2) controller の inline guard (resolveOrganizationProject) — 二重防御
        */
        Route::get('/projects', [ProjectController::class, 'index'])
            ->name('projects.index');
        Route::get('/projects/create', [ProjectController::class, 'create'])
            ->name('projects.create');
        Route::post('/projects', [ProjectController::class, 'store'])
            ->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->name('projects.show');
        Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])
            ->name('projects.edit');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])
            ->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->name('projects.destroy');

        // Item (Project 配下サンプルリソース): nested route。一覧は projects.show が担う。
        // {item} は scopeBindings で $project->items() 経由の解決 (子→親不整合は認可より前に 404。
        // NestedRouteIdorDefenseTest 登録済み)
        Route::post('/projects/{project}/items', [ItemController::class, 'store'])
            ->name('projects.items.store');
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
                ->name('projects.items.update');
            Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
                ->name('projects.items.destroy');
        });

        // Category (Project 配下の動画マニュアル分類・編集者のみ)。一覧は projects.show が内包する。
        // reorder は {category} を取らない ({project} のみ = 1 param) ため IDOR inventory 対象外。
        // {category} は scopeBindings で $project->categories() 経由の解決
        // (子→親不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録済み)
        // カテゴリ管理画面 (管理メニュー。一覧表示のみ。write は下記既存 route)
        Route::get('/projects/{project}/categories', [CategoryController::class, 'index'])
            ->name('projects.categories.index');
        Route::post('/projects/{project}/categories', [CategoryController::class, 'store'])
            ->name('projects.categories.store');
        Route::patch('/projects/{project}/categories/reorder', [CategoryController::class, 'reorder'])
            ->name('projects.categories.reorder');
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/categories/{category}', [CategoryController::class, 'update'])
                ->name('projects.categories.update');
            Route::delete('/projects/{project}/categories/{category}', [CategoryController::class, 'destroy'])
                ->name('projects.categories.destroy');
        });

        // VideoManual (Project 配下の動画マニュアル)。一覧は projects.show が内包する。
        // {manual} は scopeBindings で $project->manuals() 経由の解決
        // (子→親不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録済み)
        Route::get('/projects/{project}/manuals/create', [VideoManualController::class, 'create'])
            ->name('projects.manuals.create');
        Route::post('/projects/{project}/manuals', [VideoManualController::class, 'store'])
            ->name('projects.manuals.store');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
                ->name('projects.manuals.show');
            Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
                ->name('projects.manuals.edit');
            Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
                ->name('projects.manuals.update');
            // シナリオ document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
```

### `tests/Support/Routing/NestedRouteDefenseInventory.php` (L30-72)

```php
final class NestedRouteDefenseInventory
{
    /**
     * route 名 => (parameter 名 => 防御方式)。
     *
     * @return array<string, array<string, NestedRouteDefenseMode>>
     */
    public static function inventory(): array
    {
        $scoped = NestedRouteDefenseMode::ScopeBindings;
        $binder = NestedRouteDefenseMode::ScopedBinder;
        $tenant = NestedRouteDefenseMode::TenantGuardMiddleware;
        $manual = NestedRouteDefenseMode::ManualOwnerScopedResolution;
        $nonRes = NestedRouteDefenseMode::NonResourceParameter;

        // {project} は web/API とも テナント guard middleware が binding 直後に走る (T108 S2)
        $project = ['project' => $tenant];

        return [
            // --- REST API v1 ---
            'api.v1.projects.show' => $project,
            'api.v1.projects.items.index' => $project,
            'api.v1.projects.items.store' => $project,
            // {item} は $project->items() 経由 (scopeBindings)
            'api.v1.projects.items.update' => [...$project, 'item' => $scoped],
            'api.v1.projects.items.destroy' => [...$project, 'item' => $scoped],

            // --- 撮影 PWA (/app/*。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut}) ---
            'capture.manuals.index' => $project,
            'capture.manuals.show' => [...$project, 'manual' => $scoped],
            'capture.takes.upload-url' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
            'capture.takes.store' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
            'capture.takes.update' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.destroy' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.adopt' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.downloaded' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.playback' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.thumbnail' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],

            // --- 業務 route (web) ---
            'projects.show' => $project,
            'projects.edit' => $project,
            'projects.update' => $project,
```

### `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` (L1-78)

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\ResolveApiActor;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * `{project}` を受ける route は URL 整合 guard を **middleware 層**に必ず持つ invariant。
 *
 * cross-org の {project} は「FormRequest の DB ルール (unique/exists) を含む
 * あらゆるアプリコードより前に 404」でなければならない (存在オラクル防止)。
 * controller の inline guard (resolveOrganizationProject) は認可より前の 404 を担うが、
 * FormRequest のバリデーションは controller メソッド解決時 (= inline guard より前) に走るため、
 * middleware 層の guard が無いと「cross-org の実在 project + 不正 payload = 422 /
 * 不在 project = 404」の差分がクロステナントの存在オラクルになる (T001 / T103 レビュー指摘)。
 * 本テストは deny-by-default で「{project} を受ける route に middleware が付いていること」を
 * 機械検証し、将来の route 追加での guard 漏れを構造的に落とす。
 *
 * 組織の解決元が違うため middleware は web / API で 2 本立てになる:
 *  - web (`project.in-route-org` = EnsureProjectBelongsToRouteOrganization):
 *    セッションの current org。API に付けてはならない (API はセッションを持たない)
 *  - API v1 (`api.project-in-org` = EnsureProjectBelongsToApiOrganization):
 *    API キー / OAuth token から確定した request attribute 'organization'
 */

test('web の {project} route は project.in-route-org / API は api.project-in-org を必ず持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('project', $route->parameterNames(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();

        if (str_starts_with($route->uri(), 'api/')) {
            // API は web セッション (current org) を持たない。誤配線は全 API project route を
            // 404 に落とすため、付いていたら fail させる
            if (in_array('project.in-route-org', $middleware, true)) {
                $violations[] = "API route {$name} に web セッション前提の project.in-route-org が付いている";
            }
            // API 版の URL 整合 guard は必須 (FormRequest より前に cross-org を 404 に落とす)
            if (! in_array('api.project-in-org', $middleware, true)) {
                $violations[] = "API route {$name} に api.project-in-org middleware が無い"
                    .' (cross-org {project} が FormRequest より前に 404 になりません)';
            }
            $checked++;

            continue;
        }

        if (! in_array('project.in-route-org', $middleware, true)) {
            $violations[] = "web route {$name} に project.in-route-org middleware が無い"
                .' (cross-org {project} が FormRequest の DB ルールより前に 404 になりません)';
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    // route が 1 本も検査されない (= {project} route が消えた/リネームされた) 場合も fail させ、
    // テスト自体の空振り drift を検知する
    expect($checked)->toBeGreaterThan(0);
});

/*
 * API の middleware 順序契約 (docblock ではなく機械で固定する):
 *
 *   resolve.api-actor  <  SubstituteBindings  <  api.project-in-org  <  api-key.ability:*  <  idempotent
 *
 * | 破られる契約 | 起きること |
```

### `tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest.php` (L1-55)

```php
<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| org-boundary-404 invariant: `{organization}` route param は web ルート (auth + web group) 専用
|--------------------------------------------------------------------------
|
| MembershipScopedOrganizationBinder (AppServiceProvider で Route::bind 登録) は
| Auth::guard('web') = session guard に依存してテナント存在秘匿 (非メンバー 404) を担う。
| web 以外 (api / ai / webhooks / Filament) で `{organization}` param 名を使うと session
| guard 不在の binding が誤適用され、常時 404 などの誤動作になる。web 以外では別名 param
| (例: orgSlug) を使うこと。
*/

it('web 以外の route は {organization} param を使わない', function (): void {
    // binder は Auth::guard('web') 固定のため、session guard 以外 (auth:api-key 等) は
    // invariant 違反として落とす。許可するのは素の `auth` (default = web) と `auth:web` のみ。
    $hasWebSessionAuth = static function (RoutingRoute $route): bool {
        $middleware = array_filter($route->gatherMiddleware(), 'is_string');

        return in_array('web', $middleware, true)
            && (in_array('auth', $middleware, true) || in_array('auth:web', $middleware, true));
    };

    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('organization', $route->parameterNames(), true))
        ->reject($hasWebSessionAuth);

    expect($offenders->map->uri()->values()->all())->toBe(
        [],
        '{organization} param は MembershipScopedOrganizationBinder (web session guard 依存) が'
        .'適用されるため web + auth ルート専用。web 以外では別名 param (orgSlug 等) を使うこと: '
        .$offenders->map->uri()->implode(', '),
    );
});

it('default auth guard は web (binder の Auth::guard("web") 前提)', function (): void {
    // 素の `auth` middleware を web guard とみなす上記 invariant の前提を pin する
    expect(config('auth.defaults.guard'))->toBe('web');
});

it('Organization の routeKeyName は id (field 無指定 binding = id 解決の前提)', function (): void {
    // binder は `{organization}` (field 無指定 = organizations.switch 等) を
    // getRouteKeyName() で解決する。routeKeyName が slug 等に変わると id binding 前提が静かに崩れる。
    expect((new Organization)->getRouteKeyName())->toBe('id');
});
```
