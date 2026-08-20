# Round 3: 詳細設計の修正版レビュー依頼

Round 2 の [Critical] 6 件・[Warning] 8 件・[Suggestion] 1 件をすべて対応しました。
全文は `codex-history/design-review-decisions-round-2.md` にあります。

## Critical への対応

| 指摘 | 対応 |
|---|---|
| 母集合の縮小規則が無い (ローカル削除で母集合から消せる) | 初回は「新正典キー ∩ 現在の追跡パス」、2 回目以降は**「新正典キー ∩ (現在の追跡パス ∪ 旧アプリ台帳のキー)」**。ローカル削除は母集合に残り `MissingCurrent` になる。母集合から外れるのは正典側から消えたパスだけ。同じ正典入力での縮小は exit 3。負例 4 本追加 |
| 実プロセステストの root 切替方法が無い | CLI を**薄い引数解析層のみ**にし、判定は `FingerprintGenerationService` (root・入力・出力先・writer・git を引数で受ける) へ分離。生成の判定は service を一時ディレクトリ root で直接テストし、実プロセスは**書き込み前に終了する経路だけ**を扱う。**root 差し替えの隠しオプションは作らない** |
| `PathObservation` の定義場所が無い | `tests/Support/TemplateDivergence/PathObservation.php` を新設 (1 クラス 1 ファイル)。不変条件をコンストラクタで検査。新規 PHP は **18 本** (`FingerprintGenerationService` 含む) |
| D33 に `AtomicLedgerWriter.php` が無い | 対象パスへ追加 (9 パス)。忘れると 3a が発火することも注記 |
| D34 の対象パスが `.txt` のまま | `adoption-debt.tsv` へ訂正 |
| C3 のスキル編集が `mutatedDebtPaths` になる / 波及変更に旧モデルの記述が残る | C3 は 3 択のうち**「登録を書いて債務から削る」**を選ぶ。**D35** (対象パス = スキル 2 本) を新設し、pin を `32 → 33` / `176 → 174`、債務 2 行削除、D34 本文、S7 期待値を同じコミットで更新。**フェーズ別 pin の表**を冒頭に追加 (C2 = 32/176/281 / C3 = 33/174/281) |

## Warning への対応

- テスト計画の「10 形」→ 11 形へ統一 (dataset 名を件数の正本にする)
- **生成器の入力にも正準形バイト一致を要求** (`$raw === fromJson($raw)->toJson()`)。不一致は書き込み前 exit 1
- byte 一致の drift 検出先を F8 → **F9** へ訂正
- `AtomicTextWriter::replace()` を **`void` + 例外**へ。移植した `AtomicLedgerWriter` は戻り値の形を保つので「null 以外なら即 exit 1」をコードとテストで固定
- テスト経路数を dataset の実数へ統一 (writer = 正常 1 + 失敗 7 の 8 件)
- **部分更新の「必ず赤」を証明する手段を追加**: 2 生成物に共通の世代識別子を持たせた (債務一覧の先頭行 `# template_ledger_commit=<40 hex>`) → **F14** が指紋台帳の `generated_at_commit` との一致と、各債務の採用時ハッシュが正典ハッシュと異なることを突き合わせる。部分更新 3 状態 ((a) JSON だけ / (b) 債務だけ / (c) 件数同数で内容違い) を失敗注入で作り赤になることを固定
- `AdoptionDebtInventory` を **11 形** (ヘッダ検査を追加) へ統一
- 実装モードの「12 本」→ 18 本、実装順序にフェーズ別 pin を併記
- F13 に「解析違反を持つ入力から登録リストを組み立てない」を明記

残る指摘が無ければ全体判定を APPROVED としてください。

---

## 修正後の詳細設計書 (全文)

# 詳細設計: テンプレート乖離台帳を家系の正典 t1 へ追従する

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10**（`composer phpstan`）。ただし本設計の新規ファイルは
  `tests/` と `scripts/` にあり、`phpstan.neon` の `paths` (`app` / `config` / `database` / `routes`) の
  **外**である (実測)。よって「PHPStan level 10 で解析済み」とは報告できない。
  型を緩めて黙らせるのではなく (禁止事項 2)、**解析されない場所だからこそ境界検証を
  コードで書き、正負のテストで担保する**
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** はグローバル適用済 (`tests/Pest.php`)。個別 `DatabaseTransactions` 禁止。
  本設計のテストは **DB を一切使わない** (Architecture / Unit レーン)
- テストデータは Factory (本設計は DB モデルを扱わないため該当なし)
- `declare(strict_types=1)` + 日本語コメント。`echo` / `goto` / `global` / 開始タグ付きの出力記法は
  書かない (`ForbiddenStatementTokenInvariantTest` の母集団に `scripts/*.php` も入る)
