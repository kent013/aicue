## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。仮説なき改善はただの試行錯誤である。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (解析対象は app / config / database / routes。tests は対象外)
- Pest テストフレームワーク (RefreshDatabase はグローバル適用)
- Laratrust RBAC

【本件の性質 — 重要】
本件は「複数リポジトリで共有される機能台帳 (lctl)」の裁定が求める、**同一の関心事に家系で 1 つの名前を割り当てる**作業である。振る舞いを 1 つも変えない改名がそのまま要件である。家系名は台帳の実測記録から確定済みで、推測で決めてよい対象ではない。

【レビュー観点】
1. コードの正確性 (改名の波及の数え落とし、参照漏れ、起動時に落ちる経路)
2. 既存コードとの整合性 (命名規約、deny-by-default の目録・件数 pin の作法)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (テストファースト = どのテストを先に赤にするか、負のコントロール)
5. 受け入れ条件が本当に機械検証可能か (とくに「振る舞い完全不変」の担保方法)
6. 新設する Architecture テストの走査範囲の切り方 (走査する / 除外する / 自己除外) と、保証範囲の誇張が無いか
7. 副作用・後退リスク (とくに偽の外部サービスの配線が外れて bug-hunt の走行が本物の外部サービスへ届く事故)
8. 波及変更の網羅性
9. セキュリティ (AGENTS.md のセキュリティ不変条件。とくに fake 配線の fail-secure 二軸と seeder の三重ガード)
10. スコープの適切さ (撤回された裁定 = ファイル数統合へ踏み込んでいないか / 過小になっていないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| 4 | 命名規則から外れることの帰結を埋める (参照走査の候補集合) | `tests/Support/ExternalFakes/FakeClassCatalog.php`、`tests/Architecture/FakeClassReferenceInvariantTest.php` | 高 (施策 3 と不可分) |

## 変更ファイル一覧

### 新規 (1)

| ファイル | 内容 |
|---|---|
| `tests/Architecture/BughuntNamingResidualTest.php` | 旧名 2 つが追跡下から消えたことと、戻らないことを固定する |

### 改名 (4。`git mv` で履歴を繋ぐ)

| 変更前 | 変更後 |
|---|---|
| `database/seeders/BughuntBillingSeeder.php` | `database/seeders/BughuntStripeSyncSeeder.php` |
| `tests/Feature/Database/BughuntBillingSeederTest.php` | `tests/Feature/Database/BughuntStripeSyncSeederTest.php` |
| `app/Providers/FakeExternalsServiceProvider.php` | `app/Providers/BughuntFakesServiceProvider.php` |
| `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` | `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` |

### 変更 (29。すべて名前の置き換えのみ)

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
3 分類) をそのまま踏襲する。**走査範囲は 3 分類で明示する** (Codex 概念レビュー Warning への対応)。

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
 * (b) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。**過去の記録**である。
 *
 * - `devnotes/`: 設計・レビュー・走行記録。当時の名前で議論した事実そのものなので書き換えない
 *   (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで除外するのと同じ扱い)。
 * - `docs/TODO-closed.md`: 完了した TODO の記録。aicue:T015 / aicue:T119 が当時作った
 *   クラスの名前は当時の事実であり、書き換えると記録が嘘になる。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_HISTORICAL_PATHS = ['devnotes/', 'docs/TODO-closed.md'];

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

    foreach (BUGHUNT_NAMING_HISTORICAL_PATHS as $historical) {
        if (str_starts_with($relative, $historical)) {
            return [];
        }
    }

    $violations = [];
    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        $count = substr_count($content, $retired);
        if ($count > 0) {
            $violations[] = "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})";
        }
    }

    return $violations;
}
```

テスト本体 (要旨):

| # | 名前 | 内容 |
|---|---|---|
| N-1 | 追跡下の現役資産に旧名が 1 つも残っていない | 追跡下の全ファイルを `bughuntNamingViolationsIn()` に通して `[]` |
| N-2 | fail-closed | 母集団が下限以上、かつ代表パスを全部含む (`git ls-files` が失敗したら**空を返さず例外**) |
| N-3 | 除外は 3 つちょうど | 自分 1 件 + 過去の記録 2 件を完全一致で pin (増減で赤) |
| N-4 | 負のコントロール | 同じ述語が (a) `app/Foo.php` に旧名を書いた入力を検出し、(b) `devnotes/…` の入力は検出せず、(c) 自分自身のパスの入力も検出しない |
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
- `docs/TODO-closed.md` を丸ごと除外するため、**将来この 1 ファイルに旧名が増えても沈黙する**。
  件数 pin にしない理由は、aicue:T214 のクローズ記録自体が旧名に触れる可能性があり、
  pin を毎回動かす摩擦が検査の意味を上回るためである (穴として docblock に明記する)。

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
- 生成物 `bootstrap/cache/services.php` に旧名が残ることがあるが、Laravel は provider 一覧が
  変わると manifest を作り直すため手当ては要らない (追跡外・`.gitignore` 済み)。
  それでも詰まった場合の復旧は `php artisan optimize:clear` である。

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
ここで `placementExceptions()` から entry を落とすと、**配線 provider が 4-3 の候補集合から消え、
業務コードが配線点を直接参照しても検出できなくなる** = docblock が名指しで警告している
偽グリーンを自分で作ることになる。したがって **entry は残し、docblock の意味を書き直す**。

### 変更箇所

- `tests/Support/ExternalFakes/FakeClassCatalog.php` L52-62 (`placementExceptions()` の
  docblock と entry のクラス名・理由文)
- `tests/Architecture/FakeClassReferenceInvariantTest.php` L30-47 (allowlist のパス) /
  L60-66 (4-2 の期待値) / L105-116 (4-4 の期待値)

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
 *   (4-3 の docblock が名指しで警告している事故)。
 *
 * @return array<class-string, string> class => 理由
 */
public static function placementExceptions(): array
{
    return [
        BughuntFakesServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。名前の規則では拾えないため参照走査の候補としてここに残す。',
        FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
    ];
}
```

