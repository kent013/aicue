【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし paths は app / config / database / routes のみ = tests/ scripts/ は解析対象外)
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策に Pest テスト）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠 (UI 変更を含む場合)
11. Atomic Design 準拠 (UI 変更を含む場合)

本件は UI / API / DB を一切変更しない**静的検査 (gate) の新設**である。したがって観点 6 / 10 / 11 は
非該当であり、観点 1 / 3 / 4 / 7 / 8 と「静的検査の共通規約 5 条への準拠」を重点的に見てほしい。

【この設計を読むうえでの前提知識】
- 本リポジトリ (aicue) は laravel-claude-template から生成された。複数リポジトリが機能台帳 lctl で
  設計を共有し、lctl の feature が定める正典 (t0 → t1 → t2 → t3) に各リポジトリが追従する。
- 本件の正典 t1 = 共有ファイルの指紋台帳 + 突合検査 (3a/3b) + 掃除漏れ検出 + fail-closed 規約。
  提供元 laravel-claude-template が実装済みで、その現物を下に添付する。
- 概念設計は別セッションで Codex レビュー APPROVED 済み (Round 3)。
- AGENTS.md の「静的検査 (gate) と走査器の共通規約」5 条:
  (a) クラス参照は完全修飾名で突き合わせる (名前解決する走査にだけ適用)
  (b) 解決できない形は落とす (fail-closed)。未解決を解決済みへ混ぜない / 保証範囲外の構文は
      docblock へ明記 / 「違反が 0 件」と「母集団が 0 件」を区別する
  (c) 検出力は負例で裏取りする (両方向)
  (d) 集めた走査結果を判定に使わない形を作らない
  (e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する (語彙一致する走査にだけ適用)
  走査器・gate を新設するときは同じ PR で 4 点を揃える: 負例と正例 (テストファースト) /
  解決できない形を落とす分岐 / 走査が空振りしていないことの検査 / docblock に走査対象と
  保証しないものを書く。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
`281 = 79 (一致: 76 + byte 一致移植 3) + 26 (相違かつ登録済み: 21 + 新規同名 3 + 債務から移す 2) + 176 (債務)`

**この 3 値と D34 本文の件数・正例テストの期待値が一致していることを C2 の受入条件とする。**

## 施策一覧

| # | 施策名 | 変更ファイル | コミット | 優先度 |
|---|--------|------------|---|---|
| S1 | 識別子の反転 (role の 2 重化の前提) | `composer.json` | C1 | 高 |
| S2 | 指紋台帳の DTO とパス検証 | `tests/Support/TemplateDivergence/{LedgerRole,ComparisonState,FingerprintLedger,RepoRelativePath}.php` | C2 | 高 |
| S3 | 母集合の列挙と生成ロジック | `tests/Support/TemplateDivergence/{TrackedRepositoryFiles,AppFingerprintBuilder,AtomicLedgerWriter}.php` | C2 | 高 |
| S4 | 生成器と指紋台帳・債務一覧の生成 | `scripts/update-template-fingerprints.php` / `docs/template-fingerprints.json` / `tests/Support/TemplateDivergence/adoption-debt.txt` | C2 | 高 |
| S5 | 突合と債務の判定 (純関数) | `tests/Support/TemplateDivergence/{FingerprintReconciler,AdoptionDebtInventory,LedgerPins}.php` | C2 | 高 |
| S6 | 突合 gate | `tests/Architecture/TemplateDivergenceFingerprintTest.php` | C2 | 高 |
| S7 | 負例・正例 | `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` | C2 | 高 |
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
- [ ] S6 の F2 (role ⇔ 識別子の 2 重化) が緑になることで検証される
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

`fromJson()` が落とす形 (正典と同じ。**1 つでも欠けたら例外**):
JSON として不正 / 最上位が object でない / キー集合が正準形と不一致 /
`schema_version !== 1` / `role` が文字列でない・値域外 / `generated_at_commit` が 40 桁小文字 hex でない /
`entries` が object でない / キーが repo-relative な単一ファイルパスでない (**差し替え点**) /
値が 64 桁 hex でない / キーが昇順でない。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`self` / `bool` / `string`)
- [x] null 安全 (`json_decode` は `JSON_THROW_ON_ERROR`、`?? null` の後に型検査)
- [x] DTO を返している (配列返却なし。`entries` は `array<string, string>` を PHPDoc で明示)
- [x] Generics の型パラメータ: 該当なし (`list<string>` / `array<string, string>` を明示)
- 注: 前述のとおり `tests/` は PHPStan の解析対象外である。型は**人が書いて負例で担保する**

### テスト計画
- [ ] 負例 (S7): 上記 10 形すべてで `fromJson()` が `RuntimeException` を投げる
- [ ] 負例 (S7): `RepoRelativePath::isValid()` が 8 形すべてで false、正当なパスで true
- [ ] 正例 (S7): 現物の `docs/template-fingerprints.json` が `fromJson()` を通る
- [ ] byte 一致の裏取り: 移植 2 本が正典と byte 一致であることを**移植時に `shasum` で確認**し、
      以後は F8 (3a) が drift を検出する (専用テストは作らない = 同じ事実を 2 か所で検査しない)

### リスク
- 正典が `SharedPathRules::isValidRepoRelativePath()` の判定を変えたとき、本リポジトリの
  `RepoRelativePath` は追従しない。D33 の「再判定の条件」にこれを書く

---

## S3: 母集合の列挙と生成ロジック

### 変更箇所
- 新規: `tests/Support/TemplateDivergence/TrackedRepositoryFiles.php`
- 新規: `tests/Support/TemplateDivergence/AppFingerprintBuilder.php`
- 新規: `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` (**正典から byte 一致で移植**)

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
     * @param  list<string>  $trackedPaths
     * @param  callable(string): string  $hasher  自リポジトリのファイルの sha256 (失敗は例外)
     * @return array{ledger: FingerprintLedger, debtPaths: list<string>, matched: int, mismatched: int}
     */
    public static function build(
        FingerprintLedger $templateLedger,
        array $trackedPaths,
        callable $hasher,
        array $registeredTargetPaths,
    ): array { /* … */ }
}
```

`build()` の規則:
1. `$templateLedger->role !== LedgerRole::Template` なら例外 (入力が正典の台帳でない)
2. 母集合 = キーの積集合。**0 件なら例外** (正典 (5b))
3. 各母集合パスについて自リポジトリのハッシュを計算する。
   symlink / regular file でない / 読めない → **例外** (正典 (5c)。黙って除外しない)
4. 債務 = {母集合のうちハッシュが正典と相違} − {登録済み対象パス}。昇順・重複なし
5. 出力の `role` は `App`、`generated_at_commit` は**正典台帳の値をそのまま写す**

### テスト計画
- [ ] 負例: 正典台帳の `role` が app → 例外
- [ ] 負例: 積集合が 0 件 → 例外
- [ ] 負例: ハッシュ計算が失敗するパスがある → 例外 (空へ潰さない)
- [ ] 正例: 一致 / 相違 / 登録済み相違 を混ぜた合成入力で、母集合・債務・件数が期待どおり
- [ ] `TrackedRepositoryFiles`: git 失敗を模した root (git でないディレクトリ) で例外

### リスク
- `git ls-files -z` は index に残る削除済みパスも返す。母集合には入るが F7 が
  `MissingCurrent` として扱い 3a 側へ倒れる (過剰検出寄り = 正典 (7) に一致)

---

## S4: 生成器と指紋台帳・債務一覧の生成

### 変更箇所
- 新規: `scripts/update-template-fingerprints.php` (正典と同名・内容は本リポジトリ向け)
- 新規 (生成物): `docs/template-fingerprints.json`
- 新規 (生成物): `tests/Support/TemplateDivergence/adoption-debt.txt`

### 生成物を 2 本に分ける理由

債務一覧を指紋台帳 JSON へ入れると**正典の schema から外れる** (家系の可搬性が落ちる)。
PHP のクラス定数として生成すると生成器が PHP コードを書くことになる。
よって**1 行 1 パスの平文データファイル**にする (PR の差分が 1 行 = 1 パスで読める)。

### 使い方と終了コード

```
php scripts/update-template-fingerprints.php --template-ledger=<path> [--adopt-new-template-ledger]
```

| 終了コード | 意味 |
|---|---|
| 0 | 生成成功 (指紋台帳と債務一覧を原子的に置換し、3 つの pin 値を標準出力へ出す) |
| 3 | ガードによる拒否 (既存台帳が `role: template` / 入力の sha256 が pin と不一致で明示フラグ無し) |
| 1 | 実行不能 (入力が読めない・解釈できない・git 失敗・母集合 0 件・ハッシュ計算失敗) |

- **入力の出自の検査**: 入力ファイルの sha256 が
  `LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256` と一致しなければ exit 3。
  `--adopt-new-template-ledger` を明示したときだけ通し、**新しい pin 値 2 つを標準出力へ出す**
- **入力の構造の検査**: `FingerprintLedger::fromJson()` を通し、`role: template` を要求する
- 出力は `AtomicLedgerWriter::replace()` 経由 (切り詰めた JSON を正本に残さない)。
  債務一覧も同じ原子的置換を使う。**JSON と債務一覧の間で失敗した場合は
  件数 pin が合わなくなり gate が落ちる** (中途半端な状態が緑にならない = fail-closed)
- 標準出力・標準エラーは `fwrite()` で書く (`echo` 禁止)。`declare(strict_types=1)` を持つ

### 入力の取得手順 (実装者向け・CI では走らせない)

正典の指紋台帳は lctl の `get_source(project: laravel-claude-template,
path: docs/template-fingerprints.json, ref: 0597a0c…)` で取得し、
`content` を**そのままファイルへ保存**して `--template-ledger` に渡す
(devnotes 配下の一時ファイルに置き、コミットはしない)。
取得した台帳ファイルの sha256 を `LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256` に、
その `generated_at_commit` を `LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT` に入れる。

### テスト計画
- [ ] 生成器は**手で 1 回実行して**生成物をコミットする (CI では走らせない)
- [ ] 負例 (S7 で純関数として): role が app の入力 / sha256 不一致 / 母集合 0 件
- [ ] 生成器そのものの実行は手動確認 (exit 0 / 3 / 1 の 3 経路を実行し、
      3 と 1 のときに生成物が 1 バイトも変わらないことを確認する)

### リスク
- 生成器を `role: app` の本リポジトリで**再実行すると債務一覧が現状で上書きされる** =
  未登録の食い違いを債務へ追加して 3a を黙らせられる。これを塞ぐため
  **既存の債務一覧に無いパスを債務へ追加する場合は `--adopt-new-template-ledger` を要求**し、
  件数 pin の更新が PR に必ず現れる形にする。手編集は機械では止まらない (原理的限界)

---

## S5: 突合と債務の判定 (純関数)

### 変更箇所
- 新規: `tests/Support/TemplateDivergence/LedgerPins.php`
- 新規: `tests/Support/TemplateDivergence/AdoptionDebtInventory.php`
- 新規: `tests/Support/TemplateDivergence/FingerprintReconciler.php`

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

    /** 採用時債務の件数 (縮む方向にしか正当化されない)。 */
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
 * 採用時債務一覧 — 「採用時点で内容が食い違っていたが、登録簿に説明が無いパス」。
 *
 * ★**免除の許可一覧ではない**。採用時点の凍結された観測であり、新しく食い違いを作る経路には
 *   一切効かない (母集合のうち本一覧に無いパスが食い違えば 3a が必ず落ちる)。
 * ★一覧は**縮む方向にしか正当化されない**。縮む契機は 2 つ =
 *   (1) 内容をテンプレートへ戻す / (2) 意図的逸脱として登録簿へ書く。
 *   期限による棚卸しは登録簿の D34 (`監視中` + 見直し期限) が持つ。
 * ★**保証しないもの**: 一覧へ行を足す変更は機械では止まらない (件数 pin の更新が
 *   PR に現れることに依存する)。各パスが意図的逸脱なのか追従遅れなのかは分類していない。
 */
final class AdoptionDebtInventory
{
    public const string INVENTORY_PATH = 'tests/Support/TemplateDivergence/adoption-debt.txt';

    /**
     * データファイルを読んで検証済みのパス一覧を返す。
     *
     * 落とす形: ファイルが読めない / 空 / 末尾改行が無い / 空行 / 前後の空白 /
     * 重複 / 昇順でない / `RepoRelativePath::isValid()` を通らない。
     *
     * @return list<string>
     * @throws RuntimeException
     */
    public static function paths(string $root): array { /* … */ }
}
```