- **アーリーリターン**推奨。**コードフォーマット**: `composer fix`(Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12

### 静的検査 (gate) と走査器の共通規約 5 条への準拠

| 条 | 適用 | 本設計での満たし方 |
|---|---|---|
| (a) クラス参照は完全修飾名で突き合わせる | **非該当** | 本設計はクラス名・名前参照を解決しない (パスとハッシュだけを見る) |
| (b) 解決できない形は落とす (fail-closed) | 該当 | 読み取り失敗 / JSON 解釈不能 / git 失敗 / 解析不能な登録簿 / 判定できないパス形はすべて例外か違反にする。**空集合へ潰す経路を作らない** |
| (b) 母集団の非空 | 該当 | F4 が母集合と git 追跡ファイルの両方の非空を見る |
| (b) 保証範囲の docblock 明記 | 該当 | 突合 gate の冒頭に「保証しないもの」を列挙する (§S6) |
| (c) 負例で両方向の裏取り | 該当 | `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` (§S7) |
| (d) 集めて使わない形を作らない | 該当 | 突合結果は**種別ごとに分けた型**で返し、全種別を gate が assert する |
| (e) 語彙一致の否定形はトークン完全一致 | **非該当** | 語彙一致の判定を行わない (パスは完全一致・ハッシュは 64 桁 hex の同値比較) |

## 概念設計リファレンス

`devnotes/20260821-0000-template-divergence-fingerprint-t1/conceptual-design.md`
(Codex 概念設計レビュー Round 3 で APPROVED)

## 実測値と pin の導出 (実装時に必ず再確認する)

正典の指紋台帳 (`laravel-claude-template:docs/template-fingerprints.json`。
`generated_at_commit = a078806b0574518ddc64966f60f7d536b1338b2f`、読み取りは
`laravel-claude-template@0597a0c24d7fa7a054e3337704ccc97e4409b866`) の 947 キーに対する
本リポジトリ (`a4f4f254` 時点) の実測:

| 値 | 実測 | 内訳 |
|---|---|---|
| 正典のキー数 | 947 | |
| うち本リポジトリに実在 (= 採用時の母集合) | **275** | byte 一致 76 / 相違 199 |
| うち本リポジトリに不在 | 672 | 母集合外 (§S6 の保証しないもの 3) |
| 相違かつ既に登録済み | 21 | D10 / D11 / D14 / D18 / D20 / D22 / D25 / D27 / D30 / D31 |
| 相違かつ未登録 (観測値) | **178** | |
| 登録済みかつ内容一致 (3b が即発火する組み合わせ) | **0** | 導入時に登録簿の削除は不要 |

C2 完了時点の予測値 (**生成器の出力で確定させる**):

| pin | 予測値 | 導出 |
|---|---|---|
| `LedgerPins::FINGERPRINT_POPULATION_COUNT` | **281** | 275 + 新設する「正典のキーであるファイル」6 件 (§S2/S3/S4/S6) |
| `LedgerPins::ADOPTION_DEBT_COUNT` | **176** | 178 − D33 へ移す 2 件 |
| `LedgerPins::DIVERGENCE_ENTRY_COUNT` | **32** | 30 + D33 + D34 |

整合式 (実装時に検算する):
`281 = 78 (一致: 76 + byte 一致移植 2) + 27 (相違かつ登録済み: 21 + 新規同名 4 + 債務から移す 2) + 176 (債務)`

母集合へ入る新設ファイル 6 件の内訳 (いずれも正典のキーである):
byte 一致移植 2 件 (`LedgerRole.php` / `ComparisonState.php`) +
新規同名 4 件 (`FingerprintLedger.php` / `AtomicLedgerWriter.php` (JSON 専用のまま移植するが
本リポジトリでは `AtomicTextWriter` と対にするため docblock を 1 段落足す) /
`tests/Architecture/TemplateDivergenceFingerprintTest.php` / `scripts/update-template-fingerprints.php`)。

> **`AtomicLedgerWriter` を byte 一致移植から外した理由**: 正典版は読み戻した内容を
> `FingerprintLedger::fromJson()` に通す **JSON 専用**ライターである。債務一覧は平文なので
> 同じクラスでは書けない (Codex Round 1 [Critical])。JSON 側は正典版をそのまま使い、
> 債務一覧は `AtomicTextWriter` (検証関数を注入する平文版) を新設する。
> 移植した `AtomicLedgerWriter` には「平文の一覧は `AtomicTextWriter` が書く」ことを
> docblock へ 1 段落足すので byte 一致にはならない (= D33 の対象パスに入る)。

**この 3 値と D34 本文の件数・正例テストの期待値が一致していることを C2 の受入条件とする。**

### C2 の新規ファイル一覧 (機械的に列挙する)

| # | パス | 母集合 | 一致 |
|---|---|---|---|
| 1 | `tests/Support/TemplateDivergence/LedgerRole.php` | 入る | byte 一致 |
| 2 | `tests/Support/TemplateDivergence/ComparisonState.php` | 入る | byte 一致 |
| 3 | `tests/Support/TemplateDivergence/FingerprintLedger.php` | 入る | 相違 (D33) |
| 4 | `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` | 入る | 相違 (D33) |
| 5 | `tests/Architecture/TemplateDivergenceFingerprintTest.php` | 入る | 相違 (D33) |
| 6 | `scripts/update-template-fingerprints.php` | 入る | 相違 (D33) |
| 7 | `tests/Support/TemplateDivergence/RepoRelativePath.php` | 入らない | — |
| 8 | `tests/Support/TemplateDivergence/AtomicTextWriter.php` | 入らない | — |
| 9 | `tests/Support/TemplateDivergence/TrackedRepositoryFiles.php` | 入らない | — |
| 10 | `tests/Support/TemplateDivergence/AppFingerprintBuilder.php` | 入らない | — |
| 11 | `tests/Support/TemplateDivergence/FingerprintReconciler.php` | 入らない | — |
| 12 | `tests/Support/TemplateDivergence/ReconciliationResult.php` | 入らない | — |
| 13 | `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` | 入らない | — |
| 14 | `tests/Support/TemplateDivergence/LedgerPins.php` | 入らない | — |
| 15 | `tests/Support/TemplateDivergence/PathObservation.php` | 入らない | — |
| 16 | `tests/Support/TemplateDivergence/FingerprintGenerationService.php` | 入らない | — |
| 17 | `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` | 入らない | — |
| 18 | `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php` | 入らない | — |
| 19 | `docs/template-fingerprints.json` (生成物) | 入らない | — |
| 20 | `tests/Support/TemplateDivergence/adoption-debt.tsv` (生成物) | 入らない | — |

**新規 PHP 18 本 + 生成物 2 本** (1 クラス 1 ファイル。PSR-4 の autoload-dev が解決できる形にする)。
母集合の増加は 6 件で、うち byte 一致 2 / 相違 4。
既存変更は 4 ファイル (`composer.json` / 形式検査 / `DivergenceLedgerRules` docblock / `AGENTS.md`)
+ 登録簿 + スキル 2 本。

### フェーズ別の pin (C2 と C3 で値が変わる)

| pin | C2 完了時 | C3 完了時 | 理由 |
|---|---|---|---|
| `DIVERGENCE_ENTRY_COUNT` | 32 | **33** | C3 でスキル 2 本を説明する D35 を足す |
| `ADOPTION_DEBT_COUNT` | 176 | **174** | D35 がスキル 2 本を登録するので債務から外れる |
| `FINGERPRINT_POPULATION_COUNT` | 281 | 281 | スキル 2 本は母集合に留まる (債務 → 登録済みへ移るだけ) |

**C3 でスキルを編集すると、その 2 本は採用時ハッシュから外れて `mutatedDebtPaths` になる。**
債務モデル (§S5) では「変更したまま債務に残す」を許さないので、C3 は必ず
「登録を書いて債務から削る」を選ぶ (§S11)。C2 と C3 で pin の値が違うことを
実装時に取り違えないよう、上表を受入条件に含める。

## 施策一覧

| # | 施策名 | 変更ファイル | コミット | 優先度 |
|---|--------|------------|---|---|
| S1 | 識別子の反転 (role の 2 重化の前提) | `composer.json` | C1 | 高 |
| S2 | 指紋台帳の DTO とパス検証 | `tests/Support/TemplateDivergence/{LedgerRole,ComparisonState,FingerprintLedger,RepoRelativePath}.php` | C2 | 高 |
| S3 | 母集合の列挙と生成ロジック | `tests/Support/TemplateDivergence/{TrackedRepositoryFiles,AppFingerprintBuilder,AtomicLedgerWriter,AtomicTextWriter}.php` | C2 | 高 |
| S4 | 生成器と指紋台帳・債務一覧の生成 | `scripts/update-template-fingerprints.php` / `docs/template-fingerprints.json` / `tests/Support/TemplateDivergence/adoption-debt.tsv` | C2 | 高 |
| S5 | 突合と債務の判定 (純関数) | `tests/Support/TemplateDivergence/{FingerprintReconciler,ReconciliationResult,AdoptionDebtInventory,LedgerPins}.php` | C2 | 高 |
| S6 | 突合 gate | `tests/Architecture/TemplateDivergenceFingerprintTest.php` | C2 | 高 |
| S7 | 負例・正例 | `tests/Unit/Architecture/{TemplateDivergenceFingerprintRulesTest,TemplateFingerprintGeneratorTest}.php` | C2 | 高 |
| S8 | 件数 pin の一本化 | `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` | C2 | 中 |
| S9 | 登録簿への D33 / D34 と保証範囲の書き換え | `docs/template-divergence.md` | C2 | 高 |
| S10 | AG-159 の責務の縮小 | `AGENTS.md` / `tests/Support/TemplateDivergence/DivergenceLedgerRules.php` | C2 | 中 |
| S11 | 登録の契機 (t3 要素) | `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md` | C3 | 中 |

---

## S1: 識別子の反転 (role の 2 重化の前提)

### 変更箇所
- ファイル: `composer.json` (L3)

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし (`composer.json` の `name` を参照するコードは repo 内に 0 件。実測で確認)
- `composer.json` は**正典の指紋台帳のキーに入っていない** (提供元が `shared: false` と分類) ため、
  逸脱の登録も不要

### 現行コード
```json
    "name": "rio-development/laravel-claude-template",
```

### 変更後コード
```json
    "name": "rio-development/aicue",
```

### テスト計画
- [ ] S6 の F2 が緑になることで検証される。**F2 は「提供元名でない」ではなく
      `name === 'rio-development/aicue'` の完全一致**で見る (欠落・任意の別名を通さない)
- [ ] `composer install` / `composer test` が通ること (name はローカル project 名でしか使われない)

### リスク
- `composer.json` の変更は `composer.lock` の `content-hash` に影響しない (`name` は hash 対象外) が、
  念のため C1 の後に `composer install` を 1 回走らせて lock の差分が出ないことを確認する
- 家系の先例: metamovics が `composer.json` の name を改名し `role` を app へ反転済み

---

## S2: 指紋台帳の DTO とパス検証

### 変更箇所
- 新規: `tests/Support/TemplateDivergence/LedgerRole.php` (**正典から byte 一致で移植**)
- 新規: `tests/Support/TemplateDivergence/ComparisonState.php` (**正典から byte 一致で移植**)
- 新規: `tests/Support/TemplateDivergence/FingerprintLedger.php` (正典から移植し 1 か所を差し替え)
- 新規: `tests/Support/TemplateDivergence/RepoRelativePath.php` (本リポジトリ固有)

### 移植方針 (byte 一致とする根拠)

`LedgerRole` と `ComparisonState` は列挙のみで外部依存が無いので**正典と byte 一致で移植する**
(先例: T215 で正典の検査資産 29 ファイルを byte 一致で移植済み。
`tests/Support/Queue/` の 20 件超が現在も byte 一致で維持されている)。
byte 一致にすると母集合の中で**一致側**に入るので、以後この 2 本が動いたら 3a が落ちる
= 移植資産の drift が機械で見える。

`FingerprintLedger` は正典版が `SharedPathRules::isValidRepoRelativePath()` を呼ぶ。
`SharedPathRules` (22KB) は**提供元の `git ls-files` を分類する道具**で、本リポジトリでは
分類規則そのものを使わない (母集合の出典は正典の指紋台帳のキー) ため、
規則表ごと持ち込むと**使われない資産**になる (思考原則 2)。よって
`RepoRelativePath::isValid()` へ差し替え、この 1 点の差を D33 で登録する。

### 変更後コード (要点のみ)

```php
// tests/Support/TemplateDivergence/LedgerRole.php (正典と byte 一致)
enum LedgerRole: string
{
    case Template = 'template';
    case App = 'app';
}
```

```php
// tests/Support/TemplateDivergence/ComparisonState.php (正典と byte 一致)
enum ComparisonState
{
    case Matched;          // 内容一致
    case ContentMismatch;  // 内容相違
    case MissingCurrent;   // git 追跡から消えた (削除)
}
```

```php
// tests/Support/TemplateDivergence/RepoRelativePath.php
/**
 * 指紋台帳のキーと登録簿の対象パスに使える「リポジトリ相対の単一ファイルパス」の判定 (純関数)。
 *
 * **判定できない形は false を返す** (呼び出し側が違反にする)。黙って候補から外さない
 * (共通規約 (b))。次の 8 形を明示的に落とす:
 *  1. 空文字 / 2. 絶対パス (`/` 始まり) / 3. 要素が空 (`a//b`) /
 *  4. `.` を要素に含む / 5. `..` を要素に含む / 6. NUL を含む /
 *  7. 末尾が `/` (ディレクトリ表記) / 8. 制御文字を含む
 *
 * **保証しないもの**: 実在・追跡状態・regular file かどうかは見ない (書式だけを見る)。
 * 実在と種別は利用側 (F7 / F13) が git index と `is_file` / `is_link` で判定する。
 */
final class RepoRelativePath
{
    public static function isValid(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return false;
        }
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
```

```php
// tests/Support/TemplateDivergence/FingerprintLedger.php (正典から移植。差分は 1 行)
final readonly class FingerprintLedger
{
    public const int SCHEMA_VERSION = 1;

    /** JSON の必須キー (過不足はいずれも fail)。 */
    private const array REQUIRED_KEYS = ['schema_version', 'role', 'generated_at_commit', 'entries'];

    /** @param array<string, string> $entries repo-relative パス => sha256 (小文字 hex 64 桁)。キー昇順 */
    public function __construct(
        public int $schemaVersion,
        public LedgerRole $role,
        public string $generatedAtCommit,
        public array $entries,
    ) {}

    /** @throws RuntimeException 解釈不能なとき (正典 boundary (5c)) */
    public static function fromJson(string $json): self { /* 正典と同じ検査順序 */ }

    public function toJson(): string { /* キー昇順 + 4 空白 + 末尾改行 */ }
}
```

`fromJson()` が落とす形 (**11 形**。正典と同じ順序で、1 つでも欠けたら例外):

1. JSON として不正 / 2. 最上位が object でない (`[]` を含む) / 3. キー集合が正準形と不一致 /
4. `schema_version !== 1` / 5. `role` が文字列でない / 6. `role` が値域外 /
7. `generated_at_commit` が 40 桁小文字 hex でない / 8. `entries` が object でない (`[]` を含む) /
9. キーが repo-relative な単一ファイルパスでない (**差し替え点**) / 10. 値が 64 桁 hex でない /
11. キーが昇順でない。

**JSON の重複キーは `json_decode()` では検出できない** (後勝ちで潰れる)。そこで
利用側 (F1) が**正準形バイト一致**を要求する:

```php
// 正本のバイト列が、解釈して直列化し直した正準形と 1 バイトも違わないこと
expect($rawJson)->toBe(FingerprintLedger::fromJson($rawJson)->toJson());
```

これで重複キー・非正準な整形・キー順の崩れ・末尾改行の欠落がまとめて落ちる。
`entries` が空 object の場合は F4 (母集合の非空) が落とす。
**正典はこの検査を持たない**ので、過剰検出寄りへの上積みとして D33 に書く。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`self` / `bool` / `string`)
- [x] null 安全 (`json_decode` は `JSON_THROW_ON_ERROR`、`?? null` の後に型検査)
- [x] DTO を返している (配列返却なし。`entries` は `array<string, string>` を PHPDoc で明示)
- [x] Generics の型パラメータ: 該当なし (`list<string>` / `array<string, string>` を明示)
- 注: 前述のとおり `tests/` は PHPStan の解析対象外である。型は**人が書いて負例で担保する**

### テスト計画
- [ ] 負例 (S7): 上記 **11 形**すべてで `fromJson()` が `RuntimeException` を投げる
      (Pest の dataset 名を正本とし、本文の件数と一致させる)
- [ ] 負例 (S7): `RepoRelativePath::isValid()` が 8 形すべてで false、正当なパスで true
- [ ] 正例 (S7): 現物の `docs/template-fingerprints.json` が `fromJson()` を通る
- [ ] byte 一致の裏取り: 移植 2 本が正典と byte 一致であることを**移植時に `shasum` で確認**し、
      以後は **F9 (3a)** が drift を検出する (専用テストは作らない = 同じ事実を 2 か所で検査しない)

### リスク
- 正典が `SharedPathRules::isValidRepoRelativePath()` の判定を変えたとき、本リポジトリの
  `RepoRelativePath` は追従しない。D33 の「再判定の条件」にこれを書く

---

## S3: 母集合の列挙と生成ロジック

### 変更箇所
- 新規: `tests/Support/TemplateDivergence/TrackedRepositoryFiles.php`
- 新規: `tests/Support/TemplateDivergence/AppFingerprintBuilder.php`
- 新規: `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` (正典から移植 + docblock 1 段落)
- 新規: `tests/Support/TemplateDivergence/AtomicTextWriter.php` (平文の一覧用。本リポジトリ固有)

### 走査根の単一出典についての判断

AGENTS.md は「git 追跡下の **PHP 全数**を母集団にする走査は `Tests\Support\TrackedPhpSourceFiles` を
使う」と定めている。本設計の母集合は**拡張子を問わない全追跡ファイル**なので
`TrackedPhpSourceFiles` (`-- *.php` 限定) は使えない。
既に全追跡ファイルを列挙している検査は 3 本ある
(`BughuntNamingResidualTest` / `RouteCacheExemptionPremiseTest` / `GitIndexNormalizationTest`) が、
**この 3 本へ寄せる作業はしない**:

- `GitIndexNormalizationTest` は NFC/NFD の判定のため **index の生バイト列**を読む必要があり、
  正規化・絞り込みを行う共通化と両立しない
- 残る 2 本は自分の走査根と床値を自前で pin しており、共通化しても不変条件は増えない
  (AGENTS.md の単一出典要求は PHP 全数の走査に向けられている)

したがって `TemplateDivergence` 名前空間の中に閉じた列挙器を 1 本置き、
**他 gate へ広げる主張はしない** (docblock に明記)。

### 変更後コード (要点)

```php
// tests/Support/TemplateDivergence/TrackedRepositoryFiles.php
/**
 * git 追跡下の全ファイルを列挙する (拡張子で絞らない)。
 *
 * ★`Tests\Support\TrackedPhpSourceFiles` は `-- *.php` 限定なので本用途には使えない。
 *   本クラスは **TemplateDivergence の検査専用**であり、他 gate の走査根を置き換える
 *   主張はしない (寄せる作業に見合う不変条件の増加が無い)。
 * ★**保証しないもの**: 未追跡ファイルは列挙しない (gate が守る境界は commit / CI)。
 *   git が無い / 失敗した場合は**空を返さず例外にする** (fail-open 防止)。
 *   index に残っているが working tree に無いパスも列挙する (削除の検出は利用側が行う)。
 */
final class TrackedRepositoryFiles
{
    /** @return list<string> repo-relative パスの昇順 */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z'], $root);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ls-files の実行に失敗した (実行不能は fail): '.$process->getErrorOutput());
        }
        $paths = array_values(array_filter(explode("\0", $process->getOutput()), fn (string $p): bool => $p !== ''));
        sort($paths, SORT_STRING);

        return $paths;
    }
}
```

```php
// tests/Support/TemplateDivergence/AtomicTextWriter.php
/**
 * 平文の一覧 (採用時債務一覧) の原子的置換。
 *
 * `AtomicLedgerWriter` と同じ 5 つの契約 (同一ディレクトリ / dirname 不一致は書き込み前に fail /
 * 書き込みバイト長の確認 / **読み戻しの検証** / 失敗時は一時ファイルの掃除) を持つ。
 * 違いは読み戻しの検証を**注入された検証関数**が行う点だけである
 * (JSON 専用の `FingerprintLedger::fromJson()` を平文に使えないため)。
 *
 * ★**失敗は例外で返す** (`replace(): void` + `RuntimeException`)。
 *   移植元の `AtomicLedgerWriter::replace()` は失敗理由を戻り値で返す形なので、
 *   **呼び出し側が戻り値を無視すると fail-open になる**。本クラスは新規なので
 *   その形を持ち込まない。`AtomicLedgerWriter` を呼ぶ側 (生成器) は
 *   **戻り値が null でなければ即座に exit 1 する**ことをコードとテストで固定する。
 *
 * @param  callable(string): ?string  $validator  読み戻した内容の検証 (null = 合格 / 文字列 = 失敗理由)
 */