`FakeClassReferenceInvariantTest` 側は allowlist のパスと 4-2 / 4-4 の期待値を新名へ差し替える
(**件数は 2 件 / 6 件のまま変えない**)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 上記 2 ファイル

### PHPStan 適合チェック

- [x] `tests/` は解析対象外。`@return array<class-string, string>` は既存のまま

### テスト計画

- [x] **4-1** (Fake 命名クラスは `Fakes/` か `Testing/` 配下にのみ存在する) が緑のままであること
      — 改名後は provider が `namedClasses()` から外れるため `$misplaced` は空のまま
- [x] **4-2** (配置例外は 2 件から増えていない) が新名で緑
- [x] **4-3** (本番コードは fake クラスを参照しない) が緑、かつ候補集合に
      `BughuntFakesServiceProvider` が**含まれていること**を目視ではなく
      `placementExceptions()` の実在で担保する
- [x] **4-4** (参照 allowlist は 6 件から増えていない) が新名で緑
- [x] `ExternalFakeWiringInvariantTest` **3-10** (候補集合は
      `implementationClasses() ∪ namedClasses()`) は、provider が候補から外れても
      期待値 (配線基盤 4 件) が変わらない — provider 自身のクラス宣言は
      走査器が自己参照として数えないため、改名前も候補にはあったが結果に現れていなかった

### リスク

- `placementExceptions()` の意味が 1 用途から 2 用途へ広がる。**名前は変えない**
  (関数名まで変えると本施策が「名前を揃える作業」から逸脱する)。docblock に用途 2 を明記する。

## テストファースト計画 (どのテストを先に赤にするか)

| 順 | 操作 | 期待される色 |
|---|---|---|
| 1 | 施策 1 の `BughuntNamingResidualTest` を**改名前に**追加して実行 | **赤** (N-1 が 33 ファイル / 90 箇所を列挙する。この出力が作業一覧になる)。N-2 の代表パスも改名後のパスを指すため赤 |
| 2 | 施策 2 の `git mv` を行い、クラス宣言だけ直して実行 | **赤** (`BughuntSeedWiringInvariantTest` S-1 = 目録のキー集合の不一致 / S-4 = 投入列の不一致 / S-9 = 前提テストの不在) |
| 3 | 施策 2 の参照を全部追従させて実行 | 施策 2 側は緑。N-1 はまだ赤 (provider が残る) |
| 4 | 施策 3 の `git mv` を行い、クラス宣言だけ直して実行 | **赤** (起動時に provider が解決できず Feature 全体 / `ExternalFakeWiringInvariantTest` 3-5・3-6・3-7 が落ちる) |
| 5 | 施策 3 + 施策 4 の参照を全部追従させて実行 | **緑** (N-1 も緑になる) |
| 6 | 全検証コマンドを実行 | 全部緑 |

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
| A-5 | 改名が履歴として追える | `git log --follow --oneline -- database/seeders/BughuntStripeSyncSeeder.php` が改名前の履歴を含む (= `git mv` を使った) |
| A-6 | **振る舞い完全不変** | 変更後の全差分に対して「新名 → 旧名」の逆置換を施すと、**改名 4 件と新規テスト 1 件を除いて差分が空**になる。手順: `git diff -M main...HEAD -- <変更ファイル>` を取り、各ファイルの新内容へ `sed 's/BughuntStripeSyncSeeder/BughuntBillingSeeder/g; s/BughuntFakesServiceProvider/FakeExternalsServiceProvider/g'` を掛けたものが `git show main:<path>` と**バイト一致**する |
| A-7 | 投入列が provision と reseed で一致 | `BughuntSeedWiringInvariantTest` の S-3 / S-4 が緑 |
| A-8 | 起動時の登録点が保たれている | `ExternalFakeWiringInvariantTest` の 3-5 / 3-6 / 3-7 が緑 |
| A-9 | テスト総数が「元の数 + 新規 5 test」で、失敗 0 | 実装前の `composer test` の passed 数を記録し、実装後と突き合わせる |
| A-10 | 全検証コマンドが green | 下記の全コマンド |

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
| `docs/TODO-closed.md` / `devnotes/` の旧名 | 書き換えない | どちらも過去の記録であり、当時の名前は当時の事実である。**帰結として、この 2 か所では旧名の再流入に沈黙する** |
| 旧名からの別名 (`class_alias` / 継承した薄いクラス) の提供 | やらない | 思考原則 3 (後方互換の並走を残さない)。名前を 1 つにするのが目的なので、2 つ残したら要件を満たさない |
| 検査が「家系名として正しいこと」を保証すること | しない | 正本は台帳である。N-1 が固定するのは「旧名が現役資産に無いこと」まで |
| 字面以外の検出 (分割連結・動的生成) | しない | 走査は `substr_count` の字面照合である。誇張しない |
| 台帳への書き込み (`append_event`) | 設計では行わない | 実装完了後に実装セッションが `status_reported` を出す (refs は push 済みの `<repo>@<commit>`) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 追跡下 33 ファイル・90 箇所に触れる横断的な改名であり、他タスクと同時に走らせると衝突が避けられない。逆に**変更の中身は名前の置換だけ**なので、単独で走らせれば短時間で終わる |
| 競合リスク | `tests/Pest.php` / `bootstrap/providers.php` / `docs/architecture.md` は他タスクも触りやすい。worktree 作成から main マージまでを 1 セッションで閉じ、途中で他タスクを挟まない |