```php
// tests/Support/TemplateDivergence/FingerprintReconciler.php
/**
 * 3a / 3b と債務規則の判定 (純関数)。
 *
 * 突合の本体は**集合の等式 1 本**である:
 *   {母集合のうち不一致のパス} == ({全登録の対象パス} ∩ {母集合}) ∪ {債務一覧}
 * 等式なので ⊃ (不一致なのに未登録 = 3a) も ⊂ (一致へ戻ったのに登録が残る = 3b) も落ちる。
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
     * @param  list<string>  $doubleDeclaredPaths  債務規則 (ii): 債務と登録の二重宣言
     * @param  list<string>  $debtPathsOutsidePopulation  債務一覧に母集合外のパスがある
     */
    public function __construct(
        public array $unregisteredMismatches,
        public array $staleRegistrations,
        public array $resolvedDebtPaths,
        public array $doubleDeclaredPaths,
        public array $debtPathsOutsidePopulation,
    ) {}

    public function isClean(): bool { /* 5 つすべてが空 */ }
}
```

`FingerprintReconciler::reconcile()` の引数:
- `array<string, ComparisonState> $states` (母集合の全キー)
- `array<string, string> $registered` (母集合内の対象パス => `D11 (506 行目)` 形式のラベル)
- `list<string> $debtPaths`

判定順序 (すべて評価してから返す。早期 return しない = どの違反も 1 回の実行で全部見える):
1. 債務が母集合外 → `debtPathsOutsidePopulation`
2. 債務 ∩ 登録 → `doubleDeclaredPaths`
3. 債務のうち `Matched` → `resolvedDebtPaths`
4. 母集合 − 債務 の範囲で不一致 (`ContentMismatch` / `MissingCurrent`) かつ未登録 → `unregisteredMismatches`
5. 母集合 − 債務 の範囲で登録済みかつ `Matched` → `staleRegistrations`

### PHPStan適合チェック
- [x] 戻り値の型が明示 (`ReconciliationResult`)
- [x] null 安全 (引数はすべて型付き。`?? null` の後に型検査)
- [x] DTO を返している (`list<string>` を 5 本持つ readonly DTO)
- [x] Generics: `array<string, ComparisonState>` / `array<string, string>` を PHPDoc で明示

### テスト計画 (S7 で実施)
- [ ] 3a が発火する / しない (登録済みなら発火しない)
- [ ] 3b が発火する / しない
- [ ] 債務規則 (i)(ii)(iii) がそれぞれ発火する / 正常入力で発火しない
- [ ] `MissingCurrent` が 3a 側へ倒れる
- [ ] `AdoptionDebtInventory::paths()` が 8 形の壊れた入力で例外

### リスク
- 5 種別のうち 1 つでも gate が assert しないと (d) 違反になる。F8〜F11 で**全種別を assert**する

---

## S6: 突合 gate

### 変更箇所
- 新規: `tests/Architecture/TemplateDivergenceFingerprintTest.php`

### 検査一覧

| # | 検査 | 正典の対応 |
|---|---|---|
| F0 | 指紋台帳・登録簿・債務一覧が実在し読める。**読み取り失敗が例外になること**を負のコントロールで確認 | G0 / (5c) |
| F1 | 指紋台帳の schema が解釈でき、`role` が `App` である | G1 |
| F2 | `role` と `composer.json` の `name` が整合する (name が提供元名なら fail) | G2 |
| F3 | 母集合の件数が `LedgerPins::FINGERPRINT_POPULATION_COUNT` と完全一致 | G3 / (5d) |
| F4 | 母集合が非空 **かつ** git 追跡ファイルが非空 (走査の生存確認) | G5 / (5b) |
| F5 | 指紋台帳の `generated_at_commit` が `LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT` と一致 | (出自 pin。正典には無い上積み) |
| F6 | **本機構自身のファイルが母集合に含まれる** (必須メンバ pin。検査を黙らせる変更自体が検査対象) | G4 |
| F7 | 母集合の各パスの状態を判定する。symlink / regular file でない / 読めない → `MissingCurrent` 扱い (黙って除外しない) | G7 相当 |
| F8 | 3a / 3b が 0 件 (`unregisteredMismatches` / `staleRegistrations`) | G10 |
| F9 | 債務規則 (i): `resolvedDebtPaths` が 0 件 | (正典には無い) |
| F10 | 債務規則 (ii): `doubleDeclaredPaths` が 0 件、かつ `debtPathsOutsidePopulation` が 0 件 | (同) |
| F11 | 債務規則 (iii): 債務件数が `LedgerPins::ADOPTION_DEBT_COUNT` と完全一致 | (5d) |
| F12 | 債務が非空の間、`AdoptionDebtInventory::INVENTORY_PATH` が登録簿の対象パスとして登録されている | (D34 の存在の機械化) |
| F13 | 登録簿の解析が成功していること (`unparsable === false` かつ `parseViolations` が空)。解析不能なら**この gate も落ちる** | (5c) |

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
4. **採用時債務 176 件の中身は説明されていない**。意図的逸脱と追従遅れの区別は付いていない
   (分類の契機は D34 の見直し期限)
5. **手編集による無効化は止まらない**。指紋台帳 / 債務一覧 / pin / 本 gate 自身の書き換えは
   検査を書き換えるのと等価であり、PR レビューの義務である
6. **`generated_at_commit` の実在は検証しない** (別リポジトリの commit なので原理的に不可能)。
   書式と pin との一致だけを見る