final class AtomicTextWriter { public static function replace(/* … */): void { /* 失敗は例外 */ } }
```

```php
// tests/Support/TemplateDivergence/AppFingerprintBuilder.php
/**
 * 正典の指紋台帳と自リポジトリの追跡ファイルから、role: app の指紋台帳と
 * 採用時債務一覧を組み立てる (純関数。I/O は注入する)。
 *
 * 母集合 = {正典の指紋台帳のキー} ∩ {自リポジトリの git 追跡ファイル}。
 * 値は**正典側の sha256 をそのまま写す** (テンプレート側の内容の指紋である)。
 *
 * **保証しないもの**: 正典側に存在しないパス (自リポジトリの追加) は母集合に入らない。
 * 正典側にしか無いパス (未受領 / 追従遅れ) も母集合に入らない。
 */
final class AppFingerprintBuilder
{
    /**
     * @param  list<string>  $trackedPaths  git 追跡ファイル (昇順・重複なし。重複や不正パスは例外)
     * @param  callable(string): string  $hasher  自リポジトリのファイルの sha256
     *   (**戻り値が 64 桁小文字 hex でなければ例外**。失敗も例外)
     * @param  list<string>  $registeredTargetPaths  登録簿の全対象パス
     *   (重複・`RepoRelativePath::isValid()` を通らない値があれば例外)
     * @param  array<string, string>  $existingDebt  既存の債務一覧 (path => 採用時のアプリ側 sha256)
     * @return array{ledger: FingerprintLedger, debt: array<string, string>, matched: int, mismatched: int, addedDebt: list<string>}
     */
    public static function build(
        FingerprintLedger $templateLedger,
        array $trackedPaths,
        callable $hasher,
        array $registeredTargetPaths,
        array $existingDebt,
        ?FingerprintLedger $previousLedger,
    ): array { /* … */ }
}
```

`build()` の規則:
1. `$templateLedger->role !== LedgerRole::Template` なら例外 (入力が正典の台帳でない)
2. 母集合の決め方 (**縮小規則**。Codex Round 2 [Critical] 対応):
   - **初回生成** (`$previousLedger === null`): 母集合 = 新正典キー ∩ 現在の追跡パス
   - **2 回目以降**: 母集合 = 新正典キー ∩ (現在の追跡パス **∪ 旧アプリ台帳のキー**)
   これで**ローカルでファイルを消してから再生成しても母集合から外せない**
   (旧キーが残り、gate では `MissingCurrent` になる)。母集合から外れるのは
   **正典側から消えたパスだけ**である。
   同じ正典入力 (sha256 が pin と一致) では**母集合の縮小そのものを拒否**する (exit 3)
3. 母集合が **0 件なら例外** (正典 (5b))
4. 各母集合パスについて自リポジトリのハッシュを計算する。
   symlink / regular file でない / 読めない → **例外** (正典 (5c)。黙って除外しない)。
   旧キーで working tree に無いものは「消えた」として記録し、ハッシュ計算の対象にしない
5. 債務の更新規則 (**採用時のアプリ側ハッシュを保持する**):
   - **維持**: 既存の債務パスは**採用時ハッシュをそのまま持ち越す** (凍結された観測なので更新しない)
   - **削除**: 内容が正典と一致へ戻った / 登録簿へ登録された パスは債務から外す
   - **追加**: 既存の債務に無いパスの追加は**原則として例外**。許すのは
     `$previousLedger` (載せ替え前の指紋台帳) にそのパスがあり、
     **現在のアプリ側ハッシュが載せ替え前の正典ハッシュと一致する**ときだけである
     (= 差が生じた原因がテンプレート側の前進であることの証明)。
     それ以外は「自分が変えたのに登録していない食い違い」なので**生成器では通さない**
     (登録を書くか内容を戻す。§S4 の [Critical] 対応)
6. 出力の `role` は `App`、`generated_at_commit` は**正典台帳の値をそのまま写す**。
   債務一覧の**先頭行はヘッダ** `# template_ledger_commit=<40 桁 hex>` で、
   同じ値を書く (2 生成物の**世代識別子**。§S6 の F14 が突き合わせる)