---

## 関連する現行コード (抜粋)

### app/Providers/FakeExternalsServiceProvider.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use App\Support\FakeStorageGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 偽の外部サービスの配線 (差し替え先の決定は本ファイルに 1 つも無い)。
 *
 * 「何をどの偽物へ差し替えるか」の正本は App\Support\ExternalFakes\ExternalFakeDeclaration で、
 * 本 provider はその宣言を 1 本の経路で適用するだけである。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. capability flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても偽物を立てない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を起動時 fail-fast で拒否する。
 *
 * capability は 3 つで許可環境も判定も異なる (すべて宣言側が正本):
 * - 外部サービス (決済 gateway + 人間性確認 + 外部ログインの解決点): EXTERNALS_FLAG。
 *   container 差し替えのため register() で配線する。**外部ログインだけ許可環境が狭い**。
 * - 保存先 (S3): STORAGE_FLAG。有効化条件は FakeStorageGate に一元化する
 *   (経路登録と実行時判定を完全一致させるため。経路キャッシュ残存で素通りしないようにする)。
 * - LLM (Prism): LLM_FLAG。Prompt::$fake はプロセス大域の static のため container 差し替えではなく
 *   boot() で install する (宣言の swaps() には現れない)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->installDeclaredSwaps();
    }

    public function boot(): void
    {
        $this->bootLlmFake();       // LLM: LLM_FLAG 依存 (container 差し替えではない)
        $this->bootStorageRoutes(); // 偽の保存先の署名付き経路 — 独立
    }

    /**
     * 宣言集合を 1 本の経路で差し替える (bind 対象の決定は宣言側にしか無い)。
     */
    private function installDeclaredSwaps(): void
    {
        $environment = $this->app->environment();
        $this->warnIfExternalsFlagIsUnusable($environment);

        // 保存先だけは「登録条件と実行時条件を完全一致させる」ため判定を gate に一元化している。
        // ここでは 1 度だけ解決する。
        $storageEnabled = $this->app->make(FakeStorageGate::class)->enabled();

        foreach (ExternalFakeDeclaration::swaps() as $swap) {
            $enabled = $swap->flag === ExternalFakeDeclaration::STORAGE_FLAG
                ? $storageEnabled
                : config($swap->flag) === true
                    && in_array($environment, $swap->allowedEnvironments, true);

            if (! $enabled) {
                continue;
            }

            $this->app->bind($swap->abstract, $swap->fake);
        }
    }

    /**
     * 外部サービスのフラグが立っているのに、その capability の許可環境の外にいるときだけ
     * **1 度だけ**警告する (未知の環境で誤って有効化されたことを検出可能にするため)。
     *
     * ★外部ログインだけ許可環境が狭いことについては**警告しない**。あれは誤設定ではなく
     *   設計上の除外である (保存先 / LLM のフラグでも警告しないのと同じ)。
     */
    private function warnIfExternalsFlagIsUnusable(string $environment): void
    {
        if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) {
            return;
        }

        if (in_array($environment, ExternalFakeDeclaration::EXTERNAL_ENVIRONMENTS, true)) {
            return;
        }

        Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
            'environment' => $environment,
        ]);
    }

    /** LLM (Prism) の偽物 (LLM_FLAG + LLM_ENVIRONMENTS。挙動不変) */
    private function bootLlmFake(): void
    {
        // bughunt 既定は実 LLM で、--fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入される。
        if (config(ExternalFakeDeclaration::LLM_FLAG) !== true) {
            return;
        }

        // Prompt::$fake (プロセス大域の static) を書き換えるため、per-test で static を占有する
        // testing と、実 API 検証を潰す local は許可環境から外す。
        // (決済と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), ExternalFakeDeclaration::LLM_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }

    /** 偽の保存先の署名付き経路 (gate 成立時のみ。web CSRF group 外 = signed のみ) */
    private function bootStorageRoutes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        // 冪等化: boot() が複数回走っても (route:cache 併用・テストの provider 再実走等)
        // 同名 route を二重登録しない。通常の bootstrap では未登録 = そのまま登録される。
        if (Route::has('bughunt.storage.put')) {
            return;
        }

        Route::middleware('signed')->group(function (): void {
            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
                ->name('bughunt.storage.put');
            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
                ->name('bughunt.storage.get');
        });
    }
}
```

### bootstrap/providers.php (全文)
```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ExternalClientTimeoutServiceProvider;
use App\Providers\FakeExternalsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\McpPassportServiceProvider;
use App\Providers\PasskeyServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    // 外部 SDK (Stripe) のプロセス大域 timeout pin。他の provider の副作用と混ぜないため
    // 専用に切り出す (テストが boot() を単独で再実行できるようにする)
    ExternalClientTimeoutServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    // passkey (laravel/passkeys) の app アダプタ。Fortify が feature flag で route を
    // 登録するため **FortifyServiceProvider より後**に置く。ただし binder / middleware の
    // 後付けは provider 順序に依存しないよう $app->booted() 内で最終上書きする
    PasskeyServiceProvider::class,
    // Passport は composer.json の dont-discover で自動 discovery を無効化し、
    // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    FakeExternalsServiceProvider::class,
];
```

### database/seeders/BughuntBillingSeeder.php (L1-85)
```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketLedgerService;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * bug-hunt env 専用: 有料プラン組織に active subscription + 初期チケットを付与する。
 *
 * 目的: BillingAccess::hasActiveAccess (subscription default が active/trialing) を
 * 有料プラン組織で true にし、業務ルート (/projects, /app) を bug-hunt で走行可能にする。
 * チケット消費系ジャーニー (AI 解析 / レンダ) のため初期残高も付与する。
 *
 * ★ 無料組織には subscription もチケットも付与しない: 「課金なし経路」(残高ゼロ) を
 *   bug-hunt 環境内に温存し、課金ゲート系バグの検出能力を落とさない (概念設計 施策 4)。
 *   ただし課金ゲートは plan_code を見ず free entitlement を要求するため、未契約
 *   (plan_code NULL) の組織には declarer-less な free entitlement
 *   (free_plan_code='personal' / personal_declared_by_user_id NULL) を立てる
 *   = grandfathering backfill 後の本番状態と同型の fixture にする。
 *   初回無償チケットは発火させない (signup_tickets_granted_at に触れない = 残高ゼロを温存)。
 *
 * 三重 fail-secure (BughuntOAuthSeeder と同一): (1) config('testing.fake_externals') === true、
 * (2) app()->environment('bughunt.local')、(3) DB 名 ^bug_hunt(_[1-8])?$。
 * いずれか欠ければ no-op (production/dev DB に課金状態をばら撒かない)。
 *
 * 冪等 = 「探索前提の active 状態を毎回回復する」。stripe_status が active 以外に変わって
 * いても reseed で active を再保証する。チケットは grantMonthly の idempotency_key で二重付与しない。
 *
 * 依存は Seeder の method injection (run() 引数) で受ける (Laravel 公式作法。型安全)。
 */
class BughuntBillingSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;

    /** 初期チケット付与枚数 (S3 の解析/レンダ探索に十分な決定論値)。 */
    private const int INITIAL_TICKET_GRANT = 100;

    public function run(TicketLedgerService $tickets): void
    {
        if (
            config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true
            || ! app()->environment('bughunt.local')
            || ! $this->isBughuntDatabase()
        ) {
            $this->command->warn('BughuntBillingSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');

            return;
        }

        $this->grandfatherUncontractedOrganizations();

        $paidPlanCodes = $this->paidPlanCodes();
        if ($paidPlanCodes === []) {
            $this->command->warn('BughuntBillingSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');

            return;
        }

        $organizations = Organization::query()->whereIn('plan_code', $paidPlanCodes)->orderBy('id')->get();
        foreach ($organizations as $organization) {
            $this->ensureActiveSubscription($organization);
            // 冪等キーで二重付与を防ぐ (reseed は migrate:fresh 後だが、単独再実行にも安全)
            $tickets->grantMonthly(
                $organization,
                self::INITIAL_TICKET_GRANT,
                null,
                "bughunt:initial-grant:{$organization->id}",
                'bug-hunt 初期チケット (探索用)',
            );
        }

        $this->command->info("BughuntBillingSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
    }

```

### tests/Support/Bughunt/BughuntSeedWiringInventory.php (L29-101)
```php
final class BughuntSeedWiringInventory
{
    /**
     * 区分と理由の目録。
     *
     * `guardPremiseTest` はガードの論理 (かつ / または) を実際に動かして固定している
     * 振る舞いテストのパス。静的走査は「判定語が条件に現れること」までしか見られず、
     * `||` と `&&` の取り違えのような論理の退行は読めないため、ガードを要求する区分には
     * 前提テストを必ず紐づける (免除の前提を振る舞いで固定する
     * ThrottleExemptionPremiseTest / IdempotencyExemptionPremiseTest と同じ作法)。
     * ガードを要求しない区分では null 固定 (値があったら赤)。
     *
     * @return array<class-string, array{
     *     role: BughuntSeedRole,
     *     reason: string,
     *     guardPremiseTest: non-empty-string|null,
     * }>
     */
    public static function entries(): array
    {
        return [
            BughuntBillingSeeder::class => [
                'role' => BughuntSeedRole::BughuntOnly,
                'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
                'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
            ],
            BughuntOAuthSeeder::class => [
                'role' => BughuntSeedRole::BughuntOnly,
                'reason' => 'CLI の認証状態と旧 MCP トークンを直付与する。通常経路に載せると開発 DB へ既知の資格情報が入る。',
                'guardPremiseTest' => 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php',
            ],
            AdminUserSeeder::class => [
                'role' => BughuntSeedRole::SharedWithBughunt,
                'reason' => '固定の管理者を作る。開発環境では通常経路に載るが、bug-hunt では管理画面の探索用に明示投入する。',
                'guardPremiseTest' => 'tests/Feature/Admin/AdminUserSeederTest.php',
            ],
            ManualTestSeeder::class => [
                'role' => BughuntSeedRole::ManualFixture,
                'reason' => '手順書と動画マニュアルの見本を作る開発用 fixture。既知の資格情報を持たないためガードを要求しない。',
                'guardPremiseTest' => null,
            ],
            DatabaseSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '通常経路の束ね役。bug-hunt では migrate:fresh --seed 経由で走るため明示投入しない。',
                'guardPremiseTest' => null,
            ],
            RoleSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '役割の定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            PermissionSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '権限の定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            RolePermissionSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '役割と権限の紐付け。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            PlanSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => 'プラン定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            TicketVolumePriceSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => 'チケットの傾斜単価。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
        ];
    }
```

### tests/Architecture/BughuntSeedWiringInvariantTest.php (S-1 / S-3 / S-4 / S-9 / S-10 の抜粋)
```php
/**
 * 前提テストの紐づけの違反一覧 (純関数 = 負のコントロールが同じ述語を通せる)。
 *
 * @param  class-string  $class  対象の seeder
 * @param  string|null  $premise  紐づけられた前提テストのパス (repo ルート相対)
 * @param  bool  $guardRequired  区分が環境ガードを要求するか
 * @return list<string> 違反一覧 (空 = 合格)
 */
function bughuntSeedPremiseViolations(string $class, ?string $premise, bool $guardRequired): array
{
    if (! $guardRequired) {
        return $premise === null ? [] : ["ガードを要求しない区分に前提テストが紐づいている: {$class}"];
    }

    if ($premise === null) {
        return ["前提テストが紐づいていない: {$class}"];
    }

    $violations = [];

    if (! str_starts_with($premise, 'tests/Feature/')) {
        $violations[] = "前提テストは tests/Feature/ 配下であること: {$premise}";
    }

    $path = base_path($premise);
    if (! is_file($path)) {
        return [...$violations, "前提テストが実在しない: {$premise}"];
    }

    $source = file_get_contents($path);
    if (! is_string($source)) {
        return [...$violations, "前提テストを読めない: {$premise}"];
    }

    if (! str_contains($source, bughuntSeedShortName($class))) {
        $violations[] = "前提テストが対象 seeder を参照していない: {$premise}";
    }

    return $violations;
}

test('S-1 目録のキー集合が database/seeders の Seeder クラス集合と過不足なく一致する', function (): void {
    $declared = bughuntSeedDeclaredSeederClasses();
    $registered = array_keys(BughuntSeedWiringInventory::entries());

    // 走査が壊れて「空母集団で緑」になるのを防ぐ (fail-closed)
    expect($declared)->not->toBeEmpty();

    sort($registered);

    expect($registered)->toBe($declared);
});

test('S-2 各 entry の理由が 30 文字以上である', function (): void {
    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
        expect(mb_strlen($entry['reason']))
            ->toBeGreaterThanOrEqual(30, "理由が短すぎる: {$class}");
    }
});

