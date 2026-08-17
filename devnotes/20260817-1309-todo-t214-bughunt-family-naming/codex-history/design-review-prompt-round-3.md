# Round 3: Round 2 指摘への対応と再レビュー依頼

Round 2 の指摘 (Critical 1 / Warning 4 / Suggestion 2) にすべて対応しました。反論・見送りは 0 件です。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED** (Critical 1 / Warning 4 / Suggestion 2)。
**すべてに対応した** (反論・見送りは 0 件)。

## [Critical] 施策 4: `placementExceptions()` の理由文字列を伸ばすと A-6b が必ず不合格になる
- 判断: **対応する**
- 根拠: 完全に正しい。理由はコメントではなく**文字列リテラル**なので `token_get_all()` の
  実行トークンとして残る。逆置換しても旧内容と一致せず、A-6b が許す 2 差分に収まらない。
  設計内で自分の受け入れ条件を破っていた。
- 対応内容: 理由の文字列を**改名前と 1 文字も変えない**形へ戻した (クラス名の置換のみ)。
  「名前の規則では拾えないため参照走査の候補として残す」という説明は **docblock (コメント) だけ**に
  置き、包含そのものは 4-3 の明示 assertion が機械で固定する。
  変更後コードの直後に「理由の文字列は 1 文字も変えない」ことを明記した。

## [Warning] 施策 1: 件数 pin が旧名ごとになっておらず、内訳の入れ替えを検出できない
- 判断: **対応する**
- 根拠: 正しい。合計 2 件の比較では「片方 1 → 0 / 他方 1 → 2」が緑のまま通る。
- 対応内容: pin を `array<string, array<string, int>>` (ファイル → 旧名 → 件数) に改め、
  実測値 (`BughuntBillingSeeder` 1 件 / `FakeExternalsServiceProvider` 2 件) を登録した。
  述語も旧名ごとの比較に書き直し、N-4 の負のコントロールへ
  「合計は同じだが内訳が違う入力」を追加した (Codex の提案どおり)。

## [Warning] 「保証しないもの」の記述が新しい件数 pin と矛盾している
- 判断: **対応する**
- 根拠: 正しい。件数 pin を入れた時点で `docs/TODO-closed.md` は「沈黙する」場所ではなくなった。
- 対応内容: 表の 1 行を 3 行へ分けた —
  `docs/TODO-closed.md` (旧名ごとの件数を pin。増減も内訳の入れ替えも検出する) /
  `devnotes/` (丸ごと除外。再流入に沈黙する) / 本検査ファイル自身 (自己除外。沈黙する)。

## [Warning] A-6a / A-6b の実行手段が一意に定義されていない (手作業では機械検証と言えない)
- 判断: **対応する**
- 根拠: 正しい。母集団の導出・改名前後のパス対応・「2 か所」の判定境界・取りこぼしの
  fail-closed が未定義だった。
- 対応内容: 「A-6 の検証手順」節を新設し、**再実行できる一時スクリプト**
  (`devnotes/…/verify-rename-only.php`) の処理を 5 段で定義した —
  (1) `git diff --name-status -M main...HEAD` から母集団と改名対応表を作る (削除があれば即不合格)、
  (2) 各ファイルを A-6a / A-6b / A-6c の**どれか 1 つだけ**に分類し、
      未分類・分類表にあるのに差分に現れないものはすべて不合格、
  (3) A-6a はバイト比較、
  (4) A-6b はコメント・空白を落としたトークン列の比較で、**許可する追加トークン列 2 つと完全一致**、
      かつ**削除側のトークンは 0 個**、
  (5) 落ちた全件を出力する。
  受け入れ条件に **A-6d** (このスクリプトが終了コード 0) を追加し、
  出力を `rename-verification.md` に残すことにした。
  一時スクリプトは devnotes 配下に置き、`scripts/` へは昇格させない (AGENTS.md の運用)。

## [Warning] A-5 の `git log --follow` は「`git mv` を使ったこと」の証明にならない
- 判断: **対応する**
- 根拠: 正しい。git は改名操作を記録せず、内容の類似度から推定する。
- 対応内容: A-5 を「改名前の履歴が **rename 検出によって**追跡できること」へ書き直し、
  `git mv` を使うことは**実装手順**として残して受け入れ条件から分離した。

## [Suggestion] 「振る舞い完全不変」は保証範囲を少し超えている
- 判断: **対応する**
- 根拠: 正しい。逆置換の一致が示すのはソース上の差分の性質までである。
- 対応内容: A-6a の見出しを「**意図しない実行コード差分が無い**」へ改め、
  A-6 の節末と「保証しないもの」の表に、振る舞いの同値性は証明しない (担保は既存テストと
  `composer test` 全体) と明記した。

## [Suggestion] `devnotes/` の丸ごと除外は妥当 / 施策 4 の Critical は閉じている
- 判断: **対応不要** (追認)

---

## 修正後の詳細設計書 (全文)

# 詳細設計: bug-hunt ランタイム配線の名前を家系へ揃える (aicue:T214)

> 本ディレクトリ名に含まれる `t214` は TODO 番号 aicue:T214 を指す。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **本施策の使命への関係は間接である**。利用者に見える振る舞いは 1 つも変わらない。効くのは、
> bug-hunt の走行が本物の外部サービスへ届かないことを守る安全網の**維持コスト**である。

### 禁止事項 (AGENTS.md の転記。本施策に直結するものへ注記を付す)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
   → **本施策の不変条件「旧名は戻らない」は施策 4 の Architecture テストで登録する**
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

あわせて**思考原則 3「後方互換の並走を残さない。書き換えると決めたら同じ PR で旧実装を消す」**が
本施策の核である (旧名のクラスを別名として残さない)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。解析対象は `app` / `config` / `database` / `routes`
  であり、**`tests` は対象外**である (改名する provider と seeder は解析対象、新設する検査は対象外)。