7. **git 追跡外のファイルは母集合に入らない**
8. **本 gate は突合であって遮断ではない**。逸脱を作れなくするものではなく、
   登録なしに作れなくするものである

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
| `FingerprintLedger::fromJson()` | 10 形 (JSON 不正 / 最上位が object でない / キー過不足 / schema 違い / role 非文字列 / role 値域外 / commit 書式 / entries 非 object / キーが不正なパス / 値が sha256 でない / キー昇順でない) | 現物の指紋台帳が通る |
| `RepoRelativePath::isValid()` | 8 形 (空 / 絶対 / 空要素 / `.` / `..` / NUL / 末尾スラッシュ / 制御文字) | `tests/Pest.php` / `.claude/skills/app-design/SKILL.md` 等が true |
| `FingerprintReconciler` | 5 種別すべてを個別に発火させる | 一致・登録済み相違・債務相違だけの入力で 5 種別すべて空 |
| `AdoptionDebtInventory::paths()` | 読めない / 空 / 末尾改行なし / 空行 / 前後空白 / 重複 / 昇順でない / 不正パス | 現物の債務一覧が通る |
| `AppFingerprintBuilder::build()` | 入力が `role: app` / 積集合 0 件 / ハッシュ計算失敗 | 合成入力で母集合・債務・件数が期待どおり |
| `TrackedRepositoryFiles::all()` | git でないディレクトリを渡すと例外 | 本リポジトリの root で非空 |

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
- [ ] 件数を 1 つずらすと赤になることを手で 1 回確認する (pin の生存確認)

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
  (`docs/template-fingerprints.json`) と実ファイルを突き合わせ、
  食い違いに登録が無い場合と、内容が一致へ戻ったのに登録が残っている場合を落とす。
  **形式検査 (`TemplateDivergenceLedgerFormatTest`) 自身は突合を持たない**
- 突合が見ないものは台帳リポジトリの巡回が引き続き担う (家系の裁定 AG-159) —
  (a) 母集合の外 (テンプレートがアプリ固有と分類したファイル / テンプレート側にしか無いファイル)、
  (b) 共有ファイルの**内部**の逸脱 (粒度はファイル単位である)、
  (c) テンプレート更新への**追従遅れ** (指紋は取り込んだ時点の写しなので食い違いが生じない)、
  (d) 採用時債務一覧に載っているパスが意図的逸脱なのか追従遅れなのかの**分類**
```

D33 (`恒久`) の登録メタ表:

| 行 | 内容 |
|---|---|
| 対象パス | `docs/template-fingerprints.json` / `tests/Architecture/TemplateDivergenceFingerprintTest.php` / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` / `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` / `tests/Support/TemplateDivergence/FingerprintLedger.php` / `scripts/update-template-fingerprints.php` |
| 業務要件起因の説明 | テンプレートの現物を CI に持てないため、母集合を正典の分類規則ではなく正典が公開する指紋台帳のキーで決める。突合は本アプリの登録簿が許す複数の対象パスに合わせて実装する |
| 揃え続ける不変条件と保証機構 | 3a / 3b の集合等式と fail-closed の 4 規約を保つ (`TemplateDivergenceFingerprintTest` / `TemplateDivergenceFingerprintRulesTest`) |
| 再判定の条件 | 正典が母集合の決め方・schema・パス検証の判定を変えたとき / テンプレートの現物を CI で引ける手段ができたとき |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260821-0000-template-divergence-fingerprint-t1/` |
| 状態 | 恒久 |
| 見直し期限 | — |

D34 (`監視中`) の登録メタ表:

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/TemplateDivergence/adoption-debt.txt` / `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` |
| 業務要件起因の説明 | 採用時点で既に 176 件の共有ファイルが食い違っており、全件に登録を書くと台帳が定型文で埋まって一次情報性が壊れる。未説明の食い違いを凍結した観測として列挙し、期限付きで縮める |
| 揃え続ける不変条件と保証機構 | 一覧は縮む方向にしか動かない。新規の食い違いは 3a が落とす (`TemplateDivergenceFingerprintTest` の F9〜F11) |
| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す) / テンプレート更新の一括取り込みを行うとき |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260821-0000-template-divergence-fingerprint-t1/` |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-28 |

- 見直し期限 2027-02-28 は基準日 (2026-08-21) から 191 日で、上限 400 日の内側
- 各エントリは本文に「保証しないもの」の節を持つ (D25 以降の水準に合わせる)
- **対象パスの重複が無いことを確認済み** (実測: 既存 88 パスに上記 8 パスは 1 件も無い)

### テスト計画
- [ ] 形式検査 (TD1〜TD12) が緑: 9 行ちょうど・値域・期限・件数の 3 点一致
- [ ] F12 (債務一覧のファイルが登録済み) が緑
- [ ] 見直し期限を過去日にすると赤になることを手で 1 回確認 (既存機構の生存確認)

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
採用時点で説明が無い食い違いは `tests/Support/TemplateDivergence/adoption-debt.txt` に
凍結して列挙してあり (D34。期限付きで縮める)、**この一覧に無いパスの新しい食い違いは
必ず赤になる**。**保証しないものの正本は突合 gate の docblock** であり、本書に写さない。
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
- 採用時債務一覧 (`tests/Support/TemplateDivergence/adoption-debt.txt`) に在るパスなら、
  登録を書いて債務から削るか、債務のまま残すかを判断して設計に書く
- 在らない場合も、テンプレートに無い領域への上積みなら「登録するか迷ったら登録する」に従う
```

### 変更後コード (app-implement。要点)
```markdown
- コミット前に**乖離台帳の確認段**を通す: 共有ファイル
  (`docs/template-fingerprints.json` のキー) を変えたなら、`docs/template-divergence.md` の
  登録と `LedgerPins` の件数を**同じコミットに含める** (`git add docs/ tests/Support/TemplateDivergence/`)。
  突合 gate が赤いときに**指紋台帳や債務一覧を書き換えて黙らせない** (登録を書くか内容を戻す)
```

### 波及変更
- 両ファイルは母集合の中にあり**既に債務一覧に入る**ため、この編集は新しい登録を要求しない
  (債務パスは食い違い続けることが規則であり、編集しても食い違いのままである)
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
| 判断根拠 | 新規ファイルが 12 本 + 生成物 2 本で、既存コードへの変更は 4 ファイル (`composer.json` / 形式検査 / `DivergenceLedgerRules` docblock / `AGENTS.md`) に限られる。母集合と債務の件数 pin は**実装の最終状態でしか確定しない**ため、他作業と並行すると pin が合わなくなって赤になり続ける。1 本の worktree で完結させる |
| 競合リスク | **高い順に 2 つ**。(1) 他の TODO が共有ファイルを触ると母集合の内容が動き、債務件数の pin がずれる → 本 TODO を先に main へ入れる。(2) 他の TODO が登録簿へ D 番号を足すと件数 pin が競合する → merge 時に「番号は再利用しない・詰めない」原則で振り直す (先例: T228 の枝が D32 へ振り直された) |
| 実装順序 | C1 (識別子) → C2 (S2〜S10。**S7 の負例を先に書いて赤を確認**) → C3 (S11) |
| 受入条件 | `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が全て緑。かつ **`LedgerPins` の 3 値・D34 本文の件数・S7 の正例の期待値が一致**していること |


---

## 概念設計 (参考。既に APPROVED)

# 概念設計: テンプレート乖離台帳を家系の正典 t1 へ追従する (指紋台帳・突合検査・掃除漏れ検出・fail-closed)

## 背景・課題

### 家系での位置付け

機能台帳 lctl の feature `template-divergence-ledger` で、本リポジトリ (aicue) のセルは
`update_pending` / 現行 `t0 (+ 登録簿の形式検査)` / `target_version: t1` である
(feature_revision `61-c3c6e2960fa0`)。正典は `t3` まで進んでいるが、aicue の段階目標は t1 に
据え置かれている (feature の `agenda_resolved` = オーナー裁定 2026-08-20 が
「既存セルの目標 (aigenba / aicue の target t1) は段階目標として据え置き」と明記)。

正典 t1 が要求するのは 4 つである (feature の boundary (2)〜(7) を要約):

| 要素 | 正典の要求 |
|---|---|
| (2) 指紋台帳 | 共有ファイルの一覧と、最後に同期した時点の**テンプレート側の中身の指紋**を持つ機械可読ファイル 1 本 |
| (3) 突合検査 | (3a) 食い違っているのに登録が無ければ落とす (deny-by-default) / (3b) 一致へ戻ったのに登録が残っていれば落とす (掃除漏れ) |
| (4) 母集合起点 | 指紋台帳へ載せる対象は手書き一覧ではなく**機械抽出した母集合**を起点にする |
| (5) fail-closed | (5a) 0 件でも明示 / (5b) 母集合 0 件は不合格 / (5c) 実行不能は不合格 / (5d) 件数を pin して黙って減らさない |

加えて (6) 根拠 id の実在検証、(7) 迷ったら過剰検出寄りに倒す原則がある。

### 本リポジトリの現況 (実測 2026-08-20)

- 登録簿 `docs/template-divergence.md` は家系の統一形式へ移行済み。登録は **30 件**で、
  件数は「宣言行 / 見出しの実数 / 検査の固定値」の **3 点一致**が守られている
  (実測: 宣言行 30 / `## D<n>` 見出し 30 / `TEMPLATE_DIVERGENCE_ENTRY_COUNT = 30`)
