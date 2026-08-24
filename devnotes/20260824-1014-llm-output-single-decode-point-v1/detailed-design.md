# 詳細設計: llm-output-single-decode-point v1 追従

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)。実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受ける
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計に直接効く追加の規約**

- 静的検査 (gate) と走査器の共通規約 5 条: (a) クラス参照は完全修飾名で突き合わせる /
  (b) 解決できない形は落とす (fail-closed。未解決を解決済みへ混ぜない・保証範囲外は docblock に明記・
  「違反 0 件」と「母集団 0 件」を区別する) / (c) 検出力は負例で裏取りする /
  (d) 集めた走査結果を判定に使わない形を作らない / (e) 語彙一致の否定形はトークンの完全一致で判定する。
  新設・変更時に同じ PR で揃える 4 点 (負例と正例 / 未解決を落とす分岐 / 空振り検査 / docblock)。
- 後方互換の並走を残さない (思考原則 3)。
- テストレーンの外部 HTTP 出口は既定拒否。LLM 側は `StrayLlmCallGuard` が並存する。
- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数)。
- `echo` / `goto` / `global` / 開始タグ付き出力記法は書かない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは必ず Factory (本設計は新モデルを追加しないので Factory 追加は無い)
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260824-1014-llm-output-single-decode-point-v1/conceptual-design.md`
（Codex `gpt-5.6-terra` レビュー Round 4 で APPROVED）

正典: lctl 機能台帳 feature `llm-output-single-decode-point` の確定設計 v1 (i1〜i10)。
本設計が埋めるのは **i1 / i2 / i3 / i4 / i5 / i6**（i7〜i10 は HEAD で充足済み）。

## 乖離台帳の確認 (SKILL §3-0 の確認段)

実測 (HEAD b207bafa):

- 本設計の変更対象パスは `docs/template-fingerprints.json` の 281 キーに**1 件も無い**
  (全数照合済み)。よって `docs/template-divergence.md` の登録追加も
  `tests/Support/TemplateDivergence/LedgerPins.php` の件数更新も**不要**。
- 採用時債務一覧 (`tests/Support/TemplateDivergence/adoption-debt.tsv`) にも**1 件も無い**。
- 新設する gate は aicue のドメイン固有 gate であり、同種の先例
  (`AnalysisTokenBudgetInvariantTest` / `AdoptedReadyTakeCriterionInventoryTest` /
  `FfmpegProcessLaunchInventoryTest`) も台帳に登録されていない (grep 実測)。
- ★**`tests/Architecture/PromptUntrustedInputContractTest.php` は触らない**。
  同ファイルは指紋台帳に在り、かつ採用時債務に**採用時 sha 付きで凍結**されている
  (実測: debt の sha `7c63bbd7…` = 現ファイルの sha と一致)。触ると
  「戻す / テンプレートへ同期して債務から削る / 逸脱登録を書いて債務から削る」の
  いずれかが必須になる。同ファイルが持つ `discoverPromptFactoryClasses()` を
  共有クラスへ持ち上げれば 20 行の重複は消えるが、**その利得は債務の解消作業に見合わない**
  (思考原則 2)。よって新設 gate は自分の母集団列挙を
  `tests/Support/Prompts/PromptFactoryPopulation.php` に持ち、走査根の不在は fail-fast、
  母集団の非空を検査する (共通規約 (b) の「母集団 0 件と違反 0 件を区別する」)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 失敗区分を 6 + 直交 1 へ作り替える | `app/Enums/Manual/LlmOutputInvalidReason.php` | 高 |
| 2 | 復号点を構造の走査へ作り替える (旧正規表現経路を削除) | `app/Support/Manual/LlmJson.php` | 高 |
| 3 | 復号点の契約テストを新設する (テストファーストの起点) | `tests/Unit/Manual/LlmJsonTest.php` (新), `tests/Support/Manual/FencedLlmResponse.php` (新) | 高 |
| 4 | 依頼文 4 本の出力指示を「囲みちょうど 1 つ」へ | `resources/prompts/{sop-extract,sop-extract-media,work-decomposition,scenario-generation}.yaml` | 高 |
| 5 | canned 応答を囲みつきへ | `app/Services/AI/Testing/CannedPromptResponses.php` | 高 |
| 6 | 既存テストの受理契約を新契約へ書き換える | `tests/Unit/Manual/AnalysisDtoTest.php`, `tests/Unit/Manual/WorkDecompositionResponseDataTest.php`, `tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php`, `tests/Feature/Projects/AnalysisPipelineTest.php`, `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php`, `tests/Feature/Llm/CannedPromptResponsesTest.php`, `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`, `tests/Feature/Notifications/ManualAnalysisNotificationTest.php` | 高 |
| 7 | 復号点の単一性 gate を新設する (i1) | `tests/Architecture/LlmResponseDecodePointGateTest.php` (新), `tests/Support/Llm/LlmResponseSeamScanner.php` (新), `tests/Support/Llm/LlmResponseSeamFinding.php` (新), `tests/Support/Llm/LlmResponseHandling.php` (新), `tests/Support/Llm/DecodePointPublicSurface.php` (新), `tests/Support/Prompts/PromptFactoryPopulation.php` (新), `tests/Unit/Architecture/LlmResponseSeamScannerTest.php` (新), `tests/Architecture/fixtures/llm-seam/*.php.txt` (新) | 高 |
| 8 | 文書 (ドメイン規約 1 項 + アーキテクチャ 1 節 + 巻き戻し手順) | `AGENTS.md`, `docs/architecture.md` | 中 |

**移行 / migration**: 無い。`invalid_json` の文字列は enum 定義の 1 か所にしか存在せず
(grep 実測)、DB 列にも入っていない (`analysis_jobs` は `error` に利用者向け文言、
`failure_reason` は `AnalysisFailureReason`)。`llm_output_invalid_*` は Log context のみ。

---

## 施策 1: 失敗区分を 6 + 直交 1 へ作り替える

### 変更箇所

- ファイル: `app/Enums/Manual/LlmOutputInvalidReason.php` (全 17 行を差し替え)

### 波及変更

- TypeScript 型定義: **なし**。同 enum は
  `tests/js/architecture/enum-ts-sync-discovery.test.ts` の `PHP_ENUM_EXEMPTIONS`
  (`app/Enums/Manual/LlmOutputInvalidReason.php` / 理由「LLM 出力不正の内部理由。画面には
  再試行可否の結果だけが渡る」) に登録済みなので、case を増やしても TS 側の写しは不要。
  登録の**理由文も現状のまま有効**(画面へ値は渡らない)。
- API Resource/DTO: なし (画面へ出るのは `userMessage()` の定型文だけ)。
- テストファイル: 施策 3 / 6 で扱う。
- `match` による分岐: **無い** (grep 実測。`AnalysisPipeline::observabilityCategoryFor()` は
  `'llm_output_invalid_'.$exception->reason->value` の連結なので語彙は自動で広がる)。

### 現行コード

```php
enum LlmOutputInvalidReason: string
{
    /** JSON としてパースできない (切り詰め・コードフェンス外の説明文混入等) */
    case InvalidJson = 'invalid_json';

    /** JSON だがスキーマ違反 (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
    case SchemaViolation = 'schema_violation';
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * LLM 出力の検証失敗分類 (report ログで機械集計する。文字列 drift を型で防止)。
 *
 * ★**2 つの軸が同居する**。`SchemaViolation` 以外の 6 つは「読めなかった」の内側の細分で、
 *   `SchemaViolation` は「読めたが形が違う」という別の軸である (家系の正典 v1 の i5)。
 * ★区分の目的は**再試行の可否の分岐ではない**。可否は
 *   `AnalysisPipeline::isTransient()` が例外型 1 つで決めており (全区分 retryable)、
 *   区分は集計のためだけに存在する。
 */