- **Pest**。`RefreshDatabase` は `tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止。
- `declare(strict_types=1)` + 日本語コメント。
- コードフォーマット: `vendor/bin/pint`。
- PHP 8.4 + Laravel 12。`App\` / `Database\Seeders\` は PSR-4 なので、ファイル名とクラス名を
  同時に変えれば autoload は追従する (classmap ではない)。

## 目的と台帳の根拠

| 項目 | 内容 |
|---|---|
| 機能 (lctl) | `bughunt-runtime` (bug-hunt ランタイム配線)。feature_revision 22-8c25a7989875 を読んだ |
| aicue の状態 | **update_pending** / assessment: divergence_candidate (観測点 aicue@bac558f) |
| 裁定 | **AG-085** (2026-08-06) — 「同じ関心事に名前が 2 つある状態を続けると、追従判断のたびに『これは欠落か別名か』の確認が発生する」を理由に名前の統一を求めた。**2026-08-10 の裁定でファイル数の統合 (AG-042 / AG-085) は撤回**され、**残る要件は「同一の関心事には家系で 1 つの名前を割り当てる」だけ**になっている |
| 直近の前進 | aicue:T177 (aicue@4d3007a) が投入配線の検査を新設し、欠落側は解消済み。同報告が「提供元クラスの名前は独自のままである。家系への改名は本件の範囲外とした」と申し送っている |
| 家系からの申し送り | aigenba:T1154 の報告 —「改名の残留検査は文書も見るべきで、**その走査範囲を設計時に決めること**」。実際に aigenba では残留検査がマージ後に旧名を検出して仕事をした |

### 家系名の確定 (推測していないことの根拠)

| 関心事 | 家系名 | 台帳での実測 |
|---|---|---|
| 決済側の投入 seeder | `BughuntStripeSyncSeeder` | gates に `tests/Feature/Bughunt/BughuntStripeSyncSeederTest.php` が 5 リポジトリで実在。「持たないのは aicue のみで、同じ位置に別名の aicue:tests/Feature/Database/BughuntBillingSeederTest.php が在る」。aigenba セルは `database/seeders/BughuntStripeSyncSeeder.php` を、metamovics セルは同名を実測記録している |
| 偽の外部サービスの配線 provider | `BughuntFakesServiceProvider` | aigenba セル「旧名 `app/Providers/DuskFakesServiceProvider.php` から `app/Providers/BughuntFakesServiceProvider.php` へ改名され (旧名は HEAD に不在)」。metamovics セルにも `app/Providers/BughuntFakesServiceProvider.php` が実在 |

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t214-bughunt-family-naming/conceptual-design.md` (Codex 概念レビュー
Round 1 で APPROVED。Warning 2 件の対応は同ディレクトリの
`codex-history/conceptual-review-decisions-round-1.md`)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 旧名の残留検査を先に置いて赤にする | `tests/Architecture/BughuntNamingResidualTest.php` (新規) | 最初にやる |
| 2 | 決済側 seeder を家系名へ改名する | `database/seeders/BughuntBillingSeeder.php` → `BughuntStripeSyncSeeder.php`、`tests/Feature/Database/BughuntBillingSeederTest.php` → `BughuntStripeSyncSeederTest.php`、参照 8 ファイル | 高 |
| 3 | 配線 provider を家系名へ改名する | `app/Providers/FakeExternalsServiceProvider.php` → `BughuntFakesServiceProvider.php`、`tests/Feature/Providers/FakeExternalsServiceProviderTest.php` → `BughuntFakesServiceProviderTest.php`、参照 21 ファイル | 高 |
| 4 | 命名規則から外れることの帰結を埋める (参照走査の候補集合) | `tests/Support/ExternalFakes/FakeClassCatalog.php`、`tests/Architecture/FakeClassReferenceInvariantTest.php`、`tests/Architecture/ExternalFakeWiringInvariantTest.php` | 高 (施策 3 と不可分) |

## 変更ファイル一覧

### 新規 (1)

| ファイル | 内容 |
|---|---|
| `tests/Architecture/BughuntNamingResidualTest.php` | 旧名 2 つが追跡下から消えたことと、戻らないことを固定する |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php` | A-6 の判定を再実行できる形にする**一時スクリプト** (恒久化しない。`scripts/` へは昇格させない) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md` | 上の実行結果 (出力そのまま) |

### 改名 (4。`git mv` で履歴を繋ぐ)

| 変更前 | 変更後 |
|---|---|
| `database/seeders/BughuntBillingSeeder.php` | `database/seeders/BughuntStripeSyncSeeder.php` |
| `tests/Feature/Database/BughuntBillingSeederTest.php` | `tests/Feature/Database/BughuntStripeSyncSeederTest.php` |
| `app/Providers/FakeExternalsServiceProvider.php` | `app/Providers/BughuntFakesServiceProvider.php` |
| `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` | `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` |

### 変更 (29)

うち **26 ファイルは名前の置き換えだけ** (受け入れ条件 A-6a)。
残る 3 ファイル (`tests/Support/ExternalFakes/FakeClassCatalog.php` /
`tests/Architecture/FakeClassReferenceInvariantTest.php` /
`tests/Architecture/ExternalFakeWiringInvariantTest.php`) は、
**改名で縮む検査の網を元の広さに戻すための更新**を伴う (施策 4。受け入れ条件 A-6b)。

| 層 | ファイル | 出現数 |
|---|---|---|
| 起動 | `bootstrap/providers.php` | 2 |
| 実行スクリプト | `scripts/bug-hunt-shard.sh` (`cmd_provision` / `cmd_reseed` の投入列) | 2 |
| 本番コード (コメントのみ) | `app/Providers/AppServiceProvider.php` (2) / `app/Support/ExternalFakes/ExternalFakeDeclaration.php` (2) / `app/Support/FakeStorageGate.php` (1) / `app/Services/Billing/TicketLedgerService.php` (2) / `app/Services/Billing/Fakes/FakeStripeGateway.php` (3) / `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` (1) / `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` (1) / `app/Services/AI/Testing/CannedPromptFake.php` (1) / `CannedPromptFakeRegistrar.php` (1) / `CannedPromptResponses.php` (1) | 15 |
| 目録 (aicue:T177 の資産) | `tests/Support/Bughunt/BughuntSeedWiringInventory.php` | 3 |
| 検査 | `tests/Architecture/ExternalFakeWiringInvariantTest.php` (16) / `FakeClassReferenceInvariantTest.php` (6) / `LaneExternalFakeBindingTest.php` (1) | 23 |
| 検査の支援 | `tests/Support/ExternalFakes/FakeClassCatalog.php` (3) / `FakeWiringSourceScanner.php` (1) | 4 |
| レーン配線 | `tests/Pest.php` | 3 |
| 他のテスト | `tests/Feature/Auth/FakeSocialiteWiringTest.php` (3) / `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` (2) / `tests/Feature/Billing/TicketCheckoutTest.php` (1) / `TicketBalanceAccountingTest.php` (1) / `AutoRechargeStripeCallBudgetTest.php` (1) / `tests/Feature/Llm/CannedAnalysisPipelineTest.php` (1) | 9 |
| 文書 | `docs/architecture.md` (2) / `docs/testing-browser.md` (1) | 3 |
| 環境ひな型 | `.env.bughunt.local.example` (説明コメント) | 2 |