test('S-3 cmd_provision と cmd_reseed の投入列が順序込みで一致する', function (): void {
    $source = bughuntSeedShardSource();

    $provision = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision'));
    $reseed = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed'));

    // ★順序にも意味がある (ManualTestSeeder が先に走らないと BughuntOAuthSeeder は
    //   代表ユーザーを見つけられず skip する)。並べ替えたいときは 2 か所を同時に直すこと。
    expect($provision)->not->toBeEmpty()
        ->and($reseed)->toBe($provision);
});

test('S-4 投入列の集合が目録の「bug-hunt で明示投入する」区分と過不足なく一致する', function (): void {
    $sequence = bughuntSeedClassSequence(
        ShellFunctionWindow::ofCommand(bughuntSeedShardSource(), 'cmd_provision')
    );

    $expected = array_map(
        bughuntSeedShortName(...),
        BughuntSeedWiringInventory::seededInBughunt()
    );

    $actual = array_values(array_unique($sequence));
    sort($actual);
    sort($expected);

    expect($expected)->not->toBeEmpty()
        ->and($actual)->toBe($expected);
});

test('S-5 BughuntOnly 区分は DatabaseSeeder の呼び出し列に現れない', function (): void {
    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
    expect($source)->toBeString();
test('S-9 ガードを要求する区分は対象 seeder を参照する前提テストを持つ', function (): void {
    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
        $guardRequired = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']) !== [];

        expect(bughuntSeedPremiseViolations($class, $entry['guardPremiseTest'], $guardRequired))
            ->toBe([], "前提テストの紐づけが不正: {$class}");
    }
});

test('S-10 負のコントロール: 前提テストの差し替え・不在・区分違いを検出する', function (): void {
    // ★S-9 と**同じ述語**を通す (別の式で確かめると gate 本体の退行を映さない)。
    $class = BughuntOAuthSeeder::class;

    // (a) 実在するが対象 seeder を参照しない別のテストへ差し替える
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/ManualTestSeederTest.php', true))
        ->not->toBe([]);

    // (b) 実在しないパスへ差し替える
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/DoesNotExistTest.php', true))
        ->not->toBe([]);

    // (c) tests/Feature/ の外へ差し替える
    expect(bughuntSeedPremiseViolations($class, 'tests/Architecture/BughuntSeedWiringInvariantTest.php', true))
        ->not->toBe([]);

    // (d) ガードを要求する区分なのに紐づけを外す
    expect(bughuntSeedPremiseViolations($class, null, true))->not->toBe([]);

    // (e) ガードを要求しない区分に紐づけを足す
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php', false))
        ->not->toBe([]);

    // (f) 正のコントロール: 正しい紐づけは違反 0 件 (同じ述語であることの確認)
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php', true))
        ->toBe([]);
});
```

### tests/Support/ExternalFakes/FakeClassCatalog.php (L16-104)
```php
/**
 * fake クラスの母集団導出 (ハードコード一覧を持たない = fake が増えたら自動で母集団に入る)。
 *
 * 定義 1「fake 実装クラス」 = app/ 配下で `Fakes/` か `Testing/` ディレクトリに置かれた全クラス
 * 定義 2「fake 命名クラス」 = app/ 配下でクラス名が `Fake` で始まる or `Fake` で終わるクラス
 *
 * 前提: **1 ファイル 1 クラス + PSR-4 (`App\` => `app/`)**。Pint / composer の PSR-4 autoload が
 * 強制しているため、path から FQCN を導出する (token 解析より安定)。
 *
 * パス表記はすべて **repo ルート相対** (`app/Providers/Foo.php` / `routes/web.php`) で統一する。
 * 唯一の例外が {@see self::classFromPath()} で、これは `app/` 配下の repo 相対パスのみを受ける。
 */
final class FakeClassCatalog
{
    /** 参照走査の走査根 (repo ルート相対)。app/ だけだと route / config 直書きの抜け道が残る */
    private const array SCAN_ROOTS = ['app', 'routes', 'config', 'bootstrap'];

    /** fake 実装クラスを置くディレクトリ名 (path segment 完全一致で判定する) */
    private const array FAKE_DIRECTORIES = ['Fakes', 'Testing'];

    /**
     * 走査から外す生成物ディレクトリ (repo ルート相対の接頭辞)。
     *
     * `bootstrap/cache/` は `php artisan config:cache` 等が吐く**生成物**で .gitignore 済み。
     * ソースではないうえ、存在するかどうかが実行環境に依存するため走査すると gate が
     * 非決定になる (キャッシュ生成の有無で赤/緑が変わる)。
     */
    private const array EXCLUDED_PREFIXES = ['bootstrap/cache/'];

    /** repo ルートの絶対パス (tests/Support/ExternalFakes から 3 段上) */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

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

    /**
     * 定義 1: fake 実装クラス。母集団は app/ のみ (PSR-4 のクラス定義があるのは app/ だけ)。
     *
     * @return list<class-string>
     */
    public static function implementationClasses(): array
    {
        $classes = [];
        foreach (self::phpFilesUnder('app') as $path) {
            // ファイル名を除いたディレクトリ segment に Fakes / Testing が含まれるか (完全一致)。
            // 'FakesHelper' のような別名ディレクトリを巻き込まないため部分一致にしない。
            $segments = explode('/', $path);
            array_pop($segments);
            if (array_intersect($segments, self::FAKE_DIRECTORIES) === []) {
                continue;
            }
            $classes[] = self::classFromPath($path);
        }

        return $classes;
    }

    /**
     * 定義 2: fake 命名クラス。母集団は app/ のみ。
     *
     * @return list<class-string>
     */
    public static function namedClasses(): array
    {
        $classes = [];
        foreach (self::phpFilesUnder('app') as $path) {
            $name = basename($path, '.php');
            if (! str_starts_with($name, 'Fake') && ! str_ends_with($name, 'Fake')) {
                continue;
            }
            $classes[] = self::classFromPath($path);
        }

        return $classes;
    }

```

### tests/Architecture/FakeClassReferenceInvariantTest.php (全文)
```php
<?php

declare(strict_types=1);

use App\Providers\FakeExternalsServiceProvider;
use App\Support\FakeStorageGate;
use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 本番コードが fake のクラス名を 1 度も参照しないことの全走査
 * (c2c: external-fakes-wiring-gate 柱 3(c))。
 *
 * fake クラス名は**ディレクトリと命名から動的導出**する (ハードコード一覧を持たない)。
 * 現時点の違反は 0 件 = 「増えないこと」を今固定するのが最安。
 *
 * ★走査候補: 「fake 実装クラス」だけでは足りない。配置例外
 *   (FakeExternalsServiceProvider / FakeStorageGate) を業務コードが参照しても検出できず
 *   偽グリーンになるため、候補は implementationClasses() ∪ placementExceptions() のキーとする。
 *
 * ★走査根: app/ だけだと routes/ に Testing controller を直書きする、config/ にクラス名を書く、
 *   といった抜け道が残る。「本番コード全走査」を名乗る以上、
 *   app/ • routes/ • config/ • bootstrap/ の 4 根を走査する。
 *
 * ★誤検出が出たら allowlist を足す方向へ倒さない。まず「本当に本番コードから fake を
 *   参照しているのか」を疑う (それが本 gate の目的)。
 */

/** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (**repo ルート相対**) */
const FAKE_REFERENCE_ALLOWED = [
    // 何を偽物にするかの決定の唯一の正本 (差し替え先のクラス名はここにだけ現れる)
    'app/Support/ExternalFakes/ExternalFakeDeclaration.php',
    // 唯一の配線点。差し替え先は宣言から読むので、ここに現れる偽物系クラスは
    // 配線基盤の 4 件だけである (ExternalFakeWiringInvariantTest の 3-10 が集合で固定する)
    'app/Providers/FakeExternalsServiceProvider.php',
    // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    // bug-hunt 専用の通し確認コマンド。fake storage へ実バイトを置く必要があり、
    // FakeStorageGate 成立時のみ動く (上 2 件の controller と同 species)。
    // 本番経路からは到達しない (artisan 手動実行のみ・スケジュール登録なし)。
    // ★実装条件: constructor 引数を持たず、fake は handle() の fail-secure 4 条件を
    //   通過した**後**にのみ app() で遅延解決する。
    'app/Console/Commands/Development/PipelineSmokeCommand.php',
    // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
    'bootstrap/providers.php',
];

test('4-1 配置規約: Fake 命名クラスは Fakes/ か Testing/ 配下にのみ存在する', function (): void {
    $allowed = array_merge(
        FakeClassCatalog::implementationClasses(),
        array_keys(FakeClassCatalog::placementExceptions()),
    );

    $misplaced = array_values(array_diff(FakeClassCatalog::namedClasses(), $allowed));

    expect($misplaced)->toBe([]);
});

test('4-2 配置例外は 2 件から増えていない', function (): void {
    // 増やすときは placementExceptions() に理由を書いたうえで**ここも触る** (意図的な摩擦)。
    expect(array_keys(FakeClassCatalog::placementExceptions()))->toBe([
        FakeExternalsServiceProvider::class,
        FakeStorageGate::class,
    ]);
});

test('4-3 本番コードは fake クラスを参照しない', function (): void {
    $implementations = FakeClassCatalog::implementationClasses();
    $candidates = array_values(array_unique(array_merge(
        $implementations,
        array_keys(FakeClassCatalog::placementExceptions()),
    )));
    $files = FakeClassCatalog::scanFiles();

    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
    expect($candidates)->not->toBeEmpty()
        ->and($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        if (in_array($file, FAKE_REFERENCE_ALLOWED, true)) {
            continue;
        }

        // fake 実装クラス自身が別の fake を参照するのは正当 (FakeTakeObjectStorage → FakeObjectStore 等)
        if (str_starts_with($file, 'app/')
            && in_array(FakeClassCatalog::classFromPath($file), $implementations, true)) {
            continue;
        }

        $referenced = FakeWiringSourceScanner::referencedClasses(
            FakeClassCatalog::sourceOf($file),
            $candidates
        );

        if ($referenced !== []) {
            $violations[] = $file.': '.implode(', ', $referenced);
        }
    }

    expect($violations)->toBe([]);
});

test('4-4 参照 allowlist は 6 件から増えていない', function (): void {
    // 増やすときは理由コメントを添えて**ここも触る** (意図的な摩擦)。
    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(6)
        ->and(FAKE_REFERENCE_ALLOWED)->toBe([
            'app/Support/ExternalFakes/ExternalFakeDeclaration.php',
            'app/Providers/FakeExternalsServiceProvider.php',
            'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
            'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
            'app/Console/Commands/Development/PipelineSmokeCommand.php',
            'bootstrap/providers.php',
        ]);
});
```

### tests/Architecture/ExternalFakeWiringInvariantTest.php (L59-115, L205-262 の抜粋)
```php
/*
 * ソース走査系 mutation (M3〜M7) の被覆表。
 * M1 / M2 (宣言 entry の削除) は 3-2 の data-driven 解決検査が自動被覆する…のではなく
 * **3-16 の件数付き pin だけ**が映す (entry を消すと provider の bind もデータセットも
 * 同時に縮むため。詳細は 3-16 のコメント)。
 *
 * 定数名は他の Architecture テストと衝突しないよう prefix する
 * (Pest のファイル直下 const / function はグローバル空間に出る)。
 */
const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
    'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
    'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
    'M5' => 'provider は差し替え先のクラス名を 1 つも参照しない (決定は宣言側だけにある)',
    'M6' => 'provider の container 呼び出しは許可された形だけ',
    'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
];

const EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];