enum LlmOutputInvalidReason: string
{
    /** 囲み (コードフェンス) の開きの印が 1 つも無い (素の JSON もここに落ちる) */
    case FenceAbsent = 'fence_absent';

    /** 採った囲みの外にもう 1 つ囲みの印がある (差し込みを受け取らない) */
    case FenceMultiple = 'fence_multiple';

    /** 囲みの中身が JSON として読めない / 値の後に余剰トークンがある */
    case SyntaxBroken = 'syntax_broken';

    /** 最上位が入れ物 (object / list) ではない (scalar / null / 空のブロック) */
    case TopLevelNotContainer = 'top_level_not_container';

    /**
     * 値が完結しないまま終端に達した = **切り詰めの推定**。
     *
     * ★これは**構造からの推定であって断定ではない** (正典 i6)。提供元が返す停止の理由の正本は
     *   `llm_call_logs.finish_reason` (`Prism\Prism\Enums\FinishReason` の値。失敗系は
     *   sentinel `'failed'`) であり、本区分はその列を書き換えない。値の綴りに `inferred` を
     *   含めるのは、記録を読む人が**断定と読み違えない**ようにするためである。
     */
    case ValueIncompleteInferred = 'value_incomplete_inferred';

    /** 値は完結したが閉じの囲みが無い (切り詰めと区別する) */
    case ClosingFenceAbsent = 'closing_fence_absent';

    /** 読めたが形が違う (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
    case SchemaViolation = 'schema_violation';

    /**
     * 例外へ渡す固定文。
     *
     * ★**応答本文を含めない** (正典 i9)。区分ごとの固定文だけを返し、応答の断片・
     *   `json_last_error_msg()` / `JsonException::getMessage()` は入れない
     *   (例外の `getMessage()` を記録や画面へ流す経路が将来生まれても本文が漏れない)。
     * ★`SchemaViolation` は呼び出し側が具体的な違反内容を渡すため、ここでは既定文としてだけ持つ。
     */
    public function detail(): string
    {
        return match ($this) {
            self::FenceAbsent => 'コードフェンスの開始記号が見つかりません',
            self::FenceMultiple => 'コードフェンスがブロックの外にもう 1 つあります',
            self::SyntaxBroken => 'コードフェンス内が JSON として読めません',
            self::TopLevelNotContainer => '最上位が object / array ではありません',
            self::ValueIncompleteInferred => '値が完結しないまま応答が終わっています (切り詰めの推定)',
            self::ClosingFenceAbsent => 'コードフェンスの終了記号が見つかりません',
            self::SchemaViolation => 'スキーマ違反です',
        };
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`detail(): string`)
- [x] `match ($this)` は全 case を列挙 (default 無し = case 追加時に PHPStan が落とす)
- [x] null 安全 (null を扱わない)
- [x] Generics の型パラメータ: 該当なし

### テスト計画

- [ ] 施策 3 の `tests/Unit/Manual/LlmJsonTest.php` が全 6 区分を名指しで検証する
- [ ] `detail()` の網羅は `match` の全 case 列挙で型が保証する (専用テストは置かない)

### リスク

- `invalid_json` を消すので、**過去のログの語彙と非連続になる**。ダッシュボード等の
  外部集計は本リポジトリに無い (grep 実測で参照 0 件) ため実害は無いが、
  `docs/architecture.md` の新節に「**本変更の本番デプロイを境界として**、それ以前の記録では
  同じ事象が `invalid_json` である」ことを書き残す (月では区切らない)。
  ★**具体的な日時・リリース SHA は文書に書かない** — 本変更のコミットに自分自身の SHA は
  書けず、デプロイ日も実装時点では未確定である。境界の実値はデプロイ記録 /
  リリースノートを正本とし、文書はそこを指す (実装 PR に placeholder を残さない)。

---

## 施策 2: 復号点を構造の走査へ作り替える

### 変更箇所

- ファイル: `app/Support/Manual/LlmJson.php` (全 45 行を差し替え。旧 `preg_replace` 経路は削除)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: **呼び出し側 3 か所のシグネチャは無変更**
  (`ExtractedSopData::fromLlmText()` L33 / `WorkDecompositionResponseData::fromLlmText()` L22 /
  `GeneratedScenarioData::fromLlmText()` L34 が `LlmJson::decode($text)` を呼ぶ形をそのまま保つ)。
  `LlmJson::schemaViolation()` も無変更。
- テストファイル: 施策 3 / 6

### 現行コード

```php
public static function decode(string $text): array
{
    $trimmed = trim($text);
    // コードフェンス (```json ... ``` / ``` ... ```) を除去する
    if (str_starts_with($trimmed, '```')) {
        $trimmed = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $trimmed = trim($trimmed);
    }

    $decoded = json_decode($trimmed, true);
    if (! is_array($decoded)) {
        throw new LlmOutputInvalidException(
            LlmOutputInvalidReason::InvalidJson,
            'JSON としてパースできません: '.json_last_error_msg(),
        );
    }

    return $decoded;
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\Enums\Manual\LlmOutputInvalidReason;
use App\Exceptions\Manual\LlmOutputInvalidException;
use JsonException;

/**
 * LLM 応答文字列を構造化データへ直す**唯一の復号点** (家系の正典 v1 の i1〜i6)。
 *
 * ## 受理契約 (厳しい入口が 1 つだけ。緩い入口は持たない)
 *
 *   応答 = PRE OPEN VALUE GAP CLOSE POST
 *     PRE   : 囲みの印を含まない任意の文字列 (前置きの説明文を許す)
 *     OPEN  : 逆引用符 3 個以上の並び + 任意の言語札 [A-Za-z0-9_+.-]*
 *     VALUE : 最上位が入れ物 (object / array) の JSON 値ちょうど 1 つ
 *     GAP   : 空白のみ
 *     CLOSE : 逆引用符 3 個以上の並び (直後に言語札を持たない)
 *     POST  : 囲みの印を含まない任意の文字列 (後書きを許す)
 *
 * - 採るのは**常に最初の囲みの直後の値**である (決定論。同じ応答なら常に同じブロック)。
 * - 囲みの印が**ブロックの外にもう 1 つ**あれば受け取らない (差し込みを採らない)。
 * - 囲みの印は「行」ではなく「連続 3 個以上の逆引用符の並び」である。応答データの中に
 *   現れた印を終端に数えないのは、**構造の走査で決まった値の区間の外側だけを数える**
 *   ことで担保する。
 *
 * ## 走査器の責務 (誇張しない)
 *
 * `scanValueEnd()` が行うのは「**最初の JSON 値の終端候補を特定する**」ことだけである。
 * 値が JSON として妥当かは判定せず、それは `json_decode(..., JSON_THROW_ON_ERROR)` に委譲する
 * (自前パーサへ膨らませて `json_decode()` と判定が食い違う状態を作らない)。
 *
 * ## 保証しないもの
 *
 * - 逆引用符の**個数の対応**は見ない (開き 4 個 / 閉じ 3 個も対応が取れているとみなす)。
 * - **scalar の厳密な識別はしない**。分類は「値の開始文字が `{` / `[` か」だけで決めるので、
 *   札の形をした scalar (```` ```null ```` / ```` ```42 ````) は言語札として消費され、
 *   `TopLevelNotContainer` / `ValueIncompleteInferred` へ落ちる。
 * - 走査はバイト単位である (対象文字はすべて ASCII で、UTF-8 の後続バイトと衝突しない)。
 * - 受理文法の GAP / 前後の「空白」は **JSON の空白 4 種 (SP / HT / LF / CR) だけ**である
 *   (Unicode の空白類 — 全角空白・NBSP 等 — は空白として扱わない = 余剰トークンになる)。
 * - 応答の**切り詰めの断定はしない** (`ValueIncompleteInferred` は推定。正本は
 *   `llm_call_logs.finish_reason`)。
 *
 * 不正は `LlmOutputInvalidException` (有界リトライのトリガー。§10.7-2)。
 */