改名する 4 ファイル自身の中の出現 (seeder 本体 5 / provider 本体 1 / seeder テスト 7 /
provider テスト 9 = 22) を足すと、**追跡下・devnotes 以外の全出現は 90 箇所**である
(うち `docs/TODO-closed.md` の 2 箇所は後述のとおり触らない)。

### 削除 (0)

旧名のクラスは**別名として残さない** (思考原則 3)。移行期間・後方互換の別名は作らない。

## 施策 1: 旧名の残留検査を先に置いて赤にする

### 変更箇所

- 新規ファイル: `tests/Architecture/BughuntNamingResidualTest.php`

### 設計

家の既存作法 (`tests/Architecture/RouteCacheExemptionPremiseTest.php` の追跡下全ファイル走査 +
自己除外 + 件数 pin、`ForbiddenStatementTokenInvariantTest` の「走査する / 例外 / 除外 (理由必須)」の
3 分類) をそのまま踏襲する。**置き場所は 3 分類で排他的に扱う** (Codex 概念レビュー Warning と
詳細レビュー Round 1 Warning への対応)。

| 分類 | 対象 | 扱い |
|---|---|---|
| (a) 走査する | 追跡下の残り全ファイル | 旧名の出現は 1 件でも赤 |
| (b) 件数を完全一致で pin する | `docs/TODO-closed.md` (**旧名ごとに** `BughuntBillingSeeder` 1 件 / `FakeExternalsServiceProvider` 2 件。実測値) | 増えても減っても赤。合計ではなく**旧名ごと**に固定するので、片方を減らして他方を増やす書き換えも赤になる。丸ごと除外にすると将来の再流入に沈黙するため、粒度を落とさない |
| (c) 丸ごと除外する (理由必須) | `devnotes/` (接頭辞) と本テスト自身 | 前者は過去の設計・レビュー・走行記録が 190 ファイル規模で旧名を含み、件数 pin が実務にならない。後者は検出したい語を負のコントロールの入力として持つため |

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * 家系 (lctl の機能 bughunt-runtime) で 1 つに決まっている名前が、旧名へ戻らないことの固定。
 *
 * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
 * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
 * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
 *
 * ★保証範囲を誇張しない:
 *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
 *     動的に組み立てた文字列には**沈黙する**。
 *   - 除外した置き場所の中では旧名に沈黙する (下の 3 分類の (b)(c))。
 *   - 家系名が「正しい名前であること」は検査できない。正本は台帳であり、
 *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 退役した名前 → 家系の名前。
 *
 * 出典は lctl の機能 bughunt-runtime (aigenba / metamovics / laravel-claude-template の実測)。
 *
 * @var array<string, string>
 */
const BUGHUNT_RETIRED_NAMES = [
    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
];

/**
 * (b) 旧名を持つことが確認済みの**過去の記録**と、**旧名ごとの**件数。
 *
 * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` /
 * `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS` と同じ作法)。丸ごと除外にしないのは、
 * 除外したファイルの中で将来 旧名が再流入しても沈黙してしまうためである。
 *
 * ★合計ではなく**旧名ごと**に固定する。合計だけを見ると「片方を 1 件減らして
 *   もう片方を 1 件増やす」書き換えが緑のまま通る (Codex 詳細レビュー Round 2 の指摘)。
 *
 * - `docs/TODO-closed.md`: 完了した TODO の記録。aicue:T015 / aicue:T119 が当時作った
 *   クラスの名前は当時の事実であり、書き換えると記録が嘘になる。
 *   ★aicue:T214 のクローズ記録が旧名に触れると本 pin は赤くなる。そのときは
 *     「記録を書き換える」のではなく **pin の数を同じ変更の中で更新する** (意図的な摩擦)。
 *
 * @var array<string, array<string, int>>
 */
const BUGHUNT_NAMING_KNOWN_MENTIONS = [
    'docs/TODO-closed.md' => [
        'BughuntBillingSeeder' => 1,
        'FakeExternalsServiceProvider' => 2,
    ],
];

/**
 * (c) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
 *
 * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、件数 pin が
 * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
 * 除外するのと同じ扱い)。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];

/**
 * (c) 丸ごと走査から外す唯一のファイル = 本テスト自身。
 *
 * 検出したい語を負のコントロールの入力として持つため、自分を走査すると必ず自分で赤くなる。
 * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
 */
const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';

/** 走査の母集団が空振りでないことを確かめる代表パス (改名後に実在するもの) */
const BUGHUNT_NAMING_SENTINEL_PATHS = [
    'bootstrap/providers.php',
    'scripts/bug-hunt-shard.sh',
    'database/seeders/BughuntStripeSyncSeeder.php',
    'app/Providers/BughuntFakesServiceProvider.php',
];

/** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;

/**
 * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
 *
 * @return list<string>
 */