### プロセス起動の既存 gate への波及 (確認済み)

`Process` / `proc_open` を扱う既存の Architecture gate は
`FfmpegProcessLaunchInventoryTest` だが、**走査根は `app/` 配下のみ**であり
(実測: `Finder::create()->files()->in(base_path('app'))`)、`tests/` と `scripts/` は
母集団に入らない。よって目録への登録は不要である。
`tests/` で `Symfony\Component\Process\Process` を使う先例は
`TrackedPhpSourceFiles` / `BughuntNamingResidualTest` / `RouteCacheExemptionPremiseTest` にある。

### テスト計画
- [ ] 負例: 正典台帳の `role` が app → 例外
- [ ] 負例: 積集合が 0 件 → 例外
- [ ] 負例: ハッシュ計算が失敗する / 64 桁 hex を返さないパスがある → 例外 (空へ潰さない)
- [ ] 負例: `$trackedPaths` / `$registeredTargetPaths` に重複・不正パスがある → 例外
- [ ] 負例: 既存債務に無いパスを債務へ追加しようとする (載せ替えでない / 前世代のハッシュと
      一致しない) → 例外
- [ ] 正例: 一致 / 相違 / 登録済み相違 / 既存債務 を混ぜた合成入力で、母集合・債務・件数が期待どおり
- [ ] 正例: 載せ替えで「テンプレート側が動いたことによる新規債務」が追加できる
- [ ] `TrackedRepositoryFiles`: git 失敗を模した root (git でないディレクトリ) で例外
- [ ] `AtomicLedgerWriter` / `AtomicTextWriter`: **dataset 8 件** =
      正常系 1 + 失敗 7 (一時パス生成失敗 / dirname 不一致 / 短い書き込み / readback 失敗 /
      検証失敗 / rename 失敗 / 一時ファイル削除失敗)。dataset 名を件数の正本とする。
      **「正典でテスト済み」は本リポジトリのテストの代替にしない**
- [ ] 負例: 同じ正典入力で母集合を縮小しようとすると拒否される /
      ローカル削除したパスが母集合に残り `MissingCurrent` になる /
      正典側から消えたパスは母集合から外れる / 初回生成は現在の追跡パスとの積になる

### リスク
- `git ls-files -z` は index に残る削除済みパスも返す。母集合には入るが F7 が
  `MissingCurrent` として扱い 3a 側へ倒れる (過剰検出寄り = 正典 (7) に一致)

---

## S4: 生成器と指紋台帳・債務一覧の生成

### 変更箇所
- 新規: `scripts/update-template-fingerprints.php` (正典と同名・内容は本リポジトリ向け)
- 新規 (生成物): `docs/template-fingerprints.json`
- 新規 (生成物): `tests/Support/TemplateDivergence/adoption-debt.tsv`

### 生成物を 2 本に分ける理由と債務一覧の書式

債務一覧を指紋台帳 JSON へ入れると**正典の schema から外れる** (家系の可搬性が落ちる)。
PHP のクラス定数として生成すると生成器が PHP コードを書くことになる。
よって**平文のデータファイル**にする (PR の差分が 1 行 = 1 パスで読める)。

書式は**1 行 1 件・タブ区切りの 2 列**である:

```
<repo-relative パス>\t<採用時のアプリ側 sha256>
```

**採用時のアプリ側ハッシュを持つことが要点である** (Codex Round 1 [Critical])。
パスだけを持つと「そのパスは食い違っていればいつでも合格」になり、
凍結された観測ではなく**176 パスに対する恒久的な許可一覧**になってしまう。
ハッシュを持てば「採用時の姿のまま」と「採用後に手を入れた」を区別でき、
後者は違反として落とせる (§S5 の `mutatedDebtPaths`)。

### 使い方と終了コード

```
php scripts/update-template-fingerprints.php --template-ledger=<path> [--adopt-new-template-ledger]
```

| 終了コード | 意味 | 生成物 |
|---|---|---|
| 0 | 生成成功 (両生成物を置換し、3 つの pin 値を標準出力へ出す) | 更新 |
| 3 | ガードによる拒否 (既存台帳が `role: template` / 入力の sha256 が pin と不一致でフラグ無し / **債務へ新規パスを追加しようとした**) | **1 バイトも変えない** |
| 1 | 実行不能。**書き込み開始前**の失敗 (入力が読めない・解釈できない・引数が不正・未知/重複オプション・git 失敗・母集合 0 件・ハッシュ計算失敗) | **1 バイトも変えない** |
| 1 | 実行不能。**書き込み開始後**の I/O 失敗 | 片方だけ更新され得る。ただし件数 pin が合わなくなるので **gate が必ず赤になる** |

- **両生成物の内容は書き込みを始める前に完全に組み立て、検証まで終える** (
  組み立て中の失敗で正本に触れないため)。異なるディレクトリの 2 ファイルなので
  **セット単位の原子性は主張しない**。上表の 4 行目が受け入れた帰結である
- **入力の出自の検査**: 入力ファイルの sha256 が
  `LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256` と一致しなければ exit 3。
  `--adopt-new-template-ledger` を明示したときだけ通し、**新しい pin 値 2 つを標準出力へ出す**
- **入力の構造の検査**: `FingerprintLedger::fromJson()` を通し、`role: template` を要求する。
  さらに**入力自身の正準形バイト一致** (`$templateRaw === FingerprintLedger::fromJson($templateRaw)->toJson()`)
  を要求する。一致しなければ**書き込み前の exit 1**。これが無いと
  `--adopt-new-template-ledger` の経路で**重複キーを含む非正準な JSON を採用できる**
  (Codex Round 2 [Warning])
- **債務への追加は正規経路でも通さない** (§S3 の規則 4)。入力の sha256 が pin と同じなら
  追加は常に拒否 (exit 3)。載せ替え時でも、追加できるのは「現在のアプリ側ハッシュが
  **載せ替え前の**正典ハッシュと一致する」パスだけである。それ以外は
  拒否メッセージで「登録を書くか内容を戻せ」と告げる
- 出力は `AtomicLedgerWriter::replace()` (JSON) と `AtomicTextWriter::replace()` (債務一覧) 経由。
  どちらも読み戻して検証してから rename する
- 標準出力・標準エラーは `fwrite()` で書く (`echo` 禁止)。`declare(strict_types=1)` を持つ

### 入力の取得手順 (実装者向け・CI では走らせない)

正典の指紋台帳は lctl の `get_source(project: laravel-claude-template,
path: docs/template-fingerprints.json, ref: 0597a0c…)` で取得し、
`content` を**そのままファイルへ保存**して `--template-ledger` に渡す
(devnotes 配下の一時ファイルに置き、コミットはしない)。
取得した台帳ファイルの sha256 を `LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256` に、
その `generated_at_commit` を `LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT` に入れる。

### 生成器の構造 (テスト可能性のための分離)

CLI が `dirname(__DIR__)` で自分のリポジトリを指す作りだと、**プロセスを一時リポジトリで
起動しても出力先は本物のリポジトリになる** = テストが実生成物を書き換える危険がある
(Codex Round 2 [Critical])。よって 2 層に分ける:

- `scripts/update-template-fingerprints.php` = **薄い引数解析層のみ**。
  引数を解釈し、`FingerprintGenerationService` を呼び、終了コードへ写す。
  root は `dirname(__DIR__)` 固定 (**root を差し替える隠しオプションは作らない**)
- `tests/Support/TemplateDivergence/FingerprintGenerationService.php` =
  **root・入力・出力先・writer・git 実行をすべて引数で受ける**通常のクラス。
  生成の判定はここに閉じる

テストの割り当ては次のとおり:

| 何を | どうテストするか |
|---|---|
| 生成の成否・債務の更新規則・母集合の縮小規則・部分更新 | `FingerprintGenerationService` を**一時ディレクトリを root にして直接呼ぶ** (プロセスを起動しない) |
| 引数解析・終了コードの写像 | **実プロセス**を起動する。ただし**書き込み前に終了する経路だけ** (引数の欠落・未知オプション・重複オプション・入力が読めない) なので、本物の生成物には触れない |

### テスト計画