final class LlmJson
{
    /** 囲みの印の最小形 (逆引用符 3 個)。 */
    private const string FENCE = '```';

    /** 開きの印の直後に許す言語札の字種 (これ以外が来たら札は空と解釈する)。 */
    private const string TAG_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_+.-';

    /**
     * 囲みちょうど 1 つの応答から最上位が入れ物の JSON 値を取り出す。
     *
     * @return array<array-key, mixed>
     *
     * @throws LlmOutputInvalidException 受理契約に合わない (区分は docblock の表のとおり)
     */
    public static function decode(string $text): array
    {
        $length = strlen($text);

        // (1) 最初の囲みの印 = OPEN。無ければ素の JSON も含めて拒否する
        $open = self::findFence($text, 0);
        if ($open === null) {
            throw self::reject(LlmOutputInvalidReason::FenceAbsent);
        }

        // (2) 言語札を貪欲に読み飛ばし、値の開始位置を決める
        $start = self::skipWhitespace($text, self::skipTag($text, $open));
        if ($start >= $length) {
            throw self::reject(LlmOutputInvalidReason::ValueIncompleteInferred);
        }
        if (self::isFenceAt($text, $start)) {
            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer); // 空のブロック
        }
        if ($text[$start] !== '{' && $text[$start] !== '[') {
            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer);
        }

        // (3) 構造の走査で値の終端を決める (括弧の対応 + 文字列と打ち消しの解釈)
        $valueEnd = self::scanValueEnd($text, $start);

        // (4) 値の後は 空白 → 閉じの印 → (印を含まない後書き) だけを許す
        $after = self::skipWhitespace($text, $valueEnd);
        if ($after >= $length) {
            throw self::reject(LlmOutputInvalidReason::ClosingFenceAbsent);
        }
        if (! self::isFenceAt($text, $after)) {
            throw self::reject(LlmOutputInvalidReason::SyntaxBroken); // ブロック内の余剰トークン
        }
        $closeEnd = self::skipBackticks($text, $after);
        if ($closeEnd < $length && str_contains(self::TAG_CHARS, $text[$closeEnd])) {
            // 閉じの印ではなく別ブロックの開きだった (i3: 開始の印を閉じと読み違えない)
            throw self::reject(LlmOutputInvalidReason::FenceMultiple);
        }
        if (self::findFence($text, $closeEnd) !== null) {
            throw self::reject(LlmOutputInvalidReason::FenceMultiple);
        }

        // (5) 妥当性は json_decode に委譲する
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(substr($text, $start, $valueEnd - $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::reject(LlmOutputInvalidReason::SyntaxBroken);
        }
        if (! is_array($decoded)) {
            // (3) が `{` / `[` を確認済みなので到達しない。多重防御として残す
            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer);
        }

        return $decoded;
    }

    /**
     * スキーマ違反の例外を生成する (DTO 検証用の短縮形)。
     * $path は観測用の違反位置 (例: validation.works.2)。省略時は null。
     */
    public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
    {
        return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail, $path);
    }

    /** 区分ごとの固定文だけを載せた失効の例外 (応答本文を載せない = i9)。 */
    private static function reject(LlmOutputInvalidReason $reason): LlmOutputInvalidException
    {
        return new LlmOutputInvalidException($reason, $reason->detail());
    }

    /**
     * 最初の JSON 値の**終端候補**を返す (終端の次の位置)。
     *
     * ★括弧の対応は**期待する閉じ括弧のスタック**で追う (深さの数だけでは `{"a":[}` を
     *   終端候補まで通してしまう)。最初の不整合で確定し、走査は継続しない。
     * ★走査は最初の値が完結した時点で終わる。したがって `{"a":1}}` の 2 つ目の `}` は
     *   走査中の不整合ではなく「値の後の余剰トークン」として (4) が `SyntaxBroken` にする。
     *
     * @throws LlmOutputInvalidException `SyntaxBroken` (括弧の不整合) /
     *                                   `ValueIncompleteInferred` (完結しないまま終端)
     */
    private static function scanValueEnd(string $text, int $start): int
    {
        $length = strlen($text);
        /** @var list<string> $expected 期待する閉じ括弧 */
        $expected = [];
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }
            if ($char === '{') {
                $expected[] = '}';

                continue;
            }
            if ($char === '[') {
                $expected[] = ']';

                continue;
            }
            if ($char !== '}' && $char !== ']') {
                continue;
            }

            if (array_pop($expected) !== $char) {
                throw self::reject(LlmOutputInvalidReason::SyntaxBroken);
            }
            if ($expected === []) {
                return $i + 1;
            }
        }

        throw self::reject(LlmOutputInvalidReason::ValueIncompleteInferred);
    }

    /**
     * $from 以降の最初の囲みの印の開始位置 (無ければ null)。
     *
     * @return int|null 印の開始位置
     */
    private static function findFence(string $text, int $from): ?int
    {
        $position = strpos($text, self::FENCE, $from);

        return $position === false ? null : $position;
    }

    private static function isFenceAt(string $text, int $position): bool
    {
        return substr($text, $position, strlen(self::FENCE)) === self::FENCE;
    }

    /** 印の逆引用符の並びを読み飛ばした位置 (3 個以上を 1 つの印として扱う)。 */
    private static function skipBackticks(string $text, int $position): int
    {
        $length = strlen($text);
        $cursor = $position;
        while ($cursor < $length && $text[$cursor] === '`') {
            $cursor++;
        }

        return $cursor;
    }

    /** 開きの印 + 言語札を読み飛ばした位置。 */
    private static function skipTag(string $text, int $fencePosition): int
    {
        $length = strlen($text);
        $cursor = self::skipBackticks($text, $fencePosition);
        while ($cursor < $length && str_contains(self::TAG_CHARS, $text[$cursor])) {
            $cursor++;
        }

        return $cursor;
    }

    private static function skipWhitespace(string $text, int $position): int
    {
        $length = strlen($text);
        $cursor = $position;
        while ($cursor < $length && ($text[$cursor] === ' ' || $text[$cursor] === "\t"
            || $text[$cursor] === "\n" || $text[$cursor] === "\r")) {
            $cursor++;
        }

        return $cursor;
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`decode(): array<array-key, mixed>` / private は int / bool)
- [x] `json_decode` の戻りは `mixed` を `is_array()` で narrow してから返す
- [x] `strpos()` の `false` を型で分岐 (`?int` へ畳む)
- [x] `array_pop()` の `string|null` を `!== $char` で扱う (null は不整合として落ちる = fail-closed)
- [x] `Webmozart\Assert` は不要 (すべて明示分岐)
- [x] Generics: `list<string>` を docblock で宣言