function bughuntNamingViolationsIn(string $relative, string $content): array
{
    if ($relative === BUGHUNT_NAMING_SELF_PATH) {
        return [];
    }

    foreach (BUGHUNT_NAMING_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return [];
        }
    }

    // 過去の記録は「0 件」ではなく「pin した件数ちょうど」を旧名ごとに要求する。
    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];

    $violations = [];
    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        $count = substr_count($content, $retired);
        $allowed = $pinned[$retired] ?? 0;

        if ($count === $allowed) {
            continue;
        }

        $violations[] = $allowed === 0
            ? "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})"
            : "{$relative}: {$retired} の出現が {$count} 箇所 (pin は {$allowed} 箇所)";
    }

    return $violations;
}
```

テスト本体 (要旨):

| # | 名前 | 内容 |
|---|---|---|
| N-1 | 追跡下の現役資産に旧名が 1 つも残っておらず、過去の記録は pin した件数ちょうどである | 追跡下の全ファイルを `bughuntNamingViolationsIn()` に通して `[]` |
| N-2 | fail-closed | 母集団が下限以上、かつ代表パスを全部含む (`git ls-files` が失敗したら**空を返さず例外**) |
| N-3 | 走査の外し方が意図どおり | 丸ごと除外の**定義**が 2 つちょうど (接頭辞 `devnotes/` 1 件 + 自分自身 1 件)、件数 pin の**定義**が 1 ファイル分ちょうど (`docs/TODO-closed.md` の旧名 2 種)。ファイル数ではなく定義の数を pin する意味であることをテスト名に書く |
| N-4 | 負のコントロール | 同じ述語が (a) `app/Foo.php` に旧名を書いた入力を検出し、(b) `devnotes/…` の入力は検出せず、(c) 自分自身のパスの入力も検出せず、(d) pin したファイルで**件数がずれた**入力 (1 件でも 3 件でも) を検出し、(e) pin したファイルで**合計は同じだが内訳が違う**入力 (片方 0 件 / 他方 3 件) も検出する |
| N-5 | 旧名のクラスが**存在しない** / 家系名のクラスが存在する | `class_exists()` で両方向を確かめる (後方互換の別名を残していないことの機械化) |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイルが新規テストである

### PHPStan 適合チェック

- [x] 解析対象外 (`tests` は `phpstan.neon` の paths に無い)。ただし型注記は既存 Architecture
      テストと同じ水準で書く (`@return list<string>` 等)
- [x] `git` が使えない環境では空を返さず例外にする (fail-open 防止)

### テスト計画

- [x] **これ自体が最初に赤になるテストである** (施策 2/3 の前に置く)
- [x] 負のコントロール (N-4) を同じ述語で通す

### リスク

- 走査は字面のみ。旧名を分割・連結する書き方には沈黙する (docblock に明記する)。
- `devnotes/` を丸ごと除外するため、**設計記録の中では旧名の再流入に沈黙する**。
  ここは過去の記録の置き場所であり、現役の資産ではないので受け入れる (穴として docblock に明記する)。
- `docs/TODO-closed.md` は件数 pin なので、**aicue:T214 のクローズ記録が旧名に触れると赤くなる**。
  そのときの正しい直し方は「記録を書き換える」ではなく「pin の数を同じ変更で更新する」である
  (実装セッションへの申し送りとして docblock に書く)。

## 施策 2: 決済側 seeder を家系名へ改名する

### 変更箇所

- `database/seeders/BughuntBillingSeeder.php` (L42 のクラス宣言、L56 / L65 / L83 / L106 の
  出力文言) → `database/seeders/BughuntStripeSyncSeeder.php`
- `tests/Feature/Database/BughuntBillingSeederTest.php` (L9 の import、L13 の docblock、
  L47 / L58 / L71 / L73 / L96 の `$this->seed(...)`) → `tests/Feature/Database/BughuntStripeSyncSeederTest.php`
- `tests/Support/Bughunt/BughuntSeedWiringInventory.php` (L8 import / L50 目録キー /
  L53 `guardPremiseTest` のパス)
- `scripts/bug-hunt-shard.sh` (L1121 = `cmd_provision`、L1347 = `cmd_reseed` の
  `db:seed --class=` の値)
- `app/Services/Billing/TicketLedgerService.php` (L405 / L822 のコメント)
- `app/Services/Billing/Fakes/FakeStripeGateway.php` (L18 / L48 / L55 のコメント)
- `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` (L19 のコメント)
- `tests/Feature/Billing/TicketBalanceAccountingTest.php` (L164 のコメント)
- `.env.bughunt.local.example` (L69 の説明コメント)

### 現行コード (抜粋)

```php
// database/seeders/BughuntBillingSeeder.php:42
class BughuntBillingSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;
```

```php
// tests/Support/Bughunt/BughuntSeedWiringInventory.php:50
BughuntBillingSeeder::class => [
    'role' => BughuntSeedRole::BughuntOnly,
    'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
    'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
],
```

```bash
# scripts/bug-hunt-shard.sh:1121 / 1347
artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
```

### 変更後コード (抜粋)

```php
// database/seeders/BughuntStripeSyncSeeder.php:42
class BughuntStripeSyncSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;
```

```php
// tests/Support/Bughunt/BughuntSeedWiringInventory.php:50
BughuntStripeSyncSeeder::class => [
    'role' => BughuntSeedRole::BughuntOnly,
    'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
    'guardPremiseTest' => 'tests/Feature/Database/BughuntStripeSyncSeederTest.php',
],
```

```bash
artisan_for_shard "${db}" "${url}" db:seed --class=BughuntStripeSyncSeeder --force
```

**変えないもの**: 区分 (`BughuntSeedRole::BughuntOnly`)・理由文・投入列の**順序**・
三重ガードの条件と論理・冪等キー (`bughunt:initial-grant:{id}`)・
固定の購読 ID (`sub_bughunt_{id}`)・付与枚数 (100)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 上記のとおり (改名 1 + 参照 2)
- **DB マイグレーション: なし** (seeder の名前はデータに現れない)

### PHPStan 適合チェック

- [x] 戻り値の型は既存のまま (`run(TicketLedgerService $tickets): void` 等)
- [x] `Plan` / `Organization` の generics は既存のまま
- [x] PSR-4 のためファイル名とクラス名を同時に変える (不一致は autoload エラーになる)

### テスト計画

- [x] 既存 `tests/Feature/Database/BughuntStripeSyncSeederTest.php` (改名) がそのまま緑
- [x] `BughuntSeedWiringInvariantTest` の **S-1** (目録のキー集合 = `database/seeders` の
      Seeder クラス集合) が、改名とクラス名の追従を**機械的に強制する**
- [x] 同 **S-3 / S-4** が `cmd_provision` / `cmd_reseed` の投入列との一致を強制する
      (スクリプト側の書き換え漏れは必ず赤になる)
- [x] 同 **S-9** が `guardPremiseTest` の実在と「対象 seeder の名前を含むこと」を強制する
      (テストファイル名の追従漏れも赤になる)
- [x] 個別の `DatabaseTransactions` は使っていない (既存どおり)

### リスク

- **投入列の書き換え漏れ**が最大のリスク (漏れると bug-hunt 環境で購読が入らず、
  業務経路が探索できなくなる)。S-3 / S-4 が機械で止める。
- seeder が出す `warn` / `info` の文言は先頭にクラス名を含むため、**CLI 出力の文字列は変わる**
  (「振る舞い」ではないが観測可能な差分である)。これは意図した追従であり、受け入れ条件 A-6a の
  逆置換でバイト一致することによって「名前以外は変えていない」ことが機械で確かめられる。
- 走行中の bug-hunt 環境が旧名で `db:seed` を呼ぶ手順書を持っていないかは実測済み
  (`.claude/skills/` に旧名の出現は 0 件)。

## 施策 3: 配線 provider を家系名へ改名する

### 変更箇所

- `app/Providers/FakeExternalsServiceProvider.php` (L37 のクラス宣言) →
  `app/Providers/BughuntFakesServiceProvider.php`
- `bootstrap/providers.php` (L7 の `use`、L31 の登録)
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` →
  `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` (import と 7 箇所の実体化)