- [ ] service: 正しい入力で両生成物が書かれ、3 つの pin 値が報告に含まれる
- [ ] service: 拒否 3 経路 (既存台帳が `role: template` / 入力の sha256 が pin と不一致でフラグ無し /
      債務へ新規パスを追加しようとした / 同じ入力で母集合を縮小しようとした) で
      **生成物のバイト列が 1 ビットも変わらない**
- [ ] service: 書き込み前の失敗 (JSON が壊れている / 入力が非正準 / 母集合 0 件) で生成物が不変
- [ ] service: **部分更新の 3 状態を失敗注入で作り、gate 相当の判定が実際に落ちること**を固定する —
      (a) JSON だけ新世代 / (b) 債務一覧だけ新世代 / (c) 件数は同じで内容だけ違う部分更新。
      **(c) を確実に赤にするのが F14 (世代識別子の突き合わせ) である** (件数 pin だけでは
      増減が相殺されて緑になり得る。Codex Round 2 [Warning])
- [ ] service: 載せ替えで前世代ハッシュと一致する新規債務は通り、一致しないものは拒否される
- [ ] プロセス: 引数の欠落 / 未知オプション / 重複オプション / 入力ファイル不在 → exit 1 で生成物が不変
- [ ] プロセス: `AtomicLedgerWriter::replace()` の戻り値が null でないときに exit 1 することを
      service 経由で固定する (戻り値を無視して成功扱いにしない)
- [ ] 本リポジトリでの実行は**実装時に 1 回**行い、生成物をコミットする (CI では走らせない)

### リスク
- 生成器の再実行で債務一覧を膨らませて 3a を黙らせる経路は上記のガードで塞ぐ。
  ただし**指紋台帳・債務一覧・pin・gate 自身の手編集は機械では止まらない**
  (原理的限界。PR レビューの義務。正典の「保証しないこと 4」と同型)
- 一時 git リポジトリを作るテストは `HOME` / `GIT_*` 環境変数の影響を受ける。
  `git -c init.defaultBranch=main -c user.email=... -c user.name=...` を明示して
  グローバル設定に依存しない

---

## S5: 突合と債務の判定 (純関数)

### 変更箇所
- 新規: `tests/Support/TemplateDivergence/LedgerPins.php`
- 新規: `tests/Support/TemplateDivergence/AdoptionDebtInventory.php`
- 新規: `tests/Support/TemplateDivergence/FingerprintReconciler.php`
- 新規: `tests/Support/TemplateDivergence/ReconciliationResult.php`
- 新規: `tests/Support/TemplateDivergence/PathObservation.php`

### 正典の `FingerprintComparator` を移植しない判断

正典の突合は `DivergenceEntry` (対象パスを**1 件**持つ DTO) を前提にしている。
一方、家系の統一形式は**対象パスの複数指定を許す** (正典 design の s3 / i5) し、
本リポジトリの解析器 `DivergenceLedgerParser` は既に複数パスを返す。
正典の DTO へ詰め替えると、本リポジトリに存在しない正規化 (`commit:<sha>` /
`todo:T012` 形式の根拠) を**でっち上げる**ことになる。
正典自身が「検査の本数・クラス名・ファイル配置は不変条件に含めない」(s8) と定めているので、
**同じ等式を本リポジトリのモデルで実装**し、その差を D33 に登録する。

### 変更後コード (要点)

```php
// tests/Support/TemplateDivergence/LedgerPins.php
/**
 * 逸脱台帳と指紋台帳の固定値 (不変の scalar 定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 * ★これは免除の一覧ではない。個別のパスや D 番号を名指しして規則を免除する仕組みは無い。
 */
final class LedgerPins
{
    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 32;

    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
    public const int FINGERPRINT_POPULATION_COUNT = 281;

    /**
     * 採用時債務の件数。
     *
     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
     */
    public const int ADOPTION_DEBT_COUNT = 176;

    /** 取り込んだ正典台帳の generated_at_commit。 */
    public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';

    /** 取り込んだ正典台帳ファイル自身の sha256 (生成器の入力ガード)。 */
    public const string TEMPLATE_LEDGER_SOURCE_SHA256 = '<実装時に確定>';
}
```

```php
// tests/Support/TemplateDivergence/AdoptionDebtInventory.php
/**
 * 採用時債務一覧 — 「採用時点で内容が食い違っていたが、登録簿に説明が無いパス」と
 * **その時点のアプリ側 sha256**。
 *
 * ★**免除の許可一覧ではない**。採用時点の凍結された観測である。
 *   ハッシュを持つので「採用時の姿のまま」と「採用後に手を入れた」を区別でき、
 *   後者は違反になる (パスだけを持つ形は、そのパスに対する恒久的な許可一覧になってしまう)。
 * ★一覧が縮む契機は 2 つ = (1) 内容をテンプレートへ戻す /
 *   (2) 意図的逸脱として登録簿へ書く。期限による棚卸しは登録簿の D34
 *   (`監視中` + 見直し期限) が持つ。
 * ★**保証しないもの**: 一覧へ行を足す変更は機械では止まらない (生成器のガードと
 *   件数 pin の PR 差分に依存する)。各パスが意図的逸脱なのか追従遅れなのかは分類していない。
 */
final class AdoptionDebtInventory
{
    public const string INVENTORY_PATH = 'tests/Support/TemplateDivergence/adoption-debt.tsv';

    /**
     * データファイルを読んで検証済みの `path => 採用時 sha256` を返す。
     *
     * 落とす形 (**11 形**): ファイルが読めない / 空 /
     * 先頭行が `# template_ledger_commit=<40 桁 hex>` でない / 末尾改行が無い / 空行 /
     * 列がタブ 2 列でない / 前後に空白がある / パスの重複 /
     * パスが `RepoRelativePath::isValid()` を通らない / ハッシュが 64 桁小文字 hex でない /
     * パスの昇順でない。
     *
     * ヘッダの値は**世代識別子**として利用側 (F14) が指紋台帳の
     * `generated_at_commit` と突き合わせる (2 生成物の片方だけが更新された状態を落とすため)。
     *
     * @return array{templateLedgerCommit: string, entries: array<string, string>}
     * @throws RuntimeException
     */
    public static function read(string $root): array { /* … */ }
}
```

```php
// tests/Support/TemplateDivergence/FingerprintReconciler.php
/**
 * 3a / 3b と債務規則の判定 (純関数)。
 *
 * 突合の本体は**集合の等式 1 本**である:
 *   {母集合のうち不一致のパス} == ({全登録の対象パス} ∩ {母集合}) ∪ {債務一覧のパス}
 * 等式なので ⊃ (不一致なのに未登録 = 3a) も ⊂ (一致へ戻ったのに登録が残る = 3b) も落ちる。
 * 債務側はさらに**採用時ハッシュとの一致**まで見る (下記 (3)(4))。
 *
 * ★**登録の状態 (`恒久` / `監視中`) は読まない**。状態を突合のフィルタにすると、
 *   内容をテンプレートへ戻した後に状態だけ変えて 3b を回避できてしまう。
 * ★結果は**種別ごとに分けて返す** (集めて使わない形を作らないため = 共通規約 (d))。
 */