/**
 * 配線 provider が参照してよい配線基盤クラス (偽物の実体ではないもの)。
 *
 * 「provider が参照する偽物系クラス = 本集合ちょうど」を集合一致で検査する (3-10)。
 * ここに載っていないクラスを provider が参照した時点で赤くなり、とくに
 * **差し替え先 (swaps() の fake) が 1 つでも現れたら赤くなる**
 * = 差し替え先の決定が宣言側にしか無いことの機械的な裏付けになる。
 */
const EXTERNAL_FAKE_WIRING_PROVIDER_REFERENCE_EXCEPTIONS = [
    // LLM の偽物を立てる窓口 (container 配線を行わない)
    CannedPromptFakeRegistrar::class,
    // 偽の保存先の有効化条件 (container 配線を行わない)
    FakeStorageGate::class,
    // 偽の保存先の署名付き経路の受け口 (route action。container 配線を行わない)
    PutFakeStorageObjectController::class,
    GetFakeStorageObjectController::class,
];

/** 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
function externalFakeWiringProviderSource(): string
{
    return FakeClassCatalog::sourceOf('app/Providers/FakeExternalsServiceProvider.php');
}

/**
 * bootstrap/providers.php が宣言する provider 一覧。
 *
 * @return list<class-string>
 */
function externalFakeWiringRegisteredProviders(): array
{
    /** @var list<class-string> $providers */
    $providers = require base_path('bootstrap/providers.php');

    return $providers;
}