### テスト計画（テストファースト）

施策 3 で先に赤くする。**実装より先に**次の順で進める。

1. `tests/Unit/Manual/LlmJsonTest.php` を書く → 新 case が無いので**赤** (`Error`)
2. 施策 1 (enum) を入れる → `LlmJson` が旧 case を参照して**赤**
3. 本施策を入れる → 1 が緑

追加の境界テスト (施策 3 のケース一覧に含める):

- 入れ子の深さが 513 以上 → `json_decode` の `JsonException` → `syntax_broken`
  (走査器は通し、妥当性判定は委譲先が落とすことの証明)
- 不正な UTF-8 を含む JSON 文字列 → `syntax_broken`
- 逆引用符 4 個の開き + 3 個の閉じ → **受理する** (個数の対応を見ない宣言どおり)
- 全角空白 / NBSP を GAP に置いた応答 → `syntax_broken` (JSON の空白 4 種以外は空白でない)

### リスク

- **本番のモデルが囲みを付けないと解析が失敗する**。緩和は概念設計 §判断 1
  (互換性確認 A/B・巻き戻し手順)。
- PRE の中の説明文に偶然 3 連の逆引用符が現れると、そこが OPEN になり以降で拒否される
  (**fail-closed 方向**の誤り。誤って受理する側には倒れない)。docblock に明記する。

---

## 施策 3: 復号点の契約テストを新設する

### 変更箇所

- 新規: `tests/Unit/Manual/LlmJsonTest.php`
- 新規: `tests/Support/Manual/FencedLlmResponse.php` (テスト共通の囲み包み)

### 波及変更

- なし (テスト専用)

### 変更後コード（共通ヘルパ）

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Manual;

/**
 * LLM 応答の fake / fixture を**受理契約どおりの囲みつき**に包むヘルパ。
 *
 * ★`LlmJson::decode()` の受理契約は「囲みちょうど 1 つ」なので、素の JSON を渡す fake は
 *   `fence_absent` で落ちる。fixture 側の包み方を 1 か所に集めて、
 *   契約が変わったときに直す場所を 1 つにする。
 */
final class FencedLlmResponse
{
    /** 与えた JSON 文字列を ```json … ``` で包む。 */
    public static function wrap(string $json): string
    {
        return "```json\n".$json."\n```";
    }