- `tests/Pest.php` (L14 の `use`、`enableFakeStorage()` / `enableFakeExternals()` の実体化)
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` (16 箇所: `use`、
  M3 / M4 の被覆表の説明文、`externalFakeWiringProviderSource()` が渡すパス、
  3-5 / 3-6 / 3-7 / 3-11 等の実体化とテスト名)
- `tests/Architecture/FakeClassReferenceInvariantTest.php` (施策 4 と合わせて対応)
- `tests/Architecture/LaneExternalFakeBindingTest.php` (docblock 1 箇所)
- `tests/Support/ExternalFakes/FakeClassCatalog.php` (施策 4 と合わせて対応)
- `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` (docblock 1 箇所)
- `tests/Feature/Auth/FakeSocialiteWiringTest.php` / `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` /
  `tests/Feature/Billing/TicketCheckoutTest.php` / `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` /
  `tests/Feature/Llm/CannedAnalysisPipelineTest.php`
- `app/Providers/AppServiceProvider.php` (L130 / L134 のコメント) /
  `app/Support/ExternalFakes/ExternalFakeDeclaration.php` (L31 / L35 のコメント) /
  `app/Support/FakeStorageGate.php` (L13) / `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` (L14) /
  `app/Services/AI/Testing/CannedPromptFake.php` (L25) / `CannedPromptFakeRegistrar.php` (L15) /
  `CannedPromptResponses.php` (L22)
- `docs/architecture.md` (L1686 / L1717) / `docs/testing-browser.md` (L160) /
  `.env.bughunt.local.example` (L68)

### 現行コード / 変更後コード (抜粋)

```php
// bootstrap/providers.php (現行)
use App\Providers\FakeExternalsServiceProvider;
…
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    FakeExternalsServiceProvider::class,
```

```php
// bootstrap/providers.php (変更後)
use App\Providers\BughuntFakesServiceProvider;
…
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    BughuntFakesServiceProvider::class,
```

**変えないもの**: `bootstrap/providers.php` 内の**登録順** (`AppServiceProvider` より後 =
後勝ち rebind)、`register()` / `boot()` の中身、capability flag 名
(`ExternalFakeDeclaration::EXTERNALS_FLAG` = `testing.fake_externals`)、環境 allowlist、
`FakeStorageGate` の判定、署名付き経路の名前 (`bughunt.storage.put` / `bughunt.storage.get`)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- **Inertia Props: なし** (provider は container 配線のみで画面へ出ない)
- テストファイル: 上記のとおり

### PHPStan 適合チェック

- [x] `app/` 配下なので解析対象。クラス名の変更のみで型は不変
- [x] `bootstrap/providers.php` は解析対象外だが、`use` と登録の両方を直さないと起動時に落ちる
      (Feature テスト全体が赤になるため見逃せない)

### テスト計画

- [x] `ExternalFakeWiringInvariantTest`
      **3-5** (`bootstrap/providers.php` に登録されている) /
      **3-6** (`AppServiceProvider` より後) /
      **3-7** (起動済み container にロード済み) /
      **3-9** (container 呼び出しの形) / **3-10** (provider が参照する fake 系クラスは
      配線基盤 4 件ちょうど) がすべて緑であること
- [x] `FakeClassReferenceInvariantTest` 4-1〜4-4 (施策 4 参照)
- [x] `LaneExternalFakeBindingTest` (レーンからの直結禁止) が緑
- [x] 改名した `BughuntFakesServiceProviderTest` が緑 (register / boot の 8 test)
- [x] `tests/Pest.php` の `enableFakeExternals()` / `enableFakeStorage()` を使う既存テスト群
      (課金・captcha・SSO・保存先) が緑

### リスク

- **`bootstrap/providers.php` の追従漏れ** = 偽物が立たず bug-hunt が本物の外部サービスへ届く。
  これは本 feature が台帳で共有されている理由そのものであり、3-5 / 3-6 / 3-7 が機械で止める。
- `bootstrap/cache/` 配下の生成物 (`services.php` / `packages.php` / `config.php` /
  `routes-*.php` 等) はすべて**追跡外の生成物**であり、`.gitignore` 済み・
  `FakeClassCatalog::EXCLUDED_PREFIXES` でも走査から外れている。provider 一覧が変わると
  Laravel 自身が manifest を作り直すため手当ては要らない。それでも詰まった場合の復旧は
  `php artisan optimize:clear` である。

## 施策 4: 命名規則から外れることの帰結を埋める (参照走査の候補集合)

### 背景 (この施策が必要な理由)

`FakeClassCatalog` は fake 系クラスの母集団を**ハードコードせずに導出**している。

- 定義 1「fake 実装クラス」= `app/**/Fakes/` か `app/**/Testing/` 配下の全クラス
- 定義 2「fake 命名クラス」= クラス名が `Fake` で始まる or `Fake` で終わるクラス

現在の `FakeExternalsServiceProvider` は**定義 2 に当たる**ため、`placementExceptions()`
(定義 2 のうち定義 1 に属さなくてよい例外) に登録されている。
`FakeClassReferenceInvariantTest` の **4-3 (本番コードは fake クラスを参照しない)** は、
候補集合を `implementationClasses() ∪ placementExceptions() のキー` としており、
その docblock は「配置例外を業務コードが参照しても検出できず**偽グリーン**になるため」と
理由を書いている。

**改名すると `BughuntFakesServiceProvider` は定義 2 に当たらなくなる** (先頭も末尾も `Fake` でない)。
帰結は 2 つある。

1. `placementExceptions()` から entry を落とすと、**配線 provider が 4-3 の候補集合から消え、
   業務コードが配線点を直接参照しても検出できなくなる** = docblock が名指しで警告している
   偽グリーンを自分で作ることになる。したがって **entry は残し、docblock の意味を書き直す**。
2. `ExternalFakeWiringInvariantTest` の **3-10** (provider が参照する fake 系クラスは配線基盤
   4 件ちょうど) は候補集合を `implementationClasses() ∪ namedClasses()` で作っているため、
   **改名すると provider 自身が候補から静かに落ちる**。いまの結果は変わらない
   (走査器は `isDeclarationName` のトークンを飛ばすので、クラス宣言名は参照として数えない
   — `FakeWiringSourceScanner` の docblock と実装で確認済み) が、
   **将来 provider が別の配線基盤クラスを名指しした場合の検出範囲が黙って狭くなる**。
   これは検査の網が縮む変化なので、候補集合を
   `implementationClasses() ∪ namedClasses() ∪ placementExceptions() のキー` へ揃える
   (Codex 詳細レビュー Round 1 の Critical への対応)。
   **これはアプリの振る舞いの変更ではなく、改名で縮む網を元の広さに戻す変更である。**

### 変更箇所

- `tests/Support/ExternalFakes/FakeClassCatalog.php` L52-62 (`placementExceptions()` の
  docblock と entry のクラス名・理由文)
- `tests/Architecture/FakeClassReferenceInvariantTest.php` L30-47 (allowlist のパス) /
  L60-66 (4-2 の期待値) / L68-103 (4-3 に候補集合の明示 assertion を追加) /
  L105-116 (4-4 の期待値)
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` L239-243 (3-10 の候補集合に
  `placementExceptions()` のキーを足す)