afterEach(function (): void {

        Log::shouldHaveReceived('warning')->once();
    } finally {
        config([ExternalFakeDeclaration::EXTERNALS_FLAG => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('3-5 登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている', function (): void {
    expect(externalFakeWiringRegisteredProviders())->toContain(FakeExternalsServiceProvider::class);
});

test('3-6 登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
    $providers = externalFakeWiringRegisteredProviders();

    $fakeIndex = array_search(FakeExternalsServiceProvider::class, $providers, true);
    $appIndex = array_search(AppServiceProvider::class, $providers, true);

    expect($fakeIndex)->toBeInt()
        ->and($appIndex)->toBeInt()
        ->and($fakeIndex)->toBeGreaterThan($appIndex);
});

test('3-7 登録点: 起動済み container に provider がロードされている', function (): void {
    expect(array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
});

test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
    $source = externalFakeWiringProviderSource();

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
});

test('3-10 網羅性: provider が参照する fake 系クラスは配線基盤 4 件ちょうど (差し替え先を含まない)', function (): void {
    $candidates = array_values(array_unique(array_merge(
        FakeClassCatalog::implementationClasses(),
        FakeClassCatalog::namedClasses(),
    )));

    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
    expect($candidates)->not->toBeEmpty();

    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);
    $expected = EXTERNAL_FAKE_WIRING_PROVIDER_REFERENCE_EXCEPTIONS;

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);

    // 差し替え先が 1 つでも provider に現れたら赤くする (決定は宣言側にしか無い)。
    $fakes = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->fake,
        ExternalFakeDeclaration::swaps()
    );
    expect(array_values(array_intersect($actual, $fakes)))->toBe([]);
});
```

### tests/Architecture/RouteCacheExemptionPremiseTest.php (走査範囲の切り方の既存作法。L40-120)
```php
/**
 * 本逸脱の登録番号。`docs/template-divergence.md` / `AGENTS.md` /
 * `docs/app-integration-guide.md` と本テストの結線はこの 1 か所を通す
 * (番号を 2 か所に書かない)。
 */