final readonly class ReconciliationResult
{
    /**
     * @param  list<string>  $unregisteredMismatches  3a: 不一致なのに登録も債務も無い
     * @param  list<string>  $staleRegistrations  3b: 一致へ戻ったのに登録が残っている
     * @param  list<string>  $resolvedDebtPaths  債務規則 (i): 一致へ戻ったのに債務一覧に残っている
     * @param  list<string>  $mutatedDebtPaths  債務規則 (i'): 採用時の姿から変わっている (登録するか戻す)
     * @param  list<string>  $doubleDeclaredPaths  債務規則 (ii): 債務と登録の二重宣言
     * @param  list<string>  $debtPathsOutsidePopulation  債務一覧に母集合外のパスがある
     * @param  list<string>  $duplicateRegisteredPaths  同一パスを 2 つ以上の登録が挙げている
     * @param  list<string>  $inspectionFailures  検査不能 (symlink / 非 regular file / 読めない)
     */
    public function __construct(
        public array $unregisteredMismatches,
        public array $staleRegistrations,
        public array $resolvedDebtPaths,
        public array $mutatedDebtPaths,
        public array $doubleDeclaredPaths,
        public array $debtPathsOutsidePopulation,
        public array $duplicateRegisteredPaths,
        public array $inspectionFailures,
    ) {}

    public function isClean(): bool { /* 8 つすべてが空 */ }
}
```

`FingerprintReconciler::reconcile()` の引数:
- `array<string, PathObservation> $observations` (母集合の全キー。
  `PathObservation` は `tests/Support/TemplateDivergence/PathObservation.php` の readonly DTO で、
  状態 (`ComparisonState`) + 現在のアプリ側ハッシュ (`?string`) + 検査不能の理由 (`?string`) を持ち、
  **不変条件は「`Matched` / `ContentMismatch` なら hash は非 null かつ理由は null」
  「検査不能なら理由が非 null かつ hash は null」**をコンストラクタで検査する)
- `list<array{path: string, label: string}> $registered` (**リストで受ける**。
  同一パスの重複を突合器自身が検出するため。`array<string, string>` で受けると
  配列構築の時点で後勝ちに潰れて重複が見えなくなる)
- `array<string, string> $debt` (パス => 採用時のアプリ側ハッシュ)
- `array<string, string> $templateHashes` (母集合のパス => 正典側ハッシュ)

判定順序 (すべて評価してから返す。早期 return しない = どの違反も 1 回の実行で全部見える):

1. 検査不能の観測がある → `inspectionFailures` (**`MissingCurrent` へ畳まない**)
2. 登録の対象パスに重複がある → `duplicateRegisteredPaths`
3. 債務が母集合外 → `debtPathsOutsidePopulation` / 債務 ∩ 登録 → `doubleDeclaredPaths`
4. 債務パスの現況で 3 分岐:
   - 現在のハッシュ == 採用時ハッシュ → **未解消債務として許容** (違反にしない)
   - 現在のハッシュ == 正典ハッシュ → `resolvedDebtPaths` (一覧から削れ)
   - どちらとも異なる / 削除されている → `mutatedDebtPaths`
     (登録を書くか、採用時の姿へ戻すか、テンプレートへ同期して債務から削る)
5. 母集合 − 債務 の範囲で不一致 (`ContentMismatch` / `MissingCurrent`) かつ未登録
   → `unregisteredMismatches`
6. 母集合 − 債務 の範囲で登録済みかつ `Matched` → `staleRegistrations`

### PHPStan適合チェック
- [x] 戻り値の型が明示 (`ReconciliationResult`)
- [x] null 安全 (引数はすべて型付き。`?? null` の後に型検査)
- [x] DTO を返している (`list<string>` を 8 本持つ readonly DTO)
- [x] Generics: `array<string, PathObservation>` / `list<array{path: string, label: string}>` /
      `array<string, string>` を PHPDoc で明示

### 形式検査との依存関係 (docblock に明記する)

突合器は登録簿の**解析結果**を入力に取るので、解析が成功していることを前提にする。
その前提は**同じ gate の中で** F13 (解析が成功していること) が確かめる
(別テストの成否に暗黙に依存しない)。対象パスの書式・実在・値域の検査は形式検査 (TD3) が持ち、
突合器は**重複だけ**を自分で検出する (重複は突合の正しさに直接効くため)。

### テスト計画 (S7 で実施)
- [ ] 3a が発火する / しない (登録済み・債務なら発火しない)
- [ ] 3b が発火する / しない
- [ ] 債務: 採用時ハッシュのままなら合格 / 正典一致なら `resolvedDebtPaths` /
      どちらとも違えば `mutatedDebtPaths` / 削除でも `mutatedDebtPaths`
- [ ] `doubleDeclaredPaths` / `debtPathsOutsidePopulation` / `duplicateRegisteredPaths` /
      `inspectionFailures` がそれぞれ発火する
- [ ] `MissingCurrent` (非債務・未登録) が 3a 側へ倒れる
- [ ] `AdoptionDebtInventory::read()` が **11 形**の壊れた入力で例外

### リスク
- 8 種別のうち 1 つでも gate が assert しないと (d) 違反になる。F8〜F11 で**全種別を assert**し、
  gate 側に「`ReconciliationResult` の全プロパティを見たか」を人が確認できるよう
  `isClean()` ではなく**種別ごとに個別 assert** する

---

## S6: 突合 gate

### 変更箇所
- 新規: `tests/Architecture/TemplateDivergenceFingerprintTest.php`

### 検査一覧

| # | 検査 | 正典の対応 |
|---|---|---|
| F0 | 指紋台帳・登録簿・債務一覧が実在し読める。**読み取り失敗が例外になること**を負のコントロールで確認 | G0 / (5c) |
| F1 | 指紋台帳の schema が解釈でき、`role` が `App` である。かつ**正本のバイト列が正準形と完全一致**する (重複キー・非正準な整形・キー順の崩れを落とす) | G1 + 上積み |
| F2 | `composer.json` が読めて JSON として解釈でき、`name` が文字列で **`rio-development/aicue` と完全一致**する (欠落・別名・提供元名はすべて fail) | G2 |
| F3 | 母集合の件数が `LedgerPins::FINGERPRINT_POPULATION_COUNT` と完全一致 | G3 / (5d) |
| F4 | 母集合が非空 **かつ** git 追跡ファイルが非空 (走査の生存確認) | G5 / (5b) |
| F5 | 指紋台帳の `generated_at_commit` が `LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT` と一致 | (出自 pin。正典には無い上積み) |
| F6 | **本機構自身のファイルが母集合に含まれ、かつ regular file (symlink でない) である** (必須メンバ pin。検査を黙らせる変更自体が検査対象)。一覧が空でないことも見る | G4 + G7 |
| F7 | 母集合の各パスを観測する。`MissingCurrent` は**git index / working tree から消えた場合だけ**。symlink / 非 regular file / 読めない / ハッシュ失敗は**別種の「検査不能」**として記録する (畳まない) | G7 相当 + 上積み |
| F8 | `inspectionFailures` が 0 件 (検査不能を登録済み・債務で吸収させない = (5c)) | (正典には無い上積み) |
| F9 | 3a / 3b が 0 件 (`unregisteredMismatches` / `staleRegistrations`) | G10 |
| F10 | 債務規則: `resolvedDebtPaths` / `mutatedDebtPaths` / `doubleDeclaredPaths` / `debtPathsOutsidePopulation` / `duplicateRegisteredPaths` がすべて 0 件 | (正典には無い) |
| F11 | 債務件数が `LedgerPins::ADOPTION_DEBT_COUNT` と完全一致 | (5d) |
| F12 | 債務が非空の間、`AdoptionDebtInventory::INVENTORY_PATH` が登録簿の対象パスとして登録されている | (D34 の存在の機械化) |
| F13 | 登録簿の解析が成功していること (`unparsable === false` かつ `parseViolations` が空)。**解析違反を持つ入力から登録リストを組み立てない** (突合器へ渡す前に評価する)。解析不能なら**この gate も落ちる** | (5c) |
| F14 | **2 生成物の世代が揃っていること** — 債務一覧のヘッダ `# template_ledger_commit=` が指紋台帳の `generated_at_commit` と一致し、かつ各債務パスの**採用時ハッシュが正典ハッシュと異なる** (債務は定義上テンプレートと食い違っている)。片方だけ更新された状態を落とす | (正典には無い上積み) |

- **登録件数の pin は本 gate では見ない** (S8 の形式検査が 3 点一致を持つ)。
  同じ事実を 2 か所で検査しない
- **登録の対象パスの実在も本 gate では見ない** (形式検査の TD3 が担当)。
  ただし母集合内のパスの消滅は F7 が `MissingCurrent` として捕まえる
- 空ループで緑にならないことは F3 (母集合 281) と F11 (債務 176) の完全一致 pin が示す

### docblock に書く「保証しないもの」

1. **粒度はファイル単位**。共有ファイルの**内部**の逸脱 (規約の一部だけを変えた等) は検出しない
2. **母集合の外には沈黙する**。アプリ固有ファイル (提供元が `shared: false` と分類したもの。
   `AGENTS.md` / `tests/Pest.php` / `composer.json` / `docs/architecture.md` 等) と、
   正典側にしか無いパス (実測 672 件) は 1 件も見ない
3. **テンプレート更新への追従遅れは検出しない**。指紋は取り込んだ時点の写しなので、
   正典が先へ進んでも食い違いは生じない
4. **登録済みのパスの追加 drift は検出しない**。既に不一致で登録があるパスは、
   その後どれだけ内容が変わっても「不一致のまま」であり同じ判定になる
   (検出するのは**一致から不一致へ移る瞬間**である)。
   **債務パスは例外**で、採用時ハッシュとの一致まで見るので追加の変更は落ちる
5. **採用時債務 176 件の中身は説明されていない**。意図的逸脱と追従遅れの区別は付いていない
   (分類の契機は D34 の見直し期限)
6. **手編集による無効化は止まらない**。指紋台帳 / 債務一覧 / pin / 本 gate 自身の書き換えは
   検査を書き換えるのと等価であり、PR レビューの義務である。**F6 が保証するのは
   必須メンバが母集合に残り regular file であることまで**で、D33 で登録済みになった
   本 gate の**中身**は固定しない
7. **`generated_at_commit` の実在は検証しない** (別リポジトリの commit なので原理的に不可能)。
   書式と pin との一致だけを見る
8. **git 追跡外のファイルは母集合に入らない**
9. **本 gate は突合であって遮断ではない**。逸脱を作れなくするものではなく、
   登録なしに作れなくするものである
10. **債務一覧の増加は機械では止まらない**。生成器のガードと件数 pin の PR 差分に依存する
    (履歴を入力に取らないため、旧コミットとの比較はできない)

### テスト計画
- [ ] 本 gate 自体が現物で緑になること (`composer test` の Architecture レーン)
- [ ] F0 の負のコントロール (存在しないパスを読ませて例外になること) を gate 内に置く
- [ ] 判定の検出力は S7 (純関数の負例) が担う。**gate 側で合成入力を組まない**
      (薄い層に保つ = 正典 i12 / 既存形式検査と同じ構造)

### リスク
- F6 の必須メンバ一覧が本機構のファイル名と乖離するとザルになる。
  一覧は `LedgerPins` ではなく gate 内の定数に置き、**F6 が「一覧の各パスが母集合に在る」ことと
  「一覧が空でない」ことの両方**を見る

---

## S7: 負例・正例

### 変更箇所
- 新規: `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php`

### 負例と正例 (両方向。共通規約 (c))