- 形式検査 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` は
  判定を純関数 (`DivergenceLedgerRules`) に閉じ、負例を
  `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` が固定している。
  根拠 id (`T<n>` / `devnotes/<dir>/`) の実在検証も持つ = **正典 (6) は既に達成済み**
- **足りないのは (2)(3)(4) の 3 つ**である。しかも形式検査は自らの docblock と
  登録簿の §保証しないもの に「実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)」と
  書いて**突合の責務を明け渡している**

### 何が起きるか (この穴の実害)

同じ家系の motivation で実例が観測されている。`tests/js/architecture/contrast-invariant.test.ts` が
テンプレートと **1 バイトも違わない**状態から、意図した改良の副作用として静かに分岐した。
台帳が「実体との突合はまだ無いので、台帳に載っていない逸脱は誰も検出できない」と自ら予告していた
穴にそのまま落ちた形である。本リポジトリにも同じ形の資産が **76 ファイル**ある (下記実測)。

## 実測データ (この設計の前提)

正典の指紋台帳 `laravel-claude-template:docs/template-fingerprints.json`
(`generated_at_commit = a078806b0574518ddc64966f60f7d536b1338b2f`、読み取りは
`laravel-claude-template@0597a0c`) の **947 キー**を本リポジトリの現物と突き合わせた結果:

| 区分 | 件数 | 意味 |
|---|---|---|
| 本リポジトリに実在し、内容が**テンプレートと byte 一致** | **76** | 静かな分岐が起き得る資産。今は誰も見張っていない |
| 本リポジトリに実在し、内容が**相違** | **199** | 意図的逸脱と追従遅れが混ざっている |
| 本リポジトリに**存在しない** | **672** | テンプレート側で後から増えたもの (`infra/` 23 / `deploy/` 7 / `tests/` 498 等) |

相違 199 件の内訳をさらに登録簿の対象パス (30 エントリ・88 パス) と突き合わせた:

| 区分 | 件数 |
|---|---|
| 相違かつ**登録済み** (D10 / D11 / D14 / D18 / D20 / D22 / D25 / D27 / D30 / D31) | **21** |
| 相違かつ**未登録** (説明が無い) | **178** |
| 登録済みだが母集合の外 (アプリ固有ファイル等) | 67 |
| **登録済みかつ内容が一致 (= 3b が即座に発火する組み合わせ)** | **0** |

重要な副産物が 2 つある。

1. `AGENTS.md` / `tests/Pest.php` / `composer.json` / `docs/architecture.md` /
   `docs/TODO.md` / `docs/template-divergence.md` は**正典の指紋台帳のキーに入っていない**
   (提供元が `shared: false` = アプリ固有と分類している)。つまり最も頻繁に編集する
   アプリ固有ファイルは母集合に入らず、突合が定型文の登録を量産する心配は無い
2. 3b が最初から 0 件なので、**導入時点で登録簿の書き換えが要らない**

## 改善アイデア

t1 の 4 要素を、本リポジトリの実情 (テンプレートから生成された後 572 コミット分の距離がある) に
合わせて次の形で導入する。

### 1. 指紋台帳 `docs/template-fingerprints.json` (正典と同じ schema・同じパス)

- `role: app` / `schema_version: 1` / `generated_at_commit` = **正典の指紋台帳の値をそのまま写す**
  (= その指紋がテンプレートのどの時点の内容かを表す。role: app では別リポジトリの commit なので
  実在検証は原理的にできず、正典自身も書式検査だけと明記している)
- `entries` = **{正典の指紋台帳のキー} ∩ {本リポジトリの git 追跡ファイル}** に対する
  **テンプレート側の sha256**。手書き一覧ではなく機械抽出なので正典 (4) を満たす
- 母集合の出典を「正典の分類規則 (`SharedPathRules`) の移植」ではなく
  「正典が公開している指紋台帳のキー」にする。理由: 分類規則は提供元の
  `git ls-files` を分類する道具であり、子アプリが持っても**テンプレートの現物が手元に無いので
  ハッシュを作れない**。キーを借りれば母集合は機械的に決まり、22KB の分類規則を
  死んだ資産として抱えなくてよい (思考原則 2)

#### 入力の出自 (provenance) を pin する

指紋台帳の値は「テンプレート側の内容」を名乗るので、**入力が本当に正典の台帳であること**を
機械で縛らないと偽の基準線を混ぜられる (生成物が `role: app` であることは何の保証にもならない)。
2 段で縛る:

1. **生成器の入力検査 (fail-closed)**: 入力ファイルが `role: template` / `schema_version: 1` /
   `generated_at_commit` が 40 桁 hex / entries が非空でキーが repo-relative・値が 64 桁 hex、を
   すべて満たさなければ生成しない (exit 1)。1 つでも欠けたら「入力を解釈できない」として落とす
2. **出自の 2 値 pin**: 検査側に
   `TEMPLATE_LEDGER_SOURCE_COMMIT` (= 取り込んだ正典台帳の `generated_at_commit`) と
   `TEMPLATE_LEDGER_SOURCE_SHA256` (= 取り込んだ**正典台帳ファイル自身**の sha256) を置き、
   - 検査は自リポジトリの指紋台帳の `generated_at_commit` が pin と一致することを見る
   - 生成器は入力ファイルの sha256 が pin と一致しなければ拒否する (exit 3)。
     新しい正典台帳へ載せ替えるときだけ `--adopt-new-template-ledger` を明示し、
     生成器が新しい pin 値を標準出力へ出す (= pin の更新が PR に必ず現れる)

これで正規経路からの偽の基準線は塞がる。**指紋台帳や pin の手編集は機械では止まらない**
(検査自身を書き換えるのと等価な、リポジトリ内自己完結検査の原理的限界。PR レビューの義務)。

### 2. 突合検査 `tests/Architecture/TemplateDivergenceFingerprintTest.php`

正典と同じ「集合の完全一致」1 本で 3a / 3b を表す:

```
{母集合のうち不一致のパス} ＝ {全登録エントリの対象パス} ∩ {母集合} ∪ {採用時債務一覧}
```

- 左辺 ⊃ 右辺 = 不一致なのに登録が無い (3a)
- 左辺 ⊂ 右辺 = 一致へ戻ったのに登録が残っている (3b = 掃除漏れ)
- **エントリの状態 (`恒久` / `監視中`) は読まない**。状態を突合のフィルタにすると、
  内容をテンプレートへ戻した後に状態だけ変えて 3b を回避できてしまう

### 3. 採用時債務一覧 — 「今そこにある食い違い」を隠さずに扱う

本リポジトリには**採用時点で既に未説明の食い違いがある**。ここを直視しないと
設計は 2 つの失敗のどちらかに落ちる。

| 案 | 帰結 | 判定 |
|---|---|---|
| 未説明の全件に登録エントリを書く | 登録が 30 → 208 件。定型文で埋まり一次情報性が壊れる。1 つの TODO に収まらない | 却下 |
| 母集合を「一致している 76 件」だけにする | 残りは**列挙もされず**、再同期しても掃除漏れも検出できない。検出できない範囲が見えなくなる | 却下 |
| **母集合は 275 件 + 本機構の新規ファイルのまま、未説明のパスを exact-fit の債務一覧として列挙・pin する** | 債務が repo 内で可視・可算になり、機械で縮小方向へ強制できる | **採用** |

債務一覧に課す規則は 3 つ:

- **(i) 債務パスは食い違い続けること**。内容がテンプレートと一致へ戻ったら
  「債務一覧から削れ」と落とす (掃除漏れ検出の債務版。債務は縮む方向にしか動かない)
- **(ii) 債務一覧 ∩ 登録済み対象パス = ∅**。同じパスを「未説明の債務」と「説明済みの登録」の
  両方で宣言させない。登録を書いたら債務から削る (= 債務が 1 件縮む)
- **(iii) 件数を完全一致で pin する** (「以下」ではない)。増減どちらでも赤くなる。
  pin する値は上表の**初期債務 (176)** であり、観測値 178 ではない

**これは免除の許可一覧ではない**。免除の許可一覧は「これから作る逸脱を登録せずに通す口」だが、
債務一覧は**採用時点の凍結された観測**であり、新しく食い違いを作る経路には一切効かない
(母集合のうち債務一覧に無いパスが食い違ったら 3a が必ず落ちる)。

#### 件数を 2 つに分けて確定する (観測値と初期債務は別物)

| 値 | 件数 | 意味 |
|---|---|---|
| **観測時の未説明差分** | **178** | 2026-08-20 の実測 (相違 199 − 既に登録済み 21) |
| **C2 でコミットする初期債務一覧** | **176** | 178 から D33 へ移す 2 件 (`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` / `tests/Support/TemplateDivergence/DivergenceLedgerParser.php`) を除いた値 |

`LedgerPins` が固定するのは**後者 (176)** である。D33 の対象パスに他の債務パスを含めるなら
その分だけ初期債務はさらに減るので、**最終値は生成器の出力で確定し、`LedgerPins` /
D34 の本文 / 正例テストの 3 か所で同じ値に揃える** (実装時の必須手順)。

#### 母集合に入る新規ファイルの規則

新設ファイルのうち母集合に入るのは**正典の指紋台帳のキーであるものだけ**である。
- 入る: `tests/Architecture/TemplateDivergenceFingerprintTest.php` /
  `scripts/update-template-fingerprints.php` /
  `tests/Support/TemplateDivergence/` に置く正典と同名のクラス (いずれも正典側に実在する
  = キーである)。内容は本リポジトリ向けに変わるので**すべて食い違い扱いになり、D33 で説明する**
- 入らない: `docs/template-fingerprints.json` (**正典の 947 キーに含まれていない**。実測で確認。
  提供元が自分の指紋台帳を母集合から外している) / `LedgerPins` / 債務一覧 /
  `tests/Unit/Architecture/` の負例 (いずれも正典側に同名ファイルが無い)

#### 債務を放置させない契機 — 期限を既存の機構に載せる

件数が縮むことを規則にしても、**誰がいつ分類するか**が無ければ債務は固定化する
(「テンプレート側の安全な修正を取り込まないまま既知差分として温存する」形が最悪の帰結)。
新しい期限機構は作らず、**登録簿が既に持っている `監視中` + 見直し期限**に載せる:

- 債務一覧を説明する登録を `監視中` / 見直し期限 **2027-02-28** で 1 件置く (D34。本文には
  初期債務の件数を書く = 観測値 178 ではなく 176)。
  既存の形式検査が**期限切れを CI の赤として検出する**ので、期限が来れば必ず棚卸しが起きる
- 期限が来たときの出口は登録簿の規約節が既に定める 4 通りをそのまま使う。
  本件に写すと (1) テンプレートの内容へ戻して債務から削る / (2) 意図的逸脱として登録を書き
  債務から削る / (3) 期限を延ばして再判断の根拠を足す / (4) 対象を分けて個別に判断する。
  **検査を緩めることは選択肢に入れない**
- 検査側の結合を 1 本足す: **債務一覧が空でない間、債務一覧のファイルが登録簿の対象パスとして
  登録されていること**を見る。D34 を消して債務だけ残す形が機械で落ちる
- 責任主体は登録メタ表の `決めた人` が持つ (D34 は `開発者`)。債務 1 件ごとに責任者欄を作らない
  (176 行に同じ値が並ぶだけで一次情報にならない。思考原則 2)

### 4. fail-closed (5a〜5d)

| 規約 | 実装 |
|---|---|
| (5a) 0 件でも明示 | 既存の形式検査が持つ (件数の明示行はちょうど 1 本必須) |
| (5b) 母集合 0 件は不合格 | 指紋台帳の entries が空 / `git ls-files` が 0 件を返したら落とす |
| (5c) 実行不能は不合格 | ファイル読み取り失敗・JSON 解釈不能・git 実行失敗はすべて例外 (空へ潰さない) |
| (5d) 件数 pin | 登録件数 / 母集合件数 / 債務件数の 3 つを 1 か所 (`LedgerPins`) で完全一致 pin |

### 5. 登録の契機 (正典 t3 の要素) を先取りする

設計スキル (`app-design`) と実装スキル (`app-implement`) の出口に
「テンプレートと共有するファイルを変えたら乖離台帳を更新したか」の確認段を置く
(オーナー裁定 AG-205。提供元が先に自分のスキルへ入れており、その形が家系の手本)。

t3 の要素を先取りする根拠:

- 本設計で入れる突合検査は**母集合 (275 件 + 本機構の新規ファイル) しか見ない**。アプリ固有ファイルと
  テンプレート側で後から増えた 672 件には沈黙する。確認段はその外側を人手で拾う層である
- 検査は「赤くなってから直す」層であり、確認段は「赤くする前に登録する」層である。
  motivation では設計レビューを 4〜6 巡回しても登録されなかった実例が 4 件あり、
  裁定は「手順の欠落は手順でしか塞がらない」と結論している
- 費用が小さい (SKILL.md 2 本への追記)。両ファイルは既に債務一覧に入るため、
  この編集が新しい登録を要求することはない

### 6. AG-159 の責務宣言を書き換える (論点 2 への答え)

現行の 3 か所 (`docs/template-divergence.md` §この登録簿が保証しないもの /
`DivergenceLedgerRules` の docblock / `AGENTS.md` §テンプレートとの関係) は
「実体との突合は台帳リポジトリの巡回が行う」と書いている。突合を持つ以上この文言は
**削除ではなく範囲の縮小**として直す:

- **形式検査**は依然として突合を持たない (突合は新しい検査の担当) — 検査ごとの責務は分けて書く
- **台帳リポジトリの巡回に残る責務**は 4 つ:
  (a) 母集合の外 (アプリ固有ファイル / テンプレート側にしか無い 672 件)、
  (b) 共有ファイル**内部**の逸脱 (粒度はファイル単位なので中身の後退は見えない)、
  (c) テンプレート更新への**追従遅れ** (指紋は同期時点の写しなので追従遅れでは食い違いが出ない)、
  (d) 債務一覧 176 件が意図的逸脱なのか追従遅れなのかの**分類**

### 7. role と識別子の 2 重化を成立させる

`composer.json` の `name` が今なお `rio-development/laravel-claude-template` である
(生成時に反転し忘れている)。`role: app` を名乗る以上ここを `rio-development/aicue` へ直し、
正典と同じ「role ⇔ composer name の 2 重化」検査を成立させる。
`name` への参照は repo 内に 1 件も無く (`composer.json` の当該行のみ)、`composer.json` は
母集合の外なので登録も不要。同じ家系の metamovics が
「composer.json の name を改名し `role` を app へ反転」を先例として実施済みである。

### 8. 生成器 `scripts/update-template-fingerprints.php`

- 入力は**正典の指紋台帳の写し** (`--template-ledger=<path>`)。CI では走らせない
  (ネットワークもテンプレートの現物も要らない = 検査は committed JSON を読むだけ)
- role ガードは**正典と逆向き**: 既存台帳が `role: template` なら拒否する (exit 3)
- 終了コード規約は正典と同じ (0 = 成功 / 3 = ガードによる拒否 / 1 = 実行不能)
- 債務一覧の再計算結果を標準出力へ出す (人が pin を直せるように)

## 期待効果

- **使命への貢献 (間接だが構造的)**: 撮影 PWA の 3 枚セット・LLM 防御の窓口・テナント境界といった
  不変条件は、テンプレートと共有する検査資産の上に立っている。導入後は**母集合 275 件 (+ 本機構の
  新規ファイルのうち正典台帳のキーであるもの) を変えたときに、未登録の乖離が CI で検出される**ようになり、
  「検査は緑なのに穴が開いていた」形の事故を 1 種類減らせる。
  76 件は「導入時点で byte 一致だった内訳」であって監視範囲そのものではない。
  また**指紋台帳・突合検査・債務一覧そのものを書き換える変更は PR レビューの信頼境界**である
  (検査自身の改変で検査を弱められるのは自己完結検査の原理的限界)
- 逸脱の登録が「人が思い出すかどうか」から「CI が落ちるかどうか」へ移る (家系の裁定 AG-110 の中核)
- 掃除漏れ (テンプレート準拠へ戻したのに登録が残る) が初めて機械で落ちる
- 176 件の未説明の食い違いが**可視・可算**になり、以後は縮む方向にしか動けなくなる
  (機械で検出できるのは母集合の中だけであり、母集合の外は D33 / D34 と PR レビューが管理する)
- lctl のセルが `t0 (+ 形式検査)` → `t1` へ進み、家系で 2 番目の t1 実装リポジトリになる

## 実装方針（概要）

| # | 変更 | 種別 |
|---|---|---|
| 1 | `docs/template-fingerprints.json` | 新規 (生成物) |
| 2 | `tests/Support/TemplateDivergence/` に指紋台帳 DTO・突合の純関数・債務一覧・件数 pin | 新規 4〜5 本 |
| 3 | `tests/Architecture/TemplateDivergenceFingerprintTest.php` (薄い検査層) | 新規 |
| 4 | `tests/Unit/Architecture/` に負例・正例 (両方向) | 新規 |
| 5 | `scripts/update-template-fingerprints.php` (生成器) | 新規 |
| 6 | 既存形式検査の件数 pin を `LedgerPins` (不変の scalar 定数だけを持ち、解析・ファイル I/O・git 実行を一切持たない) へ移す | 変更 |
| 7 | AG-159 の責務宣言の書き換え (登録簿 / docblock / `AGENTS.md`) | 変更 |
| 8 | `composer.json` の `name` を `rio-development/aicue` へ | 変更 |
| 9 | `.claude/skills/app-design/SKILL.md` / `app-implement/SKILL.md` に確認段 | 変更 |
| 10 | 本機構自身の逸脱を `docs/template-divergence.md` へ登録 (D33 / D34) + 件数 30 → 32 | 変更 |

- **D33 (`恒久`)**: 母集合の出典を正典の分類規則ではなく**正典の指紋台帳のキー**にし、
  分類規則を移植しないこと。対象パスは本機構のファイル群。
  `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` と
  `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` は現在債務一覧に入る候補だが、
  本機構の一部として D33 で説明できるので**債務から登録へ移す** (債務が 2 件縮む)
- **D34 (`監視中` / 見直し期限 2027-02-28)**: 採用時点の未説明の食い違いを債務一覧として持つこと。
  期限切れが棚卸しの契機になる (上記 §3)

### 実装の分割と t1 の受入条件

1 つの standalone TODO に収めるが、**コミットは 3 つに分け、原因の切り分けを保つ**:

| コミット | 内容 | t1 の受入条件か |
|---|---|---|
| C1 | 識別子の修正 (`composer.json` の `name`) | いいえ (前提条件。role の 2 重化を成立させるため C2 より前に置く) |
| C2 | **t1 の機械層** (指紋台帳 / 突合検査 / 債務一覧 / 生成器 / 負例 / 件数 pin / D33・D34 の登録 / AG-159 の責務宣言の書き換え) | **はい** |
| C3 | t3 の要素 (スキル 2 本への確認段) | いいえ (先取り。落ちても t1 の到達は変わらない) |

C2 だけで lctl のセルは t1 に到達する。C3 が失敗しても t1 の判定に影響しないことを
実装時の報告でも分けて書く。**検証結果 (テストの実行結果) も C2 と C3 で分けて記録する** —
C3 の不具合で C2 の t1 到達評価が曖昧になるのを防ぐため。

### 型と検査の境界 (事実の確認)

- **PHPStan の解析範囲は `app` / `config` / `database` / `routes` の 4 つだけ**であり、
  `tests/` と `scripts/` は**解析対象に入っていない** (`phpstan.neon` 実測)。
  したがって本設計の新規ファイルは PHPStan level 10 で守られない。
  **型を緩めて黙らせるのではなく (禁止事項 2)、解析されない場所だからこそ
  境界の検証をコードで書く**
- 外部入力の境界は 4 つ: 指紋台帳 JSON / `git ls-files` の出力 / ファイルのハッシュ /
  登録簿の Markdown。いずれも**失敗を空集合へ潰さず例外にする**
- **パスの検証は取りこぼしを作らない**。指紋台帳のキーと登録簿の対象パスについて、
  絶対パス / 空要素 / `.` / `..` / NUL を含む / ディレクトリ / 追跡対象外 /
  シンボリックリンクの 8 形の扱いを実装時に確定し、**未対応の形は黙って除外せず落とす**
  (共通規約 (b)。「保証しない構文」として静かに外すのは本設計の趣旨に反する)
- 不変 DTO で受ける: 指紋台帳 (`schema_version` / `role` / `generated_at_commit` / `entries`)、
  比較状態 (一致 / 内容相違 / 追跡から消滅)、突合結果 (未登録の食い違い / 掃除漏れ /
  一致へ戻った債務 / 二重宣言) を**種別ごとに分けた型**で返す
  (集めた結果を判定に使わない形を作らないため = 共通規約 (d))。
  検証項目は JSON のキー集合の完全一致・`schema_version` の値・`role` の値域・
  40 桁 hex の commit・64 桁 hex の sha256・キーが repo-relative な単一ファイルパス・
  キーの昇順・重複の不在で、`json_decode` は `JSON_THROW_ON_ERROR` を使う
- 生成器も PHP なので `declare(strict_types=1)` を持ち、`echo` と開始タグ付きの出力記法を
  使わない (`ForbiddenStatementTokenInvariantTest` / `StrictTypesDeclarationGateTest` の
  母集団に入る)。標準出力・標準エラーは `fwrite()` で書く
- **テストファースト**: 負例を先に書いて赤を確認してから本体を書く (思考原則 5)。
  既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
- **「型安全」と「PHPStan で検証済み」を区別して報告する**。新規 DTO と生成器について
  保証できるのは、ネイティブ型・境界の実行時検証・正負のテストによる担保までである。
  `composer phpstan` が緑でも**これらのファイルが level 10 で解析されたことにはならない**。
  この保証範囲を実装時の報告にも残す

## 制約・前提

- **静的検査の共通規約 5 条 (AGENTS.md)** に準拠する。(a) は非該当 (クラス参照を解決しない)、
  (e) は非該当 (語彙一致を判定しない)。(b) fail-closed / (c) 負例の両方向 /
  (d) 集めて使わない形を作らない / **母集合の非空** / **docblock への保証範囲明記**は必須
- 判定は純関数に閉じ、検査層は薄く保つ (既存の形式検査と同じ構造 = 正典 i12)
- **同じ列挙を 2 本持たない**: 登録簿の解析は既存の `DivergenceLedgerParser` を再利用し、
  2 本目の解析器を作らない。ただし複数対象パスへの対応が要る
  (正典の突合は 1 エントリ 1 パスを前提にしているが、家系の統一形式は複数パスを許す)
- `composer test` レーン (Architecture / Unit) に載る。`pnpm test` 側の変更は無い
- PHPStan level 10 / Pint / `declare(strict_types=1)` + 日本語コメント
- テストレーンはホスト全体でグローバルロックされているため、待ち時間は正常

## スコープ外

- **正典 t2 の要素は採らない**。t2 は「各アプリに指紋台帳と CI 突合検査の新設を求める要求を
  取り下げる」という**緩和**であり、採ると本設計そのものが消える。lctl のセルが
  aicue の target を t1 に据え置いている以上、緩和ではなく到達を選ぶ。
  なお t2 の緩和は「求めない」であって「禁止」ではないので、実装は正典と矛盾しない
- **正典 t3 のうち採るのは確認段だけ**。提供元が入れた「指紋台帳の role で分岐する二役の書き方」は
  本リポジトリが app 役のみなので不要 (思考原則 2)
- **共有ファイル内部の逸脱の検出** (粒度はファイル単位のまま)
- **テンプレート更新への追従遅れの検出**と、追従そのもの (672 件の未受領ファイルの取り込み)
- **債務 176 件の分類・登録** (以後の実装で 1 件ずつ縮める。今回まとめて書くと定型文になる)
- 正典の `SharedPathRules` (分類規則 22KB) の移植
- 指紋台帳 JSON の手編集による無効化の防止 (検査自身の編集と等価な原理的限界。PR レビューの義務)

---

## 関連する現行コード

### 1. 既存の形式検査 tests/Architecture/TemplateDivergenceLedgerFormatTest.php (全文)

```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
use Tests\Support\TemplateDivergence\LedgerContext;
use Tests\Support\TemplateDivergence\TodoLedgerReference;
use Webmozart\Assert\Assert;