    /** 配列を JSON へ直してから包む (fixture の定型)。 */
    public static function wrapArray(array $payload): string
    {
        return self::wrap(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
```

`wrapArray()` の `@param array<array-key, mixed> $payload` を docblock で宣言する
(PHPStan level 10)。

### テスト計画（ケース一覧 = 受理 6 / 拒否 17 / 非漏洩 6）

受理:

| # | 入力 | 期待 |
|---|---|---|
| A1 | ```` ```json\n{"a":1}\n``` ```` | `['a' => 1]` |
| A2 | 札なし ```` ```\n{"a":1}\n``` ```` | `['a' => 1]` |
| A3 | 前置きと後書きつき (印は含まない) | `['a' => 1]` |
| A4 | 最上位が list ```` ```json\n[1,2]\n``` ```` | `[1, 2]` (q3 据え置き = list も受ける) |
| A5 | 値の中に印がある ```` ```json\n{"a":"``` inside"}\n``` ```` | `['a' => '``` inside']` |
| A6 | 逆引用符 4 個の開き + 3 個の閉じ | 受理 (個数の対応を見ない) |

拒否 (区分まで検証する):

| # | 入力 | 区分 |
|---|---|---|
| R1 | 素の JSON `{"a":1}` | `fence_absent` |
| R2 | JSON でない文章 | `fence_absent` |
| R3 | 囲み 2 つ (`…{"a":1}``` more ```json {"b":2}```) | `fence_multiple` |
| R4 | 値の後に別言語の開きの印 (```` ```python ````) | `fence_multiple` |
| R5 | ```` ```json\n{"a":[}\n``` ```` (括弧の不整合) | `syntax_broken` |
| R6 | ```` ```json\n{"a":1}}\n``` ```` (値の後の余剰トークン) | `syntax_broken` |
| R7 | ```` ```json\n{"a":}\n``` ```` (json_decode 失敗) | `syntax_broken` |
| R8 | ```` ```json\n42\n``` ```` | `top_level_not_container` |
| R9 | ```` ```json\n``` ```` (空のブロック) | `top_level_not_container` |
| R10 | ```` ```json\n"文字列"\n``` ```` | `top_level_not_container` |
| R11 | ```` ```json\n{"a":1 ```` (EOF で切断) | `value_incomplete_inferred` |
| R12 | ```` ```json\n{"a":"未閉 ```` (文字列の途中で終端) | `value_incomplete_inferred` |
| R13 | ```` ```json ```` の直後で終端 | `value_incomplete_inferred` |
| R14 | ```` ```json\n{"a":1}\n ```` (閉じの印が無い) | `closing_fence_absent` |
| R15 | 入れ子の深さ 513 以上 | `syntax_broken` (委譲先の `JsonException`) |
| R16 | 不正な UTF-8 を含む文字列値 | `syntax_broken` |
| R17 | GAP に全角空白 / NBSP を置いた応答 | `syntax_broken` (JSON の空白 4 種以外) |

非漏洩 (i9):

- [ ] R1〜R14 のうち 6 区分を代表する 6 ケースについて、応答に sentinel 文字列
  (`'SENTINEL-SOP-BODY-9f2c'`) を混ぜ、`getMessage()` と `userMessage()` の**どちらにも**
  sentinel が現れないことを固定する
- [ ] `is_array()` の到達不能分岐は直接テストしない (docblock の記述に留める)

### リスク

- なし (テスト追加のみ)

---

## 施策 4: 依頼文 4 本の出力指示を「囲みちょうど 1 つ」へ

### 変更箇所

- `resources/prompts/sop-extract.yaml` L30 (`system_prompt` 内)
- `resources/prompts/sop-extract-media.yaml` L32 (`system_prompt` 内)
- `resources/prompts/work-decomposition.yaml` L34 (`system_prompt` 内)
- `resources/prompts/scenario-generation.yaml` L29 (`system_prompt` 内)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 7 の検査 6 が本文言を pin する。
  既存 pin (`AnalysisTokenBudgetInvariantTest` / `AnalysisTimeBudgetInvariantTest` /
  `PromptClientTimeoutInvariantTest` / `PromptYamlContractTest` /
  `DefensiveInstructionsPresenceTest`) はいずれも `name` / `provider` / `model` /
  `max_tokens` / `client_options.timeout` / 防御指示の前置きを見るので**影響しない**
  (指示文の 1 行だけを差し替える)。
- `app/Services/AI/Testing/CannedPromptResponses.php` の `map()` は
  **persona の語句** (「作業手順書 (SOP) を構造化するエキスパート」等) を鍵にしているので
  影響しない (差し替えるのは出力形式の行だけ)。

### 現行コード（4 本とも同型）

```yaml
  出力は JSON のみ (前後に説明文・コードフェンスを付けない)。
```

### 変更後コード

```yaml
  出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください (囲みを 2 つ以上作らない)。
```

### PHPStan適合チェック

- 該当なし (YAML)

### テスト計画

- [ ] 施策 7 の検査 6 が「復号点を通す分類の依頼文 YAML の `system_prompt` に
  所定の文が含まれる」ことを deny-by-default で固定する (依頼文を足して書き忘れたら赤)
- [ ] 施策 6 で fixture / canned を囲みつきにするため、囲み無しの応答が
  `fence_absent` になることは pipeline のテストでも観測される

### リスク

- モデルの準拠が不足すると回帰する (概念設計 §判断 1 の緩和策)。
- **`{{ }}` の Blade 展開に触れない**: 差し替えるのは平文の 1 行だけで、
  `{{ $llm_canary }}` や `DefensiveInstructions::forUserInputJa()` の行は変えない。

---

## 施策 5: canned 応答を囲みつきへ

### 変更箇所

- `app/Services/AI/Testing/CannedPromptResponses.php`
  - `sopExtractCanned()` / `sopExtractMediaCanned()` / `workDecompositionCanned()` /
    `scenarioGenerationCanned()` の 4 メソッド (返り値を囲みで包む)
  - `exampleSummaryCanned()` は**自由文なので包まない**

### 波及変更

- テストファイル: `tests/Feature/Llm/CannedPromptResponsesTest.php` (施策 6)
- bug-hunt / browser レーン: canned を通す経路はすべて DTO の `fromLlmText()` を通るので、
  包むことで新契約に整合する

### 変更後コード（4 メソッド共通の形）

```php
    /** sop-extract: ExtractedSopData::fromLlmText を通過 (header + 1 section + 1 step) */
    private static function sopExtractCanned(): string
    {
        return self::fenced([
            'header' => ['title' => 'bughunt サンプル手順書', 'department' => null, 'revision' => null],
            // …（現行の payload をそのまま）
        ]);
    }

    /**
     * canned 応答を受理契約どおりの囲みつき JSON にする。
     *
     * ★`LlmJson::decode()` の受理契約は「囲みちょうど 1 つ」である。素の JSON を返すと
     *   `fence_absent` で落ちるため、**依頼文が指示する形と同じ形**で返す。
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function fenced(array $payload): string
    {
        return "```json\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n```";
    }
```

★`app/Services/AI/Testing/` は**応答を作る側**であり読む側ではないので、
施策 7 の検査 5 (囲みの印の文字列リテラル不在) の走査根には**含めない**
(含めると canned が違反になる。gate の docblock にこの区別を書く)。

### PHPStan適合チェック

- [x] `json_encode` の `JSON_THROW_ON_ERROR` で `string|false` を `string` に確定
- [x] `@param array<array-key, mixed>` を宣言

### テスト計画

- [ ] `tests/Feature/Llm/CannedPromptResponsesTest.php` の 4 テストが緑に戻ること
  (canned が該当 DTO の `fromLlmText()` を通過する)
- [ ] canned が**囲みつきである**ことを 1 テストで明示的に固定する
  (素の JSON へ戻す改変を赤にする)

### リスク

- bug-hunt / pipeline-smoke の canned 経路が壊れると探索走行が止まる。
  上記テストが同じレーンで守る。

---

## 施策 6: 既存テストの受理契約を新契約へ書き換える

### 変更箇所（対象と操作）

| ファイル | 行 | 操作 |
|---|---|---|
| `tests/Unit/Manual/AnalysisDtoTest.php` | L20-23 | 「コードフェンスを除去して JSON を返す」を**新契約のテストへ書き換える**: 囲みつきは受理 / 素の JSON は `fence_absent` |
| 同 | L25-32 | 「不正 JSON を InvalidJson で拒否」→ 区分名を `fence_absent` へ (JSON でない文章は囲みが無い) |
| 同 | L35, L48, L66, L86, L94, L101, L107 | `fromLlmText(json_encode(...))` を `fromLlmText(FencedLlmResponse::wrapArray(...))` へ |
| `tests/Unit/Manual/WorkDecompositionResponseDataTest.php` | L20-31 (`decompositionResponseText()`) | 戻り値を囲みつきにする (呼び出し側は無変更) |
| 同 | L46-49, L71 | 直接組み立てている JSON / 「JSON ではない」入力を新契約へ |
| `tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php` | L27 (`ocrResult()`) | `fromLlmText()` へ渡す文字列を囲みつきにする |
| `tests/Feature/Projects/AnalysisPipelineTest.php` | L82-135 (`extractFixture` / `decompositionFixture` / `scenarioFixture`) | 3 つの fixture 関数の戻り値を囲みつきにする (24 か所の呼び出しは無変更) |
| 同 | L418-429 | 「コードフェンス付き JSON も受理する」→ **「囲みが 2 つある応答は fence_multiple で拒否され有界リトライに乗る」へ書き換える** (囲みつき受理は全 fixture が常時証明するので専用テストは不要になる) |
| 同 | L387-389 / L406-410 / L448-450 | 不正応答の文字列はそのまま (区分が `fence_absent` に変わるだけで挙動は同じ)。期待値に区分名を書いている箇所があれば更新 |
| `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` | L70-101 (3 fixture) | 囲みつきへ |
| 同 | L203-214 | 「これは JSON ではありません」→ `failure_category` が `llm_output_invalid_` で始まる (現行の `str_starts_with` 照合はそのまま通る) |
| `tests/Feature/Llm/CannedPromptResponsesTest.php` | L103-140 | canned が DTO を通過する 4 テスト (施策 5 で緑に戻る) + 囲みつきである固定を 1 本追加 |
| `tests/Feature/Projects/ScenarioBookendMaterializeTest.php` | L62-125 (`bookendExtractJson` / `bookendDecomposeJson` / `bookendScenarioJson`) | 囲みつきへ |
| `tests/Feature/Notifications/ManualAnalysisNotificationTest.php` | L68-96 | インラインの `json_encode(...)` 3 か所を `FencedLlmResponse::wrapArray(...)` へ |

### 既存テストの扱い (意図的な契約の置換であること)

- 消す振る舞いは **1 つだけ**: 「囲みが無い応答を受理する」。これは正典 i2 が
  明示的に禁じた振る舞いであり、**同じ変更で反対側 (拒否されること) をテストで固定する**。
  つまりテストの削除ではなく、**旧契約を新しい不変条件へ置換するための意図的な更新**である。
- 「コードフェンス付き JSON も受理する」テストは削除ではなく**書き換え**で、
  受理の証明は全 fixture が常時担う。空いた枠には新しい保証
  (囲み 2 つ = `fence_multiple` の拒否とリトライ) を入れる。
- それ以外のテストは**入力の包み方だけ**が変わる (アサーションは不変)。

### 統合層の非漏洩テスト (i9。単体層だけでは足りない)

施策 3 の単体テストは `getMessage()` / `userMessage()` を見るが、**記録と DB は見ていない**。
以下を Feature テストへ追加する (`tests/Feature/Projects/AnalysisPipelineTest.php`)。

- [ ] sentinel (`SENTINEL-SOP-BODY-9f2c`) を含む応答を `Prompt::fake()` で流し、
      **再試行ログの context** (`AI 解析の LLM 呼び出しを再試行します`) に sentinel が無いこと
- [ ] **終端ログ** (`AI 解析の抽出段 (終端)`) の context に sentinel が無いこと
- [ ] `analysis_jobs.error` が `userMessage()` の定型文と**完全一致**し sentinel を含まないこと
- [ ] 上記は 6 区分の dataset で回す (区分ごとに sentinel の混ぜ方を変える)

### テスト計画（順序）

- [ ] 施策 3 の新規テストと、本施策の**契約反転テスト** (囲み無しの拒否 / 囲み 2 つの拒否) を
      **実装より先に**追加し、赤を確認する
- [ ] 施策 1・2 の実装後に、残りの fixture 包装 (24 か所の呼び出しは無変更) を行う
- [ ] pipeline の統合テストを緑にし、統合層の非漏洩テストを追加する
- [ ] `composer test` 全緑 (Feature / Unit / Architecture)、`pnpm test` 全緑

### リスク

- fixture を囲みつきにする際、`Prompt::fake()` の応答順序に依存したテストがあるため
  順序は変えない (包むだけ)。

---

## 施策 7: 復号点の単一性 gate を新設する (i1)

### 変更箇所（新規のみ）

- `tests/Support/Prompts/PromptFactoryPopulation.php` — `app/Prompts/` の再帰列挙
  (走査根の不在は fail-fast)
- `tests/Support/Llm/LlmResponseSeamScanner.php` — 走査器 (純関数)
- `tests/Support/Llm/LlmResponseSeamFinding.php` — 走査結果 (解決状態つき)
- `tests/Support/Llm/LlmResponseHandling.php` — 応答の扱いの分類 enum (テスト側)
- `tests/Support/Llm/DecodePointPublicSurface.php` — 公開面の判定 (純関数。検査 7 と負例が共有)
- `tests/Architecture/LlmResponseDecodePointGateTest.php` — 目録 + 8 検査
- `tests/Unit/Architecture/LlmResponseSeamScannerTest.php` — 検出器の自己検査 (負例・正例・未解決)
- `tests/Architecture/fixtures/llm-seam/*.php.txt` — 負例・正例の見本

### 既存基盤の再利用（同じ列挙を 2 本持たない）

- 参照 site の抽出は **`Tests\Support\PhpReferenceScanner`** を使う
  (`ReferenceKind::MethodCall` / `StaticCall` / `NameReference` を完全修飾名まで解決して返す。
  `tokenIndex` と `tokens()` で「site の直後・直前のトークン列」を見られる)。
  新しいトークン走査器は書かない。
- 依頼文 YAML の列挙と parse は **`Tests\Support\PromptYaml`** を使う。
- `Tests\Support\TrackedPhpSourceFiles` は使わない (母集団が `app/` の宣言した根に限るため)。

### 目録（gate ファイル内。deny-by-default）

```php
/**
 * 応答の扱いの分類 (テスト側 enum。`string` にすると綴り間違いがどの検査にも当たらず
 * 分類漏れになるため型で閉じる)。`tests/Support/Llm/LlmResponseHandling.php`。
 */
enum LlmResponseHandling
{
    /** 応答を復号点 (`LlmJson::decode`) 経由で構造化データとして読む */
    case Decoded;

    /** 提供元が形を保証する経路 (構造化出力)。**現在 0 件** (枠だけ持つ) */
    case ProviderShape;

    /** 応答を構造化データとして読まない (自由文) */
    case FreeText;
}

/**
 * 依頼文 factory の応答の扱い (deny-by-default)。
 *
 * @return array<class-string, array{kind: LlmResponseHandling, template: string, reason: string}>
 */
function llmResponseHandlingInventory(): array
{
    return [
        SopExtractPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'sop-extract', 'reason' => ''],
        SopExtractFromMediaPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'sop-extract-media', 'reason' => ''],
        WorkDecompositionPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'work-decomposition', 'reason' => ''],
        ScenarioGenerationPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'scenario-generation', 'reason' => ''],
        ExampleSummaryPrompt::class => ['kind' => LlmResponseHandling::FreeText, 'template' => 'example-summary',
            'reason' => '見本の依頼文で応答は 1 文の要約 (文章) であり、構造化データとして読む経路を持たない'],
    ];
}

/**
 * 登録済みの受け取り関数 ({FQCN}::{method})。`executeSync()` の応答はこの引数として
 * 直接渡されなければならない (変数へ束縛する形は未解決として落ちる)。
 *
 * @return list<string>
 */
function llmResponseReceivers(): array
{
    return [
        'App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText',
        'App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData::fromLlmText',
        'App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData::fromLlmText',
    ];
}

/** 復号語彙 (`json_decode` / 囲みの印) を書いてよい唯一のファイル (完全一致 1 件)。 */
const DECODE_POINT_PATH = 'app/Support/Manual/LlmJson.php';

/** 復号語彙の不在を見る走査根 (存在しない根は fail-fast)。 */
const DECODE_VOCABULARY_ROOTS = [
    'app/Support/Manual',
    'app/DataTransferObjects/Manual/Analysis',
    'app/Services/Manual',
    'app/Prompts',
];
```

### 8 つの検査

1. **依頼文の全数分類 + 項目の妥当性**: `PromptFactoryPopulation::classes()` の全件が
   目録に在り、目録の鍵に現存しないクラスが無い (双方向)。母集団が空なら赤。
   併せて項目ごとに次を検査する — `kind` は `LlmResponseHandling` の 3 値のいずれか
   (型で保証)、`template` は非空かつ `resources/prompts/{template}.yaml` が実在、
   `Decoded` 以外は `reason` が **30 文字以上** (`Decoded` は `reason` が空文字列)。
2. **応答の受け取り口の全数分類 (3 分類 + 未解決は失敗)**: `app/` 全体の
   `ReferenceKind::MethodCall` かつ `name === 'executeSync'` の site を母集団とし、
   走査器が各 site の受け手を次の 3 つに分ける。
   - `resolved_prompt_factory`: 直前が `X::make(...)` で `X` が目録の鍵に解決できる
   - `resolved_other`: 直前が `X::make(...)` だが `X` が目録の鍵でない
     → 理由つきの別目録 (現在 0 件) に登録が要る
   - `unresolved`: それ以外の書き方 (変数への束縛 / container 解決 / 式)
     → **1 件でも赤** (共通規約 (b))
3. **応答の流れの構造的封じ**: `resolved_prompt_factory` かつ分類 `decoded` の site は、
   その site を囲む**最内の未閉じ `(`** の直前 3 トークンが
   `名前トークン` `::` `メソッド名` の形であり、解決した `{FQCN}::{method}` が
   `llmResponseReceivers()` に在ること。無ければ赤 (= 応答が変数や別サービスへ回る形を塞ぐ)。
   分類 `free_text` の site はこの検査の対象外 (受け取り関数を持たない)。
4. **`GuardedPrompt` の参照者の分類**: `App\Support\Llm\GuardedPrompt` を完全修飾名で
   参照する `app/` のファイルが、目録の依頼文 factory か
   `app/Support/Llm/`（窓口と実行単位そのもの）のどちらかであること。
5. **復号語彙の不在**: `DECODE_VOCABULARY_ROOTS` の PHP から、
   `DECODE_POINT_PATH` の 1 件を除いて、
   (i) 関数呼び出しとしての `json_decode` トークン、
   (ii) 逆引用符 3 連を含む文字列リテラル、が 1 件も出ないこと。
6. **依頼文と受理契約の同期**: 分類 `Decoded` の目録項目の `template` に対応する
   `resources/prompts/{template}.yaml` の `system_prompt` に、pin した所定の文
   (`出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください`) が含まれること。
   `PromptYaml::paths()` / `parseOrFail()` を使う。
7. **復号点の公開面の pin (i4 の実体)**: `Tests\Support\Llm\DecodePointPublicSurface::of(string $class)`
   (`class-string` を受け取り、public メソッドの名前・static か・引数の型と必須性・戻り値型を
   正規化して返す**純関数**) に `App\Support\Manual\LlmJson::class` を渡し、
   - public メソッドの集合が **`decode` / `schemaViolation` の 2 つと完全一致**すること
     (`decodeLenient` のような緩い入口を後から足すと赤くなる)
   - `decode` が `public static` で、**必須の `string` 引数ちょうど 1 つ**、戻り値型が `array`
   - `schemaViolation` が `public static` で戻り値型が `LlmOutputInvalidException`
   - protected / private の存在は問わない (公開面だけを pin する)

   ★**負例は同じ純関数を通す**。fixture (公開面を 1 つ増やした見本クラス) を
   `DecodePointPublicSurface::of()` に渡し、本番と**同一の判定経路**で赤になることを
   確認する (gate だけが Reflection を直接叩き、負例が別ロジックで数える形にはしない。
   それでは負例が本番 gate の検出力を証明しない)。
8. **受け取り関数が復号点に直結していること**: `llmResponseReceivers()` の各項目について、
   その**メソッド本体の中**で、
   - `App\Support\Manual\LlmJson::decode` の完全修飾で解決できる静的呼び出しが**ちょうど 1 件**
   - メソッドの**第 1 引数の変数**が本体の中で**ちょうど 1 回**現れ、その出現が
     その静的呼び出しの**直接の引数**である
     (別変数への代入 / 別サービスへの受け渡し / 2 回目の利用はいずれも赤)
   を検査する。これで「`executeSync()` の応答が受け取り関数へ入ったあと、復号点以外へ
   流れる」経路が構造的に閉じる (検査 3 が入口、検査 8 が出口)。

### 走査器の名前解決と fail-closed（共通規約 (a)(b)(e)）

- クラス名は `PhpReferenceScanner` が返す**完全修飾名**で突き合わせる
  (`use` / group use / 別名 / 部分修飾を解いた結果)。短名一致はしない。
- **`json_decode` の判定は関数名の完全修飾解決で行う** (トークンのテキスト一致だけでは
  回避経路が残る)。次の 4 形をすべて違反として拾う。
  - 素の呼び出し `json_decode(`
    (名前空間の中でも、同名の関数が名前空間に無いので実効は global の `json_decode`)
  - **完全修飾** `\json_decode(` (`T_NAME_FULLY_QUALIFIED`)
  - **`use function` の別名** (`use function json_decode as decodeJson;` → `decodeJson(`。
    先頭に `\` を書いた綴りも同じく global へ解決する)

  ★**違反にするのは「解決後の完全修飾名が厳密に `json_decode`」のものだけ**である
  (共通規約 (a) と同じ規律を関数側にも適用する)。したがって
  `use function Foo\{json_decode as decodeJson};` は `Foo\json_decode` へ解決されるので
  **違反ではない** (末尾名だけで判定すると同名の別関数を誤検出する)。
  group use は**別名解決を実装する対象**には含めるが、**global の回避例ではない**
  (非違反の正例として負例集に置く)。

  判定は**区切りで割ったトークンの完全一致** ((e)) で行い、部分文字列一致・正規表現の
  語境界に頼らない。**直前の有意トークンが `->` / `?->` / `::` / `function` の場合は対象外**
  (メソッド呼び出し・メソッド宣言)。
- **文字列リテラルの `json_decode` も違反**にする (`call_user_func('json_decode', …)` /
  `array_map('json_decode', …)` の回避を塞ぐ。完全一致のリテラルだけを見る)。
- **動的な関数呼び出し** (`$fn($text)` / 変数に入れた callable / `Closure::fromCallable($var)`) は
  **保証範囲外**であり docblock に明記する (名前が静的に決まらないため)。
- 逆引用符 3 連は `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` の
  中身に対してのみ見る (コメント・docblock は `PhpTokenScan::normalize()` で落ちる)。
- 受け手を解決できない `executeSync` site は**未解決として gate を失敗させる**
  (候補から無言で外さない)。

### 受け手の解決アルゴリズム (検査 2 / 3 の実体)

`executeSync` の site (トークン索引 `i` = メソッド名トークン) から次の手順で解決する。
**どの段でも条件を満たさなければ `unresolved`** とし、gate を失敗させる。

1. `i-1` の有意トークンが `->` または `?->` であること。
2. `i-2` が `)` であること。
3. `i-2` から**後方へ括弧の対応を数えながら**走り (`)` で +1 / `(` で −1)、
   0 になった位置を `make(` の開き括弧とする。正規化済みトークン列を使うので
   コメント・文字列リテラルの中の括弧は現れない
   (`PhpTokenScan::normalize()` が落としている)。
   対応が取れないまま列の先頭に達したら `unresolved`。
4. 開き括弧の直前 3 トークンが `名前トークン` `::` `T_STRING(make)` の形であること
   (`名前トークン` は `T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`)。
   形が違えば `unresolved` (変数受け手 `$prompt->executeSync()` / `static::make()` /
   container 解決はここで落ちる)。
5. `名前トークン` を import 表で完全修飾名へ解決する。目録の鍵なら
   `resolved_prompt_factory`、そうでなければ `resolved_other`。

★この手順は `make()` の引数に**入れ子の呼び出し・配列・名前付き引数・クロージャ・複数行**が
あっても成立する (段 3 が括弧の対応だけを見るため)。正例・未解決例は走査器の自己検査で固定する。

### 検査 8 の実体 (受け取り関数の中の流れ)

`PhpReferenceScanner` の site は `class` / `callable` (スコープ) を持つので、
受け取り関数のスコープに属する site だけを取り出せる。手順:

1. 目録の `{FQCN}::{method}` を含むファイルを開き、正規化トークン列と site を取る。
2. スコープが当該 `callable` の site のうち、`StaticCall` かつ受け手が
   `App\Support\Manual\LlmJson` に解決でき、名前が `decode` のものを数える → **1 件でなければ赤**。
3. そのメソッドの**第 1 引数の変数名**を宣言部から読む (`function fromLlmText(string $text)`)。
   宣言部が読めなければ `unresolved` → 赤。
4. メソッド本体 (対応する `{` … `}`) の中の `T_VARIABLE` でその名前と一致するものを数える →
   **1 件でなければ赤**。
5. その 1 件が、段 2 の呼び出しの `(` と `)` の**間にある直接の引数**であること
   (`(` の次のトークンで、次が `)` または `,`)。違えば赤。

### docblock に書く「保証しないもの」

- 反射・動的に組み立てたクラス名・文字列キーだけの container 解決の経路は見えない。
- **動的な関数呼び出し** (`$fn($text)` / 変数に入れた callable) は見えない
  (文字列リテラルの完全一致だけは拾う)。
- vendor 配下と `tests/` 配下は走査しない。
- `json_decode` の不在を保証するのは**宣言した 4 つの走査根の中だけ**である
  (`app/` の他の 13 ファイルの `json_decode` は LLM と無関係な経路であり対象外。
  応答をそこへ運ぶ経路の側は検査 2・3 が塞ぐ)。
- `app/Services/AI/Testing/` は**応答を作る側**なので走査根に入れない
  (囲みの印を持つのが正しい)。
- 「復号点を通す」以外の 2 分類 (`provider_shape` / `free_text`) は目録の宣言を信じる
  (宣言と実装の食い違いを機械で見てはいない)。

### テスト計画（テストファーストの手順）

1. `tests/Unit/Architecture/LlmResponseSeamScannerTest.php` を先に書く → 走査器が
   無いので**赤**。ケース:
   - 正例: `X::make($a, $b)->executeSync()` が受け取り関数の引数にある形 → 違反 0
   - 負例 1: `$text = X::make(...)->executeSync();` → **未解決**として検出
   - 負例 2: `Foo::make(...)->executeSync()` (目録外の型) → `resolved_other`
   - 負例 3: 受け取り関数でない関数の引数に渡す形 → 検査 3 の違反
   - 負例 4: `json_decode($t, true)` を走査根のファイルに置く → 検査 5 の違反
   - 負例 5 ((e) の 3 形): `my_json_decode(` / `json_decode_all(` / `$o->json_decode(`
     → いずれも**違反にしない** (誤検出しないこと)
   - 負例 6: 逆引用符 3 連の文字列リテラル → 検査 5 の違反
   - 負例 7 (回避経路): `\json_decode(` / `use function json_decode as decodeJson;` +
     `decodeJson(` / `call_user_func('json_decode', …)`
     → いずれも**検査 5 の違反として検出**する
   - 正例 7b (誤検出しないこと): `use function Foo\{json_decode as decodeJson};` + `decodeJson(`
     → 解決後が `Foo\json_decode` なので**違反にしない**
   - 負例 8 (受け手解決): `make()` の引数に入れ子の呼び出し・配列・名前付き引数・
     クロージャ・改行がある正例 → `resolved_prompt_factory` /
     `$prompt->executeSync()` / `static::make(…)->executeSync()` / 対応の取れない括弧
     → `unresolved`
   - 負例 9 (検査 8): 受け取り関数から `LlmJson::decode` を消す / `$text` を
     別変数へ代入する / `$text` を 2 回使う → いずれも赤
   - 負例 10 (検査 7): 公開面を 1 つ増やした fixture クラス → 赤
   - 母集団 0 件 (存在しない根) → **例外で落ちる** (fail-fast)
2. 走査器を実装 → 1 が緑
3. `tests/Architecture/LlmResponseDecodePointGateTest.php` を書く → 目録が空なので**赤**
4. 目録を埋める → 緑
5. 感度の実測: 目録から 1 件抜く / 依頼文を 1 本足す / `app/Services/Manual/` に
   `json_decode` を一時的に置く → いずれも赤になることを確認してから戻す
   (負例 fixture でも同じ経路を固定する)

### リスク

- gate が偽陽性を出すと実装が進まない。緩和は「解決できる書き方を 1 つに絞る」設計そのもの
  (現行 4 site はすべてその形)。
- 走査器が `PhpReferenceScanner` の emission 契約 (1 つの静的呼び出しが
  `NameReference` と `StaticCall` の 2 site を生む) を二重に数えないこと。
  母集団は `MethodCall`/`executeSync` に限るので二重計上は起きない。

---

## 施策 8: 文書

### 変更箇所

- `AGENTS.md`「ドメイン固有規約」に **21 番**として追記
  (現在 20 番まで。既存の番号は動かさない)
- `docs/architecture.md`「LLM プロンプト防御の窓口方式」の**直後**に新節
  「LLM 応答の復号点 (単一) と失敗の区分」を追加 (入口の節の隣に出口の節を置く)

### 追記内容（要旨。実装時に本文を書く）

- AGENTS.md 21: 「LLM 応答を構造化データとして読む場所は
  `App\Support\Manual\LlmJson::decode()` の 1 か所である。受理契約は**囲みちょうど 1 つ**で、
  緩い入口は持たない。依頼文を足すときは `LlmResponseDecodePointGateTest` の目録へ
  分類を登録し、`decoded` 分類なら依頼文 YAML に所定の出力指示を書く。
  失敗区分の語彙は `LlmOutputInvalidReason` が正本で、**再試行の可否は区分で分けない**。
  保証しないものの正本は gate と `LlmJson` の docblock (本書に写さない)」
- docs/architecture.md 新節:
  - 受理文法と区分の決定順序 (表)
  - 区分ごとの意味と「切り詰めは推定であり、正本は `llm_call_logs.finish_reason`」
  - 観測の読み方: 件数と最終失敗数は Log から数えられる。**率は現行 Log から出せない**
    (分母が無い。必要なら `llm_call_logs` の `prompt_template` 別の行数と突合する)
  - **出荷後の観測と巻き戻し手順**: `llm_output_invalid_fence_absent` /
    `_fence_multiple` が終端失敗の主因として現れたら、一手目は依頼文の出力指示の修正。
    回復しなければ**本変更を導入したリリースコミット (または変更一式) を revert** する。
    squash / rebase merge でも成立する言い方にし、「マージコミットの revert」とは書かない。
    **復号点だけを部分的に緩める形は採らない** (受理契約を緩める並走を作らない = 思考原則 3)
  - **本変更の本番デプロイを境界として**、それ以前の記録では同じ事象が `invalid_json` である
    旨の注記。**日時・リリース SHA は書かない** (自己参照できず、実装時点で未確定。
    実値の正本はデプロイ記録 / リリースノートで、文書はそこを指す)

### テスト計画

- [ ] `AGENTS.md` の追記は既存の番号を動かさない (renumber 禁止)
- [ ] `docs/architecture.md` の節見出しの追加が既存の doc テストを壊さないこと
  (`composer test` の Architecture レーンで確認)

### リスク

- 文書と実装が食い違うと規約が形骸化する。保証範囲の正本は docblock 側に置き、
  文書には写さない (AGENTS.md の既存規律に合わせる)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更が LLM 応答の復号点とその周辺 (enum / 依頼文 YAML / canned / 解析テスト / 新設 gate) に閉じており、docs/TODO.md の Open 9 件 (T249 / T109 / T127 / T128 / T193 / T201 / T205 / T206 / T207) と対象ファイルが重ならない (実測)。テストの書き換えが 8 ファイルに及ぶため、他タスクと同じブランチで混ぜると赤の原因が切り分けにくい。巻き戻しが「本変更を導入したリリースコミット (または変更一式) の revert」1 手で済むことも standalone の利点である (squash / rebase merge でも成立する) |
| 競合リスク | 低。`AGENTS.md` / `docs/architecture.md` の追記だけが他タスクと衝突しうる (追記位置が末尾・新節なので機械的に解消できる) |
| 実装順 | (1) 施策 3 の新規テスト + 施策 6 の**契約反転テストだけ**を書いて赤を確認 → (2) 施策 1 (enum) → (3) 施策 2 (復号点) → (4) 施策 6 の残り (fixture の包装 + 統合層の非漏洩テスト) → (5) 施策 4 (依頼文) → (6) 施策 5 (canned) → (7) 施策 7 (gate。走査器の自己検査を先に赤くする) → (8) 施策 8 (文書) |

## 完了条件

1. 検証コマンド全 green: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
2. `scripts/bug-hunt-shard.sh pipeline-smoke --check` (費用ゼロの preflight) 通過
3. **互換性確認 A**: pipeline-smoke の実走 1 回で
   `sop-extract` / `work-decomposition` / `scenario-generation` が囲みつきで返る
   (課金あり・`BUGHUNT_ORCHESTRATOR=1` の親のみ・**ユーザー承認が必要**)
4. **互換性確認 B**: 画像 SOP の解析 1 件で `sop-extract-media` が囲みつきで返る
   (pipeline-smoke の `REQUIRED_TEMPLATES` に含まれないため別実行が必要。
   課金あり・**ユーザー承認が必要**)
5. 3 と 4 は**エージェント判断では実行しない**。未実施なら「未確認のまま完了」にせず、
   **外部確認待ち**として TODO のクローズ時にどちらが未実施かを明示する
   (自動テストの green を「実 provider で確認済み」と書かない)