| 対象 | 負例 (検出できること) | 正例 (誤検出しないこと) |
|---|---|---|
| `FingerprintLedger::fromJson()` | **11 形** (JSON 不正 / 最上位が object でない (`[]` を含む) / キー過不足 / schema 違い / role 非文字列 / role 値域外 / commit 書式 / entries 非 object (`[]` を含む) / キーが不正なパス / 値が sha256 でない / キー昇順でない)。Pest の dataset に**検査名を付けて**定義し、設計書の件数と一致させる | 現物の指紋台帳が通る |
| 正準形バイト一致 (F1) | 最上位キーの重複 / `entries` 内のパス重複 / 空 object と空配列の混同 / 整形の崩れ / 末尾改行なし | 現物の指紋台帳が `fromJson()->toJson()` と byte 一致 |
| `RepoRelativePath::isValid()` | 8 形 (空 / 絶対 / 空要素 / `.` / `..` / NUL / 末尾スラッシュ / 制御文字) | `tests/Pest.php` / `.claude/skills/app-design/SKILL.md` 等が true |
| `FingerprintReconciler` | **8 種別すべてを個別に発火させる** (とくに `mutatedDebtPaths` = 債務パスを採用時ハッシュから変えた場合、`inspectionFailures` = 債務パス / 登録済みパスが symlink・ディレクトリ・読み取り不能でも**許容されない**こと) | 一致・登録済み相違・採用時のままの債務だけの入力で 8 種別すべて空 |
| `AdoptionDebtInventory::read()` | **11 形** (読めない / 空 / ヘッダ行の書式 / 末尾改行なし / 空行 / タブ 2 列でない / 前後空白 / パス重複 / 不正パス / ハッシュ書式 / 昇順でない) | 現物の債務一覧が通る |
| `AppFingerprintBuilder::build()` | 入力が `role: app` / 積集合 0 件 / ハッシュ計算失敗 / hasher が 64 桁 hex を返さない / 追跡パスや登録パスの重複・不正 / **既存債務に無いパスの追加** / 母集合が縮む再生成 (パスが消えた場合の扱い) | 合成入力で母集合・債務・件数が期待どおり。載せ替えで前世代ハッシュと一致する新規債務は通る |
| `AtomicLedgerWriter` / `AtomicTextWriter` | **dataset 8 件** = 正常系 1 + 失敗 7 (一時パス生成失敗 / dirname 不一致 / 短い書き込み / readback 失敗 / 検証失敗 / rename 失敗 / 削除失敗)。dataset 名を件数の正本とする | 正常系で正本が置換される |
| `FingerprintGenerationService` | 拒否 4 経路 / 書き込み前失敗 3 経路での**生成物の不変**、**部分更新 3 状態** ((a) JSON だけ新世代 / (b) 債務一覧だけ新世代 / (c) 件数が同じで内容だけ違う) で**判定が赤になること** | 正しい入力で両生成物と 3 つの pin 値 |
| 生成器 (実プロセス) | 引数の欠落 / 未知オプション / 重複オプション / 入力ファイル不在 → exit 1。**書き込み前に終了する経路だけを扱う** (本物の生成物に触れないため) | — |
| `TrackedRepositoryFiles::all()` | git でないディレクトリを渡すと例外 | 本リポジトリの root で非空 |

- **件数の表記は Pest の dataset を正本とし、本文の「N 形」と必ず一致させる**
  (`FingerprintLedger` = 11 形 / `AdoptionDebtInventory` = 11 形 /
  `RepoRelativePath` = 8 形 / atomic writer = 8 件)
- テストファースト: **上記負例を先に書いて赤を確認**してから本体を書く (思考原則 5)
- 合成入力は gate 内ではなくこの単体テストに置く (AGENTS.md「負例の置き場は 3 通りとも認める」)。
  gate と検出器の docblock から本ファイルを辿れるようにする

### リスク
- 負例が「本体を書いた後に通る入力」で作られると裏取りにならない。
  赤を確認した順序を実装時のコミットで残す (テストのコミットを先に置く)

---

## S8: 件数 pin の一本化

### 変更箇所
- ファイル: `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` (L31-37, L71)

### 現行コード
```php
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 30;
// …
            pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
```

### 変更後コード
```php
use Tests\Support\TemplateDivergence\LedgerPins;
// …
            pinnedEntryCount: LedgerPins::DIVERGENCE_ENTRY_COUNT,
```

- Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
  新しい gate から参照すると読み込み順に依存する。値の置き場所を `LedgerPins` へ移して
  両 gate が同じ定数を読む形にする
- 件数の「3 点一致」の意味は変わらない (宣言行 / 見出しの実数 / **`LedgerPins` の固定値**)
- docblock も同時に直す (S10)

### 波及変更
- TypeScript 型定義: なし / API Resource: なし
- テストファイル: `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` は
  `LedgerContext` へ固定値を**引数で**渡しているので変更不要 (実測: `pinnedEntryCount:` を
  テスト内で明示している)

### テスト計画
- [ ] `composer test` の Architecture レーンで形式検査が緑
- [ ] **pin 不一致の負例は既存**である (`tests/Unit/Architecture/DivergenceLedgerRulesTest.php` の
      「TD12: 明示件数・解析件数・固定件数の 3 点一致を要求する」が dataset
      `固定件数が多い` / `固定件数が少ない` で両方向を固定済み。実測で確認)。
      本施策は pin の**置き場所**を移すだけなので負例の追加は不要

### リスク
- 本ファイルは母集合の中にあり、現在は債務一覧の候補である。S9 で D33 の対象パスへ移すので
  債務が 1 件縮む。**S8 と S9 は同じコミット (C2) に入れる**

---

## S9: 登録簿への D33 / D34 と保証範囲の書き換え

### 変更箇所
- ファイル: `docs/template-divergence.md` (L11 の件数宣言行 / L59-66 の保証しないもの / 末尾に D33・D34)

### 変更後コード (要点)

件数宣言行: `登録エントリ: 30 件` → `登録エントリ: 32 件`

§この登録簿が保証しないもの (書き換え。**削除ではなく範囲の縮小**):

```
## この登録簿が保証しないもの

- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
  再利用しないことは人が守る規約である)
- **実体との突合は別の検査が持つ** —
  `tests/Architecture/TemplateDivergenceFingerprintTest.php` が指紋台帳
  (`docs/template-fingerprints.json`) と実ファイルを突き合わせる。
  **形式検査 (`TemplateDivergenceLedgerFormatTest`) 自身は突合を持たない**
- **突合が保証しない範囲の正本は突合検査の docblock である** (ここには写さない。
  2 か所に書くと必ず食い違う)。突合が見ない範囲は台帳リポジトリの巡回が引き続き担う
  (家系の裁定 AG-159)
```

> **単一出典**: 保証しない範囲の 4 分類 (母集合の外 / ファイル内部 / 追従遅れ / 債務の分類) は
> **突合 gate の docblock だけに書く**。登録簿と `AGENTS.md` は「どこに書いてあるか」を指すだけに
> 留める (Codex Round 1 [Warning] の単一出典の指摘に対応)。

D33 (`恒久`) の登録メタ表:

| 行 | 内容 |
|---|---|
| 対象パス | `docs/template-fingerprints.json` / `tests/Architecture/TemplateDivergenceFingerprintTest.php` / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` / `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` / `tests/Support/TemplateDivergence/FingerprintLedger.php` / `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` / `scripts/update-template-fingerprints.php` |
| 業務要件起因の説明 | テンプレートの現物を CI に持てないため、母集合を正典の分類規則ではなく正典が公開する指紋台帳のキーで決める。突合は本アプリの登録簿が許す複数の対象パスに合わせて実装する |
| 揃え続ける不変条件と保証機構 | 3a / 3b の集合等式と fail-closed の 4 規約を保つ (`TemplateDivergenceFingerprintTest` / `TemplateDivergenceFingerprintRulesTest`) |
| 再判定の条件 | 正典が母集合の決め方・schema・パス検証の判定を変えたとき / テンプレートの現物を CI で引ける手段ができたとき |
| 決めた日 | **実装コミット当日** (下記の注記を必ず読む) |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260821-0000-template-divergence-fingerprint-t1/` |
| 状態 | 恒久 |
| 見直し期限 | — |

> **決めた日は未来日にできない** (TD8)。判定の基準日は形式検査が渡す
> `CarbonImmutable::today()` = **アプリの timezone における当日**である。
> 設計時点 (JST 2026-08-21 00:xx) は UTC ではまだ 2026-08-20 なので、
> **`2026-08-21` と書くと基準日が UTC 判定の環境で未来日として落ちる**。
> よって**両エントリの決めた日は `2026-08-20` を使う** (どちらの timezone でも過去日)。
> 実装が日を跨いだ場合も、**基準日以前であることを `config/app.php` の timezone で
> 確認してから**書く。

D34 (`監視中`) の登録メタ表:

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` |
| 業務要件起因の説明 | テンプレートの現物が CI に無いため、採用時点で食い違っていた 176 件が意図的逸脱なのか追従遅れなのかを機械では区別できない。区別が付くまで採用時の姿を凍結して扱う層を持つ |
| 揃え続ける不変条件と保証機構 | 債務パスは採用時のアプリ側ハッシュのまま留まること。変えたら `mutatedDebtPaths`、テンプレート一致へ戻ったら `resolvedDebtPaths` が落とす (`TemplateDivergenceFingerprintTest` の F10 / F11) |
| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す) / テンプレート更新の一括取り込みを行うとき / 債務パスの分類が付いたとき |
| 決めた日 | **実装コミット当日** (D33 と同じ注記に従う。`2026-08-20`) |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260821-0000-template-divergence-fingerprint-t1/` |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-28 |

> **D34 は「176 件の逸脱を正当化する登録」ではない**。登録簿の冒頭は
> 「互換・UX・**作業量**を理由にした逸脱は記録せず是正する」と定めており、
> 「176 件書くのは大変だから」は逸脱の理由になり得ない。D34 が登録するのは
> **未分類の債務を期限付きで管理する安全機構を持つこと**そのものであり、
> その業務要件起因は「テンプレートの現物が CI に無く、意図的逸脱と追従遅れを機械で
> 区別できない」ことである。**分類を先送りする言い訳ではなく、
> 先送りを期限付きで可視化する装置**として登録する (期限切れは CI の赤 = 是正の強制)。