/*
 * 逸脱の登録簿 (`docs/template-divergence.md`) が家系の統一形式を満たすことを検査する。
 *
 * 判定の実体は `DivergenceLedgerRules` (純関数) にあり、本テストは
 * **実ファイルを読んで文脈を組み立て、違反が空であることを見るだけ**の薄い層である。
 * 負例 (検出器が実際に検出できること) は
 * `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` が固定する。
 *
 * **この検査が保証しないもの** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの)。
 *    実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
 *  - 内容をテンプレート準拠へ戻したのに残っている登録 (対象パスは実在し続けるため)
 *  - 登録の中身が正しいこと (空でないこと・値域に収まっていることだけを見る)
 *  - 削除した番号の再利用 (使用済み番号の履歴を持たないため)
 *
 * 実行不能 (台帳が読めない / 囲みが閉じない / 登録エントリ領域が無い) は
 * skip でも緑でもなく**不合格**にする。
 */

/**
 * 登録件数の固定値。
 *
 * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
 * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
 */
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 30;

/** 逸脱の登録簿の本文 (読めないことは不合格)。 */
function templateDivergenceMarkdown(): string
{
    $markdown = file_get_contents(base_path('docs/template-divergence.md'));
    Assert::string($markdown, 'docs/template-divergence.md を読めない');

    return $markdown;
}