### 現行コード

```php
/**
 * 定義 2 のうち定義 1 に属さなくてよい例外 (fake の実体ではなく配線基盤)。
 *
 * @return array<class-string, string> class => 理由
 */
public static function placementExceptions(): array
{
    return [
        FakeExternalsServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
        FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
    ];
}
```

### 変更後コード

```php
/**
 * 「fake の実体ではない配線基盤」の目録。用途は 2 つある。
 *
 * 1. 定義 2 (Fake 命名) に当たるが定義 1 (Fakes/ • Testing/ 配下) に属さなくてよい**配置の例外**
 * 2. **参照走査 (FakeClassReferenceInvariantTest 4-3) の候補**に必ず含める集合
 *
 * ★BughuntFakesServiceProvider は家系の名前へ改名した結果、名前の規則 (定義 2) では
 *   拾えなくなった。それでも本目録に残すのは用途 2 のためである — ここから落とすと、
 *   業務コードが唯一の配線点を直接参照しても検出できず**偽グリーン**になる
 *   (4-3 の docblock が名指しで警告している事故)。この包含は 4-3 の明示 assertion が固定する。
 *
 * @return array<class-string, string> class => 理由
 */
public static function placementExceptions(): array
{
    return [
        BughuntFakesServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
        FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
    ];
}
```

★**理由の文字列 (実行トークンとして残る値) は 1 文字も変えない**。説明は docblock (コメント) に
だけ書く。文字列を伸ばすと逆置換後に旧内容と一致しなくなり、受け入れ条件 A-6b が許す
「実行トークンの差分は 2 か所だけ」が崩れる (Codex 詳細レビュー Round 2 の Critical)。

`FakeClassReferenceInvariantTest` 側は allowlist のパスと 4-2 / 4-4 の期待値を新名へ差し替える
(**件数は 2 件 / 6 件のまま変えない**)。あわせて **4-3 の中に候補集合の明示 assertion を足す**
(Codex 詳細レビュー Round 1 の Critical / Warning への対応)。

```php
// tests/Architecture/FakeClassReferenceInvariantTest.php 4-3 の fail-closed 部に追記
    // ★名前の規則 (定義 2) では拾えなくなった配線 provider が、候補集合に必ず残っていること。
    //   ここが落ちると「本番コードが唯一の配線点を参照しても検出できない」偽グリーンになる。
    expect($candidates)->toContain(BughuntFakesServiceProvider::class)
        ->and($candidates)->toContain(FakeStorageGate::class);
```

```php
// tests/Architecture/ExternalFakeWiringInvariantTest.php 3-10 (候補集合の統一)
    $candidates = array_values(array_unique(array_merge(
        FakeClassCatalog::implementationClasses(),
        FakeClassCatalog::namedClasses(),
        array_keys(FakeClassCatalog::placementExceptions()),
    )));
```

**`placementExceptions()` の名前は変えない**。関数名まで変えると本施策が「名前を揃える作業」から
逸脱し、差分が名前の置換だけであるという受け入れ条件 (A-6) が成立しなくなる。
別メソッド (`referenceScanInfrastructureClasses()` 等) を新設する案も検討したが、
**目録が 2 件しかない段階で入口を 2 つに割ると、どちらに足すかの判断がそのつど要る**ため採らない
(思考原則 2「今必要なものだけ作る」)。代わりに用途 2 を docblock に明記し、
上の**明示 assertion で機械的に固定する** (削除事故は 4-3 の中で赤くなる)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 上記 2 ファイル

### PHPStan 適合チェック

- [x] `tests/` は解析対象外。`@return array<class-string, string>` は既存のまま

### テスト計画

- [x] **4-1** (Fake 命名クラスは `Fakes/` か `Testing/` 配下にのみ存在する) が緑のままであること
      — 改名後は provider が `namedClasses()` から外れるため `$misplaced` は空のまま
- [x] **4-2** (配置例外は 2 件から増えていない) が新名で緑
- [x] **4-3** (本番コードは fake クラスを参照しない) が緑、かつ**候補集合に
      `BughuntFakesServiceProvider` と `FakeStorageGate` が含まれること**を明示 assertion で固定
- [x] **4-4** (参照 allowlist は 6 件から増えていない) が新名で緑
- [x] `ExternalFakeWiringInvariantTest` **3-10** の候補集合へ `placementExceptions()` のキーを
      足しても**期待値 (配線基盤 4 件) は変わらない**こと — 走査器はクラス宣言名を参照として
      数えないため、provider 自身が候補に入っても結果に現れない。
      **実装セッションはこれを実行して確かめる** (説明だけで済ませない)
- [x] **負のコントロール**: 3-10 の候補集合から `placementExceptions()` を外した状態でも
      緑のままであることを 1 度確認し、「いま結果は変わらないが将来の網の広さが違う」ことを
      実装記録 (impl-review 用のメモ) に残す (この 1 点は**恒久テストにはしない** —
      恒久化すると「候補集合を狭めてよい」という誤った要求になるため)

### リスク

- `placementExceptions()` の意味が 1 用途から 2 用途へ広がる。**名前は変えない**
  (関数名まで変えると本施策が「名前を揃える作業」から逸脱する)。docblock に用途 2 を明記し、
  4-3 の明示 assertion で「落とすと赤くなる」状態にする。
- 3-10 の候補集合を広げるのは**検査側の変更**であり、A-6 の「逆置換でバイト一致」の対象から
  外れる 3 ファイルのうちの 1 つになる (受け入れ条件 A-6b で別建てに検証する)。

## テストファースト計画 (どのテストを先に赤にするか)

| 順 | 操作 | 期待される色 |
|---|---|---|
| 1 | 施策 1 の `BughuntNamingResidualTest` を**改名前に**追加して実行 | **赤** (N-1 が 33 ファイル / 90 箇所を列挙する。この出力が作業一覧になる)。N-2 の代表パスも改名後のパスを指すため赤 |
| 2 | 施策 2 の `git mv` を行い、クラス宣言だけ直して実行 | **赤** (`BughuntSeedWiringInvariantTest` S-1 = 目録のキー集合の不一致 / S-4 = 投入列の不一致 / S-9 = 前提テストの不在) |
| 3 | 施策 2 の参照を全部追従させて実行 | 施策 2 側は緑。N-1 はまだ赤 (provider が残る) |
| 4 | 施策 3 の `git mv` を行い、クラス宣言だけ直して実行 | **赤** (起動時に provider が解決できず Feature 全体 / `ExternalFakeWiringInvariantTest` 3-5・3-6・3-7 が落ちる) |
| 5 | 施策 3 + 施策 4 の参照を全部追従させて実行 | **緑** (N-1 も緑になる) |
| 6 | `php devnotes/…/verify-rename-only.php` (A-6 の検証手順) を実行 | 終了コード 0。落ちたら**逆置換で一致しない箇所が作業一覧になる** |
| 7 | 全検証コマンドを実行 | 全部緑 |