const ROUTE_CACHE_DIVERGENCE_ID = 'D19';

/**
 * 検査 B の走査から**丸ごと**外す唯一のファイル (repo 相対)。
 *
 * 本テスト自身だけである。検出したい語を負のコントロールの入力として持つため、
 * 自分を走査すると必ず自分で赤くなる。
 *
 * ★**保証の穴として明記する**: 本ファイルの中に `route:cache` の実行記述を書いても
 *   検査 B は沈黙する。丸ごとの除外はこの 1 件に限り、他のファイルは
 *   {@see ROUTE_CACHE_PREMISE_KNOWN_MENTIONS} の**件数 pin** で扱う。
 */
const ROUTE_CACHE_PREMISE_SELF_PATH = 'tests/Architecture/RouteCacheExemptionPremiseTest.php';

/**
 * 説明文として needle を持つことが確認済みのファイルと、その**件数**。
 *
 * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` と同じ作法)。
 * ファイル単位の除外にしないのは、除外したファイルの中に将来の実行記述が紛れ込んでも
 * 沈黙してしまうためである (deny-by-default の粒度を落とさない)。
 *
 * - `RouteThrottleBinderTest`: テスト名の文字列に「route:cache 下の再適用が冪等」という
 *   **説明**が入っている。実行ではないが、コメントを落としても文字列リテラルとして残る。
 *
 * @var array<string, int>
 */
const ROUTE_CACHE_PREMISE_KNOWN_MENTIONS = [
    'tests/Feature/Security/RouteThrottleBinderTest.php' => 1,
];

/**
 * 走査の母集団が空振りでないことを確かめる代表パス。
 *
 * @var list<string>
 */
const ROUTE_CACHE_PREMISE_SENTINEL_PATHS = [
    'composer.json',
    '.github/workflows/ci.yml',
    'scripts/bug-hunt-shard.sh',
];

/** 走査の母集団の下限 (これを下回ったら列挙そのものを疑う)。 */
const ROUTE_CACHE_PREMISE_MINIMUM_TRACKED_FILES = 500;

/**
 * git 追跡下の全ファイル (repo 相対パス、昇順)。
 *
 * ★`Tests\Support\TrackedPhpSourceFiles` は `*.php` 専用なので使えない。対象が
 *   拡張子を問わないため、共用クラスを新設せず本テスト内に閉じる (今必要なものだけ作る)。
 * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
 *
 * @return list<string>
 */
function routeCachePremiseTrackedFiles(): array
{
    $process = new Process(['git', 'ls-files', '-z'], base_path());
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '') {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);

```