/** 根拠の照合先 (TODO 台帳の Open と Closed の両方)。 */
function templateDivergenceTodoSources(): string
{
    $open = file_get_contents(base_path('docs/TODO.md'));
    $closed = file_get_contents(base_path('docs/TODO-closed.md'));
    Assert::string($open, 'docs/TODO.md を読めない');
    Assert::string($closed, 'docs/TODO-closed.md を読めない');

    return $open."\n".$closed;
}

test('TD0: 逸脱の登録簿を読めること (実行不能は不合格)', function (): void {
    expect(trim(templateDivergenceMarkdown()))->not->toBe('');
    expect(trim(templateDivergenceTodoSources()))->not->toBe('');
});

test('TD1〜TD12: 逸脱の登録簿が統一形式を満たすこと', function (): void {
    $todoSources = templateDivergenceTodoSources();

    $violations = DivergenceLedgerRules::violations(
        DivergenceLedgerParser::parse(templateDivergenceMarkdown()),
        new LedgerContext(
            baseDate: CarbonImmutable::today(),
            pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
            pathExists: fn (string $path): bool => is_file(base_path($path)),
            directoryExists: fn (string $path): bool => is_dir(base_path($path)),
            // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
            rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn($reference, $todoSources),
        ),
    );

    expect($violations)->toBe([], "逸脱の登録簿の形式違反:\n".implode("\n", $violations));
});
```

### 2. 既存の解析器 tests/Support/TemplateDivergence/DivergenceLedgerParser.php (抜粋: 定数と public API)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Webmozart\Assert\Assert;

/**
 * 逸脱の登録簿 (`docs/template-divergence.md`) の Markdown を解析する (純関数)。
 *
 * 解析器は**取り出すだけ**で、値の妥当性は `DivergenceLedgerRules` が見る。
 * 読み解けなかったこと (囲みが閉じない / 登録エントリ領域が無い) は
 * 違反として返し、空集合へ落として緑にする経路は持たない (fail-closed)。
 *
 * Markdown の囲み文法を全部は実装しない。台帳で使ってよい囲みは**行頭のバッククォート
 * 3 個ちょうど**だけで、バッククォート 4 個以上と `~~~` は**明示的に違反**にする
 * (黙って読み飛ばすと、その囲みで登録を隠せる回避口になる)。
 */
final class DivergenceLedgerParser
{
    /**
     * 登録メタ表のラベル (規定の順序)。過不足・順序違いは「台帳を解釈できない」= 不合格。
     *
     * @var list<string>
     */
    public const META_LABELS = [
        '対象パス',
        '業務要件起因の説明',
        '揃え続ける不変条件と保証機構',
        '再判定の条件',
        '決めた日',
        '決めた人',
        '根拠',
        '状態',
        '見直し期限',
    ];

    /** 登録の見出しの正準形 (行全体一致)。 */
    private const ENTRY_HEADING = '/^## D([1-9]\d*) (\S.*)$/u';

    /** 件数の明示行。 */
    private const DECLARED_COUNT = '/^登録エントリ: (\d+) 件$/u';

    public static function parse(string $markdown): ParsedLedger
    {
        $scan = self::outsideFenceLines($markdown);
        $violations = $scan['violations'];

        if ($scan['unclosed']) {
            $violations[] = 'P1: 囲みコード区画が閉じていない (解析不能)。囲みは行頭のバッククォート 3 個ちょうどで開閉する';

            return new ParsedLedger([], null, $violations, true);
        }

        $lines = $scan['lines'];

        $declared = null;
        $declaredHits = 0;
        foreach ($lines as $line) {
            if (preg_match(self::DECLARED_COUNT, $line[1], $matches) === 1) {
                $declaredHits++;
                $declared = (int) $matches[1];
            }
        }
        if ($declaredHits !== 1) {
            $violations[] = sprintf(
                'TD12: 件数の明示行「登録エントリ: N 件」は囲みの外にちょうど 1 本必要 (実測 %d 本)',
                $declaredHits,
            );
            $declared = null;
        }

        $regionStart = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line[1], '## D')) {
                $regionStart = $index;
                break;
            }
        }

        if ($regionStart === null) {
            $violations[] = 'P2: 登録エントリ領域 (最初の `## D<n>` 見出し) が見つからない (解析不能)';

            return new ParsedLedger([], $declared, $violations, true);
        }

        /** @var list<int> $headingIndexes */
        $headingIndexes = [];
        $total = count($lines);
        for ($index = $regionStart; $index < $total; $index++) {
            if (str_starts_with($lines[$index][1], '## ')) {
                $headingIndexes[] = $index;
            }
        }

        /** @var list<ParsedEntry> $entries */
        $entries = [];
        /** @var array<int, int> $seenIds id => 初出の行番号 */
        $seenIds = [];

        foreach ($headingIndexes as $position => $headingIndex) {
            [$lineNumber, $headingText] = $lines[$headingIndex];

            if (preg_match(self::ENTRY_HEADING, $headingText, $matches) !== 1) {
                $violations[] = sprintf(
                    'TD1: %d 行目の見出しが正準形 `## D<n> <要約>` ではない: %s',
                    $lineNumber,
                    $headingText,
                );

                continue;
            }

            $id = (int) $matches[1];
            $summary = $matches[2];

            foreach (self::forbiddenSummaryReasons($summary) as $reason) {
                $violations[] = sprintf('TD1: D%d (%d 行目) の要約は%s', $id, $lineNumber, $reason);
            }

            if (isset($seenIds[$id])) {
                $violations[] = sprintf(
                    'TD1: D%d が重複している (%d 行目と %d 行目)。番号はリポジトリ内で一意',
                    $id,
                    $seenIds[$id],
                    $lineNumber,
                );
            } else {
                $seenIds[$id] = $lineNumber;
            }

            $bodyEnd = $headingIndexes[$position + 1] ?? $total;
            $body = array_slice($lines, $headingIndex + 1, $bodyEnd - $headingIndex - 1);

            $metadata = self::parseMetadata($body, sprintf('D%d (%d 行目)', $id, $lineNumber), $violations);

            $entries[] = new ParsedEntry($id, $summary, $lineNumber, $metadata);
        }

        return new ParsedLedger($entries, $declared, $violations, false);
    }

    /**
     * 囲みコード区画の外にある行だけを行番号付きで返す。
```

### 3. 既存の DTO (ParsedLedger / ParsedEntry / EntryMetadata / LedgerContext)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録簿を解析した結果。
 *
 * `$unparsable` が true のときは登録簿を読み解けていないので、
 * `DivergenceLedgerRules` は解析時の違反だけを返して**そこで打ち切る** (fail-closed)。
 * 解析できなかったことを空集合へ落として緑にする経路は作らない。
 */