「先に赤を見る」対象は **N-1 (新設)** と、**既存の deny-by-default 検査群 (S-1 / S-4 / S-9 /
3-5 / 3-6 / 3-7 / 4-2 / 4-4)** の 2 系統である。後者は「参照の数え落とし」を機械で暴く役目を持つので、
**手順 2 と 4 で意図的に中間状態を作って赤を確認する** (赤を見ずに一括置換しない)。

## 受け入れ条件 (機械検証可能な形)

| # | 条件 | 検証方法 |
|---|---|---|
| A-1 | 追跡下の現役資産に旧名が 1 件も残っていない | `git ls-files -z \| xargs -0 grep -l -E 'BughuntBillingSeeder\|FakeExternalsServiceProvider'` の出力が **`devnotes/…` と `docs/TODO-closed.md` と `tests/Architecture/BughuntNamingResidualTest.php` だけ**である |
| A-2 | 同上を検査が固定している | `vendor/bin/pest tests/Architecture/BughuntNamingResidualTest.php` が緑 (N-1〜N-5) |
| A-3 | 旧名のクラスが存在しない | `php -r "require 'vendor/autoload.php'; var_dump(class_exists('Database\\Seeders\\BughuntBillingSeeder'), class_exists('App\\Providers\\FakeExternalsServiceProvider'));"` が `false` / `false` (N-5 が同じことをテストで固定する) |
| A-4 | 家系名のファイルが実在する | `test -f database/seeders/BughuntStripeSyncSeeder.php && test -f app/Providers/BughuntFakesServiceProvider.php` |
| A-5 | 改名前の履歴が追える | `git log --follow --oneline -- database/seeders/BughuntStripeSyncSeeder.php` / `-- app/Providers/BughuntFakesServiceProvider.php` が改名前の履歴を含む (**git は改名操作を記録せず内容の類似度から推定する**ので、これは「`git mv` を使ったことの証明」ではなく「rename 検出が働くこと」の確認である。`git mv` を使うことは実装手順として要求し、受け入れ条件とは分ける) |
| A-6a | **意図しない実行コード差分が無い (名前だけを置換したファイル群)** | 対象は変更ファイルのうち**下記 A-6b の 3 ファイルと新規テスト 1 件を除く全部** (改名した 4 ファイルを含む)。各ファイルの新内容へ `sed 's/BughuntStripeSyncSeeder/BughuntBillingSeeder/g; s/BughuntFakesServiceProvider/FakeExternalsServiceProvider/g'` を掛けたものが `git show main:<改名前のパス>` と**バイト一致**すること |
| A-6b | **意味も更新した 3 ファイルの差分が想定どおり** | 対象は `tests/Support/ExternalFakes/FakeClassCatalog.php` / `tests/Architecture/FakeClassReferenceInvariantTest.php` / `tests/Architecture/ExternalFakeWiringInvariantTest.php`。逆置換した新内容と旧内容を **PHP のトークン列 (`token_get_all` から `T_COMMENT` / `T_DOC_COMMENT` / `T_WHITESPACE` を除いたもの)** で比較し、差分が次の 2 か所**だけ**であること — (i) 3-10 の候補集合へ `array_keys(FakeClassCatalog::placementExceptions())` を足した式、(ii) 4-3 に足した候補集合の明示 assertion。**それ以外のコード差分が 1 トークンでもあれば不合格**である (理由文字列を含む) |
| A-6c | 新規ファイルは対象外 | `tests/Architecture/BughuntNamingResidualTest.php` は新規のため逆置換の比較対象にしない |
| A-6d | A-6a〜A-6c の判定が**再実行できる形**である | 下記「A-6 の検証手順」の一時スクリプトが終了コード 0 で通ること。母集団は `git diff --name-status -M main...HEAD` から導出し、**未分類・重複分類・分類はあるが差分に現れないファイルはすべて不合格**にする (fail-closed) |
| A-7 | 投入列が provision と reseed で一致 | `BughuntSeedWiringInvariantTest` の S-3 / S-4 が緑 |
| A-8 | 起動時の登録点が保たれている | `ExternalFakeWiringInvariantTest` の 3-5 / 3-6 / 3-7 が緑 |
| A-9 | 新設の検査が実在して緑 / 既存の指定 invariant が緑 / 全体が緑 | `vendor/bin/pest tests/Architecture/BughuntNamingResidualTest.php` に N-1〜N-5 の 5 test が現れて緑、`BughuntSeedWiringInvariantTest` (S-1〜S-11) / `ExternalFakeWiringInvariantTest` / `FakeClassReferenceInvariantTest` / `LaneExternalFakeBindingTest` / 改名した 2 本のテストが緑、`composer test` が **failed 0** かつ **skipped の件数が実装前と同じ** (テストの総数そのものは dataset 展開で揺れるため受け入れ条件にしない) |
| A-10 | 全検証コマンドが green | 下記の全コマンド |

### A-6 の検証手順 (再実行できる形にする)

判定を文章の読み合わせにしない。**一時スクリプトを `devnotes/20260817-1309-todo-t214-bughunt-family-naming/`
配下に置いて実行する** (AGENTS.md「一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ」。
恒久化はしない — 1 回きりの改名の検証であり、`scripts/README.md` の台帳に載せる性質ではない)。

ファイル名: `verify-rename-only.php` (実行は `php devnotes/…/verify-rename-only.php`)

処理の流れ:

1. `git diff --name-status -M main...HEAD` を実行し、母集団を作る。
   - `R<類似度> <旧パス> <新パス>` の行から**改名の対応表**を取る。
   - `M <パス>` は旧パス = 新パス。`A <パス>` は新規。`D <パス>` があれば**即不合格**
     (本施策に削除は無い)。
2. 各ファイルを A-6a / A-6b / A-6c のどれか **1 つだけ**に分類する。
   分類表 (A-6b の 3 ファイルと A-6c の 1 ファイル) はスクリプトに定数で持つ。
   - **未分類が 1 件でもあれば不合格** (取りこぼしの fail-closed)。
   - **分類表にあるのに差分に現れないファイルがあっても不合格** (設計と実装のずれ)。