- 見直し期限 2027-02-28 は基準日 (2026-08-20) から 192 日で、上限 400 日の内側
- 各エントリは本文に「保証しないもの」の節を持つ (D25 以降の水準に合わせる)
- **対象パスの重複が無いことを確認済み** (実測: 既存 88 パスに上記 9 パスは 1 件も無い)。
  `AtomicLedgerWriter.php` を D33 に含めるのを忘れると、母集合の中で
  「相違かつ未登録」になって 3a が発火する (整合式の 27 件に数えてある)

### テスト計画
- [ ] 形式検査 (TD1〜TD12) が緑: 9 行ちょうど・値域・期限・件数の 3 点一致
- [ ] F12 (債務一覧のファイルが登録済み) が緑
- [ ] 期限切れ・未来日の負例は**既存の単体テスト**が両方向で固定済み (TD6 / TD8)。追加不要

### リスク
- D34 の期限が切れると CI が赤になる。これは**仕様**であり、直し方は登録簿の規約節の 4 通り
  (検査を緩めることは選択肢に入れない)

---

## S10: AG-159 の責務の縮小

### 変更箇所
- ファイル: `AGENTS.md` §テンプレートとの関係
- ファイル: `tests/Support/TemplateDivergence/DivergenceLedgerRules.php` (docblock L13-19)

### 現行コード (AGENTS.md)
```
**書式の正本は同ファイルの規約節**で、形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する
(登録メタ表の 9 行・状態の値域・対象パスの実在と重複・件数の 3 点一致)。
書式の中身は本書に写さない (2 か所に書くと必ず食い違う)。
```

### 変更後コード (AGENTS.md。追記は 1 段落に留める)
```
**書式の正本は同ファイルの規約節**で、形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する
(登録メタ表の 9 行・状態の値域・対象パスの実在と重複・件数の 3 点一致)。
書式の中身は本書に写さない (2 か所に書くと必ず食い違う)。

**実体との突合は `tests/Architecture/TemplateDivergenceFingerprintTest.php` が持つ**
(家系の正典 t1)。指紋台帳 `docs/template-fingerprints.json` (テンプレート側の内容の
sha256。母集合は正典の指紋台帳のキーと自リポジトリの追跡ファイルの積集合) と実ファイルを
突き合わせ、食い違いに登録が無い場合と、内容が一致へ戻ったのに登録が残っている場合を落とす。
共有ファイルを変えたら**同じ変更で**登録を足す (または戻す)。
採用時点で説明が無い食い違いは `tests/Support/TemplateDivergence/adoption-debt.tsv` に
**採用時のアプリ側 sha256 つきで**凍結して列挙してある (D34。期限付きで縮める)。
検出するのは**テンプレートと一致していた状態から新たに不一致になった、未登録かつ
非債務のパス**と、**債務パスが採用時の姿から変わったこと**である。
**保証しないものの正本は突合 gate の docblock** であり、本書に写さない。
```

### 現行コード (DivergenceLedgerRules docblock)
```php
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
 *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159)
 *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
```

### 変更後コード
```php
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは**本検査では**検出しない。
 *    突合は `tests/Architecture/TemplateDivergenceFingerprintTest.php` が持つ (家系の正典 t1)
 *  - 内容がテンプレート準拠へ戻った登録の残置も**本検査では**検出しない (同上)
 *  - 突合が見ない範囲 (母集合の外・ファイル内部の逸脱・追従遅れ・債務の分類) は
 *    台帳リポジトリの巡回が引き続き担う (家系の裁定 AG-159)
```

### 波及変更
- `docs/template-divergence.md` の §保証しないもの は S9 で直す (同じ内容を 3 か所に書かない
  ため、AGENTS.md には突合の存在と入口だけを書き、詳細は gate の docblock に置く)
- `docs/architecture.md`: **追記しない** (保証範囲の正本を gate の docblock 1 か所に置く方針)

### テスト計画
- [ ] 文書の変更のみ。`composer test` / `pnpm test` に影響しないことを確認
- [ ] `AGENTS.md` は母集合の外なので 3a を発火させない (実測で確認済み)

### リスク
- 保証範囲の記述が 4 か所 (AGENTS.md / 登録簿 / 形式検査 docblock / 突合 gate docblock) に散る。
  **正本は突合 gate の docblock 1 つ**と決め、他 3 か所は「どこが持つか」だけを書く

---

## S11: 登録の契機 (t3 要素)

### 変更箇所
- ファイル: `.claude/skills/app-design/SKILL.md` (Phase 3 冒頭)
- ファイル: `.claude/skills/app-implement/SKILL.md` (コミット直前の段)

### 変更後コード (app-design。要点)
```markdown
### 3-0. 乖離台帳の確認段 (必須)

詳細設計に**テンプレートと共有するファイル**の変更が含まれるかを確認する。
共有ファイルかどうかは `docs/template-fingerprints.json` のキーに**そのパスが在るか**で決まる。

- 在る場合: `docs/template-divergence.md` への登録の追加 (または削除) と、
  `tests/Support/TemplateDivergence/LedgerPins.php` の件数の更新を**施策として明記する**
- 採用時債務一覧 (`tests/Support/TemplateDivergence/adoption-debt.tsv`) に在るパスなら、
  **「変更したまま債務に残す」は選べない**。次の 3 つから選んで設計に書く —
  (1) 内容を採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る /
  (3) 意図的逸脱として登録を書き債務から削る
- 在らない場合も、テンプレートに無い領域への上積みなら「登録するか迷ったら登録する」に従う
```

### 変更後コード (app-implement。要点)
```markdown
- コミット前に**乖離台帳の確認段**を通す: 共有ファイル
  (`docs/template-fingerprints.json` のキー) を変えたなら、`docs/template-divergence.md` の
  登録と `LedgerPins` の件数を**同じコミットに含める**。
  stage は**変更したファイルを個別に指定する** (`git add docs/template-divergence.md
  tests/Support/TemplateDivergence/LedgerPins.php` のように。
  ディレクトリ単位の `git add docs/` は無関係な変更まで巻き込むので書かない)。
  突合 gate が赤いときに**指紋台帳や債務一覧を書き換えて黙らせない** (登録を書くか内容を戻す)。
  債務一覧に在るファイルを変えた場合は、上の 3 択のどれを採ったかをコミットメッセージに書く
```

### 波及変更 (**債務モデルの帰結として登録が必須**)

両ファイルは母集合の中にあり、C2 の時点では債務一覧に入る。
債務は**採用時のアプリ側ハッシュで凍結**されているので、C3 で編集すると必ず
`mutatedDebtPaths` になる (§S5)。「変更したまま債務に残す」は選べないので、
C3 では 3 択のうち**「登録を書いて債務から削る」**を選ぶ:

- 新規登録 **D35** (`恒久`) を足す。対象パスは
  `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md`
  (実測: 既存の登録済み 88 パスにこの 2 本は無い = 重複しない)
- 業務要件起因の説明は「本アプリの設計・実装スキルは、乖離台帳の確認段と bug-hunt 等の
  アプリ固有の手順を持つためひな形と異なる」。揃え続ける不変条件は
  「共有ファイルを変えたら登録を同じコミットで足す手順を出口に持つこと」
- 同じコミットで pin を **`DIVERGENCE_ENTRY_COUNT` 32 → 33** /
  **`ADOPTION_DEBT_COUNT` 176 → 174** へ、債務一覧から 2 行削除、
  D34 本文の件数を 176 → 174 へ、S7 の現物期待値を更新する
- **母集合の件数 (281) は変わらない** (債務 → 登録済みへ移るだけ)
- `AGENTS.md` §設計・TODO・devnotes の運用: **追記しない** (スキルの内側の手順であり、
  規約本文に写すと 2 か所管理になる)

### テスト計画
- [ ] スキル文書の変更のみ。`composer test` / `pnpm test` に影響しない
- [ ] C3 として**独立したコミット**にし、検証結果も C2 と分けて記録する

### リスク
- 確認段は人手の層であり機械強制ではない。t3 の完全な到達 (正典側は role で分岐する二役の
  書き方を持つ) は主張しない

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規 PHP が 18 本 + 生成物 2 本で、既存コードへの変更は 4 ファイル (`composer.json` / 形式検査 / `DivergenceLedgerRules` docblock / `AGENTS.md`) に限られる。母集合と債務の件数 pin は**実装の最終状態でしか確定しない**ため、他作業と並行すると pin が合わなくなって赤になり続ける。1 本の worktree で完結させる |
| 競合リスク | **高い順に 2 つ**。(1) 他の TODO が共有ファイルを触ると母集合の内容が動き、債務件数の pin がずれる → 本 TODO を先に main へ入れる。(2) 他の TODO が登録簿へ D 番号を足すと件数 pin が競合する → merge 時に「番号は再利用しない・詰めない」原則で振り直す (先例: T228 の枝が D32 へ振り直された) |
| 実装順序 | C1 (識別子) → C2 (S2〜S10。**S7 の負例を先に書いて赤を確認**。pin は 32 / 176 / 281) → C3 (S11 + D35。pin は 33 / 174 / 281) |
| 受入条件 | AGENTS.md の検証コマンド **10 本すべて**が緑 — `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`。**C2 と C3 で別々に記録する**。かつ **`LedgerPins` の 3 値・D34 本文の件数・S7 の正例の期待値が一致**していること。「型安全」と「PHPStan で解析済み」を区別して報告すること (`tests/` `scripts/` は解析対象外) |