final readonly class ParsedLedger
{
    /**
     * @param  list<ParsedEntry>  $entries  解析できた登録 (見出しが正準形のものだけ)
     * @param  int|null  $declaredCount  「登録エントリ: N 件」の明示行の値 (行がちょうど 1 本でなければ null)
     * @param  list<string>  $parseViolations  解析時点で分かった違反
     */
    public function __construct(
        public array $entries,
        public ?int $declaredCount,
        public array $parseViolations,
        public bool $unparsable,
    ) {}
}
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 解析した逸脱の登録 1 件。
 *
 * `$metadata` が null なのは登録メタ表を解析できなかった場合で、そのときは
 * `ParsedLedger::$parseViolations` に理由が入っている (握り潰さない)。
 */
final readonly class ParsedEntry
{
    public function __construct(
        public int $id,
        public string $summary,
        public int $line,
        public ?EntryMetadata $metadata,
    ) {}

    /** 違反メッセージの見出し (どの登録の話かを 1 目で分かるようにする)。 */
    public function label(): string
    {
        return sprintf('D%d (%d 行目)', $this->id, $this->line);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録 1 件が持つ登録メタ表 9 行の値。
 *
 * 値は**生文字列のまま**持ち、妥当性 (値域・日付・実在) は `DivergenceLedgerRules` が見る。
 * ここで正規化すると「解析器が直したので合格した」という抜け道ができるため、
 * 解析器は取り出すだけにする。
 */
final readonly class EntryMetadata
{
    /**
     * @param  list<string>  $targetPaths  対象パス欄から取り出したパス (バッククォート囲みの中身)
     * @param  string  $rawTargetPathCell  対象パス欄の生の値 (書式違反の報告に使う)
     */
    public function __construct(
        public array $targetPaths,
        public string $rawTargetPathCell,
        public string $domainReason,
        public string $invariantAndGuard,
        public string $reevaluationCondition,
        public string $decidedOn,
        public string $decidedBy,
        public string $rationale,
        public string $state,
        public string $reviewDeadline,
    ) {}
}
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Carbon\CarbonImmutable;
use Closure;

/**
 * 形式検査の文脈 (基準日と実在判定の注入点)。
 *
 * 基準日を引数で受け取るのは、見直し期限と決めた日の判定を純関数に保ち、
 * 単体テストが実行日で揺れないようにするためである。
 */
final readonly class LedgerContext
{
    /**
     * @param  CarbonImmutable  $baseDate  期限判定の基準日 (検査層は今日、単体テストは固定日を渡す)
     * @param  int  $pinnedEntryCount  検査側に固定した登録件数 (明示件数との同期検査であって免除一覧ではない)
     * @param  Closure(string): bool  $pathExists  リポジトリ相対の**ファイル**の実在判定 (is_file)
     * @param  Closure(string): bool  $directoryExists  リポジトリ相対の**ディレクトリ**の実在判定 (is_dir)
     * @param  Closure(string): bool  $rationaleExists  根拠 (T 番号) が TODO 台帳の表に実在するか
     */
    public function __construct(
        public CarbonImmutable $baseDate,
        public int $pinnedEntryCount,
        public Closure $pathExists,
        public Closure $directoryExists,
        public Closure $rationaleExists,
    ) {}
}
```

### 4. 既存の規則 tests/Support/TemplateDivergence/DivergenceLedgerRules.php (抜粋: docblock と violations)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * 逸脱の登録簿の形式違反を列挙する (純関数)。
 *
 * **保証しない範囲** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
 *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159)
 *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
 *  - 登録の中身が正しいことは見ない (空でないこと・値域に収まっていることだけを見る)
 *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出し・地の文は見ない
 *  - 削除した番号の再利用は検出しない (使用済み番号の履歴を持たないため)
 *
 * 固定件数 (`LedgerContext::$pinnedEntryCount`) は**明示件数との同期検査**であって、
 * 例外を許す一覧ではない。個別の D 番号を名指しする許可一覧は持たない。
 */
final class DivergenceLedgerRules
{
    /** 状態の値域。どちらも「今ある逸脱」を表し、解消を意味する語は持たない。 */
    public const STATES = ['恒久', '監視中'];

    /** 決めた人の値域。 */
    public const DECIDERS = ['オーナー', '開発者'];

    /** 見直し期限の上限 (基準日からの日数)。青天井の期限で検査を無力化させない。 */
    public const MAX_REVIEW_WINDOW_DAYS = 400;

    /** `恒久` の見直し期限に置く不在の記号。 */
    public const PERMANENT_DEADLINE = '—';

    /**
     * プレースホルダの語彙 (過剰検出寄りに倒す)。
     *
     * 適用先は根拠と自由記述 3 欄だけである。見直し期限の `—` は `恒久` の正値なので、
     * 期限欄にはプレースホルダ検査を掛けない。
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = ['', '...', '…', 'TBD', '未定', '-', '—', '?', '？'];

    /**
     * @return list<string> 違反一覧 (空 = 合格)
     */
    public static function violations(ParsedLedger $ledger, LedgerContext $context): array
    {
        $violations = $ledger->parseViolations;

        // 解析不能なら以降の規則は評価できない。評価しないことを違反として返す (fail-closed)。
        if ($ledger->unparsable) {
            return $violations;
        }

        /** @var array<string, list<string>> $pathOwners */
        $pathOwners = [];

        foreach ($ledger->entries as $entry) {
            $metadata = $entry->metadata;
            if ($metadata === null) {
                continue;
            }

            foreach (self::targetPathViolations($entry, $metadata, $context) as $violation) {
                $violations[] = $violation;
            }
            foreach ($metadata->targetPaths as $path) {
                $pathOwners[$path][] = $entry->label();
            }

            foreach (self::freeTextViolations($entry, $metadata) as $violation) {
                $violations[] = $violation;
            }
            foreach (self::decisionViolations($entry, $metadata, $context) as $violation) {
                $violations[] = $violation;
            }
            foreach (self::stateViolations($entry, $metadata, $context) as $violation) {
                $violations[] = $violation;
            }
        }

        foreach ($pathOwners as $path => $owners) {
            if (count($owners) > 1) {
                $violations[] = sprintf(
                    'TD4: 対象パス `%s` を %s が重複して挙げている。和集合で重複させない (片方を消しても赤にならなくなる)',
                    $path,
                    implode(' / ', $owners),
                );
            }
        }

        $parsedCount = count($ledger->entries);
        if ($ledger->declaredCount !== null && $ledger->declaredCount !== $parsedCount) {
            $violations[] = sprintf(
                'TD12: 明示件数 %d 件と解析した見出しの件数 %d 件が食い違う',
                $ledger->declaredCount,
                $parsedCount,
            );
        }
        if ($parsedCount !== $context->pinnedEntryCount) {
            $violations[] = sprintf(
                'TD12: 解析した見出しの件数 %d 件と検査側の固定件数 %d 件が食い違う (登録を足した / 消したら同じ変更で固定件数も直す)',
                $parsedCount,
                $context->pinnedEntryCount,
            );
        }

        return $violations;
    }

```

### 5. 登録簿 docs/template-divergence.md の規約節 (L1-L98)

```
# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。

登録エントリ: 30 件

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
  (これから作る予定) は登録しない — 予定の管理は `docs/TODO.md` の役目である
- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
  他リポジトリから参照するときは `aicue:D<n>` と書く
- **登録するか迷ったら登録する**。テンプレートの実物は手元に無いので「テンプレートに無い領域への
  上積み」か「ひな形から外れた判断」かを本アプリだけで確定できないことがある。
  誤登録はエントリを削除すれば是正できるが、登録漏れには気付けない。台帳リポジトリの巡回から
  「記録されるべき乖離」として届いた指摘は、この理由で登録する側へ倒す

## 登録メタ表 (9 行ちょうど・この順序)

| 行 | 値域 |
|---|---|
| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。区切りは半角スペースとスラッシュと半角スペース。glob・絶対パス・上位への相対指定は不可。ファイルとして実在すること。**全登録の和集合で重複しないこと** |
| 業務要件起因の説明 | なぜドメイン要件のせいでテンプレートの形から外れたか (1〜2 文) |
| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
| 決めた人 | `オーナー` / `開発者` |
| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
| 状態 | `恒久` / `監視中` |
| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は全角ダッシュ 1 文字 |

- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)
- セルの中に縦棒を書かない (エスケープしても解釈しない)。表の区切りを使いたくなる内容は
  エントリ本文の節へ書く

## 見直し期限が切れたときの直し方 (4 通り)

1. 逸脱を解消して登録を消す
2. `恒久` へ変えて理由を足す
3. 期限を延ばして再判断の根拠を足す
4. 対象を分けて個別に判断する

**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。

## この登録簿が保証しないもの

- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
  再利用しないことは人が守る規約である)

## エントリ形式

```
## D1 <逸脱の要約>

| 行 | 内容 |
|---|---|
| 対象パス | `app/Example.php` |
| 業務要件起因の説明 | ... |
| 揃え続ける不変条件と保証機構 | ... |
| 再判定の条件 | ... |
| 決めた日 | 2026-01-01 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| ... | ... | ... |

### なぜ正当な差分か(logic-driven)
...

### 揃えている不変条件(これは保証し続ける)
> 「...」
どの機構でカバーするか。drift を防ぐテスト。

### 関連
- 実装: ...
```
```

### 6. phpstan.neon (paths の実測)

```
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 10
    paths:
        - app
        - config
        - database
        - routes
    excludePaths:
        - vendor
```

### 7. 正典 (提供元 laravel-claude-template@0597a0c) の現物 — 要約

**`tests/Architecture/TemplateDivergenceFingerprintTest.php`** (18877 bytes) の検査は G0〜G14:

- G0: 指紋台帳と逸脱台帳が実在し読める + 読み取り失敗が例外になる負のコントロール
- G1: 指紋台帳のスキーマが解釈できる / G2: role と composer.json の name が整合する
- G3: 指紋台帳の登録パス数がゲート側定数 (`FINGERPRINT_ENTRY_COUNT = 947`) と完全一致
- G4: 本機構のファイルが指紋台帳に必ず含まれる (必須メンバ pin。`SharedPathRules::REQUIRED_SHARED_PATHS`)
- G5: 指紋台帳の登録パスが 0 件なら落ちる (boundary 5b)
- G6/G7: (role: template のみ) 追跡ファイル全件が分類規則のいずれかに当たる / shared: true が regular file
- G8: (role: template のみ) working tree から再計算した指紋台帳がコミット済みと完全一致 (鮮度)
- G9: 逸脱台帳のパース件数がゲート側定数 (`DIVERGENCE_ENTRY_COUNT`) と完全一致
- G10: (role: app のみ) 指紋の不一致集合と全登録の対象パス集合が完全一致 (3a/3b)
- G11: 全登録の対象パスが実在する (現在の git 追跡ファイル ∪ MissingCurrent の指紋台帳キー)
- G12: 全登録の根拠 id が実在する (commit / TODO)
- G13: 分類規則表と REQUIRED_SHARED_PATHS の自己整合 / G14: generated_at_commit の書式と条件付き実在

正典の docblock が宣言する「保証しないこと」9 項目: 粒度はファイル単位 / shared:false 層の新規逸脱は
検出しない / 子アプリの追従遅れは検出しない / 指紋台帳 JSON の手編集は止めない / role と composer name の
両方の反転漏れは検出できない / role: app では子アプリの追加ファイルを検出しない / git 追跡外は母集合外 /
一度登録されたパスのその後の追加 drift は検出しない / generated_at_commit の実在は条件付きでしか検証しない。

**`FingerprintComparator`** (純関数):
- `compare(baseline, current): array<string, ComparisonState>` — キーが current に無ければ MissingCurrent、
  ハッシュ一致なら Matched、違えば ContentMismatch
- `reconcile(states, entries): list<string>` — 「同一対象パスの二重登録」を先に落とし、
  不一致集合と (登録済み ∩ 母集合) の**集合の完全一致**を判定して 3a / 3b のメッセージを返す。
  **エントリの状態 (DivergenceStatus) は読まない** (状態だけ変えて 3b を回避されるため)
- `verifyTargetPathsExist(trackedPaths, states, entries)` — 実在 = 現 git 追跡 ∪ MissingCurrent のキー

**`FingerprintLedger`** (readonly DTO): `SCHEMA_VERSION = 1`、必須キー
`['schema_version','role','generated_at_commit','entries']` の**集合完全一致**、role は enum、
commit は 40 桁小文字 hex、entries は `array<string,string>` でキーが
`SharedPathRules::isValidRepoRelativePath()` を通り値が 64 桁 hex、**キー昇順**。
すべて `RuntimeException` (5c)。`toJson()` は キー昇順 + `JSON_PRETTY_PRINT|UNESCAPED_SLASHES|UNESCAPED_UNICODE`
+ 末尾改行。`matchesIgnoringGeneratedCommit()` は鮮度比較用。

**`AtomicLedgerWriter::replace()`**: 同一ディレクトリの一時ファイルへ書き、(1) 書き込みバイト長、
(2) 読み直した内容が `FingerprintLedger::fromJson()` を通ること、を確認してから rename。
一時パスの dirname が正本と違えば書き込み前に fail。失敗時は一時ファイルの削除を試み、
削除にも失敗したらその旨も報告する。I/O はすべて注入 (失敗注入でユニットテスト可能)。

**`scripts/update-template-fingerprints.php`** (提供元版): role ガード
(既存台帳が role: app なら exit 3)、git は `proc_open` で終了コードを検査して実行
(`shell_exec` は終了コードを返さないため使わない)、追跡ファイル 0 件なら exit 1、
生成は `FingerprintGenerator::build()`、置換は `AtomicLedgerWriter`。
終了コード規約 0 = 成功 / 3 = role ガード拒否 / 1 = 実行不能。

**正典の指紋台帳** `docs/template-fingerprints.json`: `schema_version: 1` / `role: "template"` /
`generated_at_commit: "a078806b0574518ddc64966f60f7d536b1338b2f"` / entries 947 件。
**自分自身 (`docs/template-fingerprints.json`) は entries に含まれていない** (実測)。

### 8. 実測の突き合わせ結果 (この設計の数値の出どころ)

正典の 947 キー vs 本リポジトリ `a4f4f254`:
- byte 一致 76 / 相違 199 / 本リポジトリに不在 672
- 相違 199 のうち登録済み 21 (D10 D11 D14 D18 D20 D22 D25 D27 D30 D31)、未登録 178
- 登録済み 88 パスのうち母集合内で内容一致のものは **0 件** (3b は導入時に発火しない)
- `AGENTS.md` / `tests/Pest.php` / `composer.json` / `docs/architecture.md` / `docs/TODO.md` /
  `docs/template-divergence.md` は**正典のキーに入っていない** (提供元が shared: false と分類)