3. A-6a: 新内容へ逆置換 (`BughuntStripeSyncSeeder` → `BughuntBillingSeeder`、
   `BughuntFakesServiceProvider` → `FakeExternalsServiceProvider`) を掛け、
   `git show main:<旧パス>` と**バイト比較**する。1 バイトでも違えば不合格。
4. A-6b: 逆置換した新内容と `git show main:<旧パス>` を `token_get_all()` に掛け、
   `T_COMMENT` / `T_DOC_COMMENT` / `T_WHITESPACE` を落としたトークン列 (種別と字句の対) を作る。
   2 つの列の差分を取り、**許可する追加トークン列 2 つ**
   ((i) `array_keys ( FakeClassCatalog :: placementExceptions ( ) )` を含む 3-10 の候補集合の追加、
   (ii) 4-3 に足した `expect ( $candidates ) -> toContain ( … )` の 1 文) と一致しなければ不合格。
   **削除側のトークンは 0 個であること**も要求する (既存の検査を弱めていないことの担保)。
5. すべて通ったら終了コード 0。失敗したファイルと理由を全件出力する
   (最初の 1 件で止めない = 直す作業一覧になる)。

実行結果 (出力そのまま) を `devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md`
に残す。

> **保証範囲を誇張しない**: A-6 が示すのは「意図しない実行コード差分が無い」ことまでである。
> **振る舞いの同値性そのものを証明するものではない** (autoload・キャッシュ・
> リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。振る舞い側の担保は、
> 既存の Feature / Architecture テストと `composer test` 全体が受け持つ。

## 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- 前線は `composer test` と `composer phpstan` と `vendor/bin/pint --test` である
  (変更は PHP / シェル / 文書のみで、フロントには 1 行も触れない)。
- `pnpm` 系は**回帰していないことの確認**として全数実行する (AGENTS.md の検証コマンド節が
  `tests/js/architecture/verification-commands-doc-sync.test.ts` で同期を強制している)。
- `composer test:browser` は本施策の対象外だが、`tests/Pest.php` を触るため
  **実装セッションの判断で 1 度は流す** (Browser lane も同じ `enableFakeExternals()` を使う)。
- テストレーンはホスト全体で 1 本ずつしか走らない (T099)。待ちが出るのは正常であり、
  ロックファイルを消さない・kill しない。

## 保証しないもの / やらないと決めたこと

| 項目 | 判断 | 理由 |
|---|---|---|
| ファイル数の統合 (8 → 6) | やらない | **2026-08-10 の裁定で撤回済み**。やると裁定に反する |
| `config('testing.fake_externals')` / `TESTING_FAKE_EXTERNALS` の改名 | やらない | 台帳が不一致として挙げていない。設定キーと環境変数は `.env.bughunt.local` との外部契約で、改名は bug-hunt 環境の再設定を要求する破壊的変更になる |
| 検査ファイル名の家系への統一 (`ExternalFakeWiringInvariantTest` → `BughuntFakeWiringTest` 等) | やらない | 2026-08-16 の巡回が残要件として名指ししたのは本設計の 2 件だけである。広げるなら台帳の議題として起こす |
| `tests/Feature/Database/BughuntOAuthSeederGuardTest.php` の改名 | やらない | 台帳の gates は aicue のこの名前を**実在する検査**として登録しており、別名として問題視していない |
| テストの置き場所の移動 (`tests/Feature/Database/` → `tests/Feature/Bughunt/`) | やらない | 台帳は aicue の決済側テストを「**同じ位置に**別名で在る」と記録している = 位置は要件ではない |
| `docs/TODO-closed.md` の旧名 | 書き換えない (件数は pin する) | 完了した TODO の記録であり、当時の名前は当時の事実である。**旧名ごとの件数を完全一致で pin するので、増減も内訳の入れ替えも検出する** (沈黙しない) |
| `devnotes/` の旧名 | 書き換えない (走査から丸ごと外す) | 過去の設計・レビュー・走行記録であり、190 ファイル規模で旧名を含む。件数 pin が実務にならないため丸ごと外す。**帰結として、ここでは旧名の再流入に沈黙する** |
| 本検査ファイル自身の中の旧名 | 走査しない | 検出したい語を負のコントロールの入力として持つため。**帰結として、このファイルの中では沈黙する** (`RouteCacheExemptionPremiseTest` と同じ穴の持ち方) |
| 旧名からの別名 (`class_alias` / 継承した薄いクラス) の提供 | やらない | 思考原則 3 (後方互換の並走を残さない)。名前を 1 つにするのが目的なので、2 つ残したら要件を満たさない |
| 検査が「家系名として正しいこと」を保証すること | しない | 正本は台帳である。N-1 が固定するのは「旧名が現役資産に無いこと」まで |
| 字面以外の検出 (分割連結・動的生成) | しない | 走査は `substr_count` の字面照合である。誇張しない |
| 「振る舞いが同値である」ことの証明 | しない | A-6 が示すのは**意図しない実行コード差分が無いこと**までである。autoload・キャッシュ・リポジトリ外の実行手順・動的に組み立てるクラス名は対象外で、振る舞い側の担保は既存テストと `composer test` 全体が受け持つ |
| 台帳への書き込み (`append_event`) | 設計では行わない | 実装完了後に実装セッションが `status_reported` を出す (refs は push 済みの `<repo>@<commit>`) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 追跡下 33 ファイル・90 箇所に触れる横断的な改名であり、他タスクと同時に走らせると衝突が避けられない。逆に**変更の中身は名前の置換だけ**なので、単独で走らせれば短時間で終わる |
| 競合リスク | `tests/Pest.php` / `bootstrap/providers.php` / `docs/architecture.md` は他タスクも触りやすい。worktree 作成から main マージまでを 1 セッションで閉じ、途中で他タスクを挟まない |

---

## 実測の補足

`docs/TODO-closed.md` の旧名の内訳は実測値である (`grep -o` による計数):
- `BughuntBillingSeeder`: 1 件 (aicue:T015 の行)
- `FakeExternalsServiceProvider`: 2 件 (aicue:T015 の行と aicue:T119 の行)

## 再レビューの依頼

修正後の詳細設計を再判定してください。とくに次を見てください。

1. A-6b の Critical が実際に閉じたか (理由文字列を戻し、説明を docblock に寄せた形で、許可差分 2 か所が本当に成立するか)
2. A-6 の検証手順 (一時スクリプトの 5 段) が、機械検証条件として抜けなく定義されているか。fail-closed の取り方に穴がないか
3. 旧名ごとの件数 pin と負のコントロール (N-4 の 5 ケース) が、deny-by-default として十分か
4. 他に、実装に入る前に閉じておくべき Critical / Warning が残っていないか

出力形式は Round 1 / Round 2 と同じ。
