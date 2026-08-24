# 概念設計レビュー依頼 (Round 1)

## アプリの使命 (North Star) — AGENTS.md より
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 — AGENTS.md より
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


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

## 追加の前提 (この設計に固有)

本設計は社内の「機能台帳」で確定した正典 (canonical design) v1 への追従である。正典の不変条件 i1〜i10 は他リポジトリの実装比較と外部調査を経て確定しており、**正典そのものへの異議はレビュー対象外**である。レビューしてほしいのは「この aicue というリポジトリで、正典を満たす形をこの方針で実装してよいか」である。

### 正典 v1 の不変条件 (抜粋)

- i1: 応答を構造化データとして受け取る経路は二択 (リポジトリ内の復号点 / 提供元が形を保証する経路)。どちらでもない自前の読み方が本体コードに存在しないことを機械検査で固定する。依頼文が増えたときに黙って抜けない形であること。
- i2: 復号点の既定の受理契約は厳しい。囲み (コードフェンス) はちょうど 1 つを要求し、採るのは常に最初の囲みの直後の値 (決定論)。囲みの外にもう 1 つ囲みがあれば受け取らない。
- i3: 囲みの境界は文字の並びの規則ではなくデータの構造の走査で決める。応答データ中の囲み記号を終端に数えない。閉じの印だけを閉じと認める。
- i4: 緩い受け取り方は入口を分けて持ってよい。ただし既定は厳しい入口であり、緩い入口は呼んでよい場所を登録制で固定する。「緩い方が既定で誰でも呼べる」形は採らない。
- i5: 失敗は専用例外で止め、型のある区分を持つ。区分は少なくとも 6 つを別々に見分ける (囲みが無い / 囲みが複数 / 構文が壊れている / 最上位が期待した入れ物でない / 値が完結しないまま終わった / 値は完結したが閉じの囲みが無い)。
- i6: 値が完結しないまま終わったという判定は構造からの推定であって断定ではない。提供元が停止の理由を返す場合はその値が正本であり、推定が上書きしない。
- i7: 形の検査 (スキーマ検証) の失敗は復号の失敗と同じ再試行の起動条件へ合流する。違反位置を観測用に持ち、制御の分岐には使わない。
- i8: 例外は利用者向け文言と内部詳細を分けて持つ。
- i9: 失敗の診断に応答本文を残さない。残してよいのは長さ・要約値・囲みの数え上げ・理由の区分・違反位置と、どの呼び出しか辿れる鍵 1 つ。
- i10: 失効の例外は上限のある再試行の起動条件になる。上限は回数で必ず区切り、実時間の期限は任意。

台帳が「aicue に欠けている」と名指しした点: i1 (迂回の機械検査が無い) / i2 / i3 / i4 / i5 / i6。充足済み: i7 / i8 / i9 / i10。

### 現行実装 (HEAD) の全文

`app/Support/Manual/LlmJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\Enums\Manual\LlmOutputInvalidReason;
use App\Exceptions\Manual\LlmOutputInvalidException;

/**
 * LLM 出力テキストの JSON デコード共通ヘルパ (コードフェンス除去 + json_decode + array 検証)。
 * 不正は LlmOutputInvalidException (有界リトライのトリガー)。
 */
final class LlmJson
{
    /**
     * @return array<array-key, mixed>
     */
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

    /**
     * スキーマ違反の例外を生成する (DTO 検証用の短縮形)。
     * $path は観測用の違反位置 (例: validation.works.2)。省略時は null で、
     * 既存の呼び出し側は無変更のまま動く。
     */
    public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
    {
        return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail, $path);
    }
}
```

`app/Enums/Manual/LlmOutputInvalidReason.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * LLM 出力 JSON の検証失敗分類 (report ログで機械集計する。文字列 drift を型で防止)。
 */
enum LlmOutputInvalidReason: string
{
    /** JSON としてパースできない (切り詰め・コードフェンス外の説明文混入等) */
    case InvalidJson = 'invalid_json';

    /** JSON だがスキーマ違反 (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
    case SchemaViolation = 'schema_violation';
}
```

`app/Exceptions/Manual/LlmOutputInvalidException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\LlmOutputInvalidReason;
use RuntimeException;

/**
 * LLM 出力 JSON の検証失敗 (有界リトライのトリガー。§10.7-2)。
 * AnalysisPipeline::withBoundedRetry の retryable 集合に含まれ (transient な
 * provider/connection 例外と同じ扱い)、試行上限または実時間 deadline の到達で
 * failJob (ユーザー向け文言) へ落とす。
 */
final class LlmOutputInvalidException extends RuntimeException
{
    public function __construct(
        public readonly LlmOutputInvalidReason $reason,
        string $detail,
        /** 違反位置 (例: validation.works.2)。観測専用で制御フローには使わない */
        public readonly ?string $path = null,
    ) {
        parent::__construct("AI の応答を解釈できませんでした。再実行してください。({$reason->value}: {$detail})");
    }

    /** ユーザー向け要約 (内部 detail を error 列へ漏らさない) */
    public function userMessage(): string
    {
        return 'AI の応答を解釈できませんでした。再実行してください。';
    }
}
```

呼び出し側 (3 か所) はいずれも `{Dto}::fromLlmText(string $text)` の中で `LlmJson::decode($text)` を呼び、以降は Assert / 手書き検証で narrow して `LlmJson::schemaViolation($detail, $path)` を投げる。パイプラインは `AnalysisPipeline::withBoundedRetry()` が回数上限 (config `manual.analysis_llm_max_retries` = 2) と実時間 deadline (1,080 秒) で再試行し、`isTransient()` は `LlmOutputInvalidException` を retryable として許可している。

依頼文 (prompt YAML) 4 本はいずれも system_prompt に「出力は JSON のみ (前後に説明文・コードフェンスを付けない)」と書いている。

---

## 概念設計

# 概念設計: llm-output-single-decode-point v1 追従 (LLM 応答の復号点を厳しくし、失敗を数えられるようにする)

## 背景・課題

lctl 機能台帳 feature `llm-output-single-decode-point` は 2026-08-22 に正典 v1 が確定し
(`design.md` の不変条件 i1〜i10)、aicue セルは **implemented → update_pending (pre-v1 → v1)** に
改められた。台帳が名指しする不足は 5 点 (i1・i2・i3・i4・i5) + 切り詰めの明示 (i6) で、
本リポジトリ HEAD (b207bafa) の実読で全数を再確認した。

現行の復号点 `app/Support/Manual/LlmJson.php` は 38 行で、次の 4 段しか持たない。

1. `trim()`
2. 先頭が ``` なら `preg_replace('/^```[a-zA-Z0-9]*\s*/')` と `preg_replace('/\s*```$/')` で剥がす
3. `json_decode($t, true)`
4. `is_array()` でなければ `LlmOutputInvalidReason::InvalidJson` で例外

ここから出る具体的な欠落は次のとおりである。

- **囲みの個数を数えない (i2 欠)**。応答が `` ```json {A} ``` `` の後にもう 1 つ
  `` ```json {B} ``` `` を持っていても、先頭の `` ``` `` を剥がして末尾の `` ``` `` を剥がした
  「A + ``` + B」を JSON として読もうとする。壊れて落ちるので実害は出ていないが、
  **どのブロックが採られるかが応答の内容で決まる**構造になっている。依頼文には他人の書いた
  SOP 本文が `<user_input>` として入る (prompt-injection-defense の窓口経由) ので、
  後続ブロックの差し込みは**外から仕掛けられる**入力である。
- **境界を文字の並びで決める (i3 欠)**。`/\s*```$/` は「応答データの中に現れた ```」と
  「囲みの終端」を区別できない。逆に、閉じの印と「別言語の開始の印」も区別しない。
- **既定が緩い (i4 欠)**。入口が 1 つで囲みは任意なので、「囲み無しでも読む」緩い契約が
  唯一の入口 = 誰でも呼べる既定になっている。
- **失敗の区分が粗い (i5 欠)**。`invalid_json` の 1 case に「囲みが無い / 囲みが複数 /
  構文が壊れている / 最上位が入れ物でない / 切り詰め / 閉じの囲みが無い」が全部入る。
  結果として**作り直せば直るもの**と**何度やっても直らないもの**が集計で同じ値になる。
- **切り詰めの推定が存在しない (i6 欠)**。判定そのものが無い。
- **迂回の機械検査が無い (i1 欠)**。`tests/Architecture/` に `LlmJson` を見る検査は
  grep 一致 0 件である。現在 3 か所 (`ExtractedSopData` / `WorkDecompositionResponseData` /
  `GeneratedScenarioData`) が唯一の復号点を通っているのは**慣行のみ**で、
  新しい依頼文が自前で `json_decode` を書き始めても赤くならない。

一方、正典が aicue の実装から採った側 (i7 の違反位置 + 再試行への合流 / i8 の利用者向け文言の
分離 / i9 の応答本文を残さない / i10 の回数上限 + deadline) は HEAD で充足している。
本作業はそこを壊さずに、欠けている受理契約・失敗語彙・迂回検査を足す。

## 改善アイデア

**「厳しい入口 1 つ」に作り替え、失敗を 6 区分 + 直交 1 区分で数えられるようにし、
その入口の外で応答を自前で読めないことを機械で固定する。**

### A. 受理契約を「囲みちょうど 1 つ」へ (i2 / i3)

復号点の唯一の入口は、次の形の応答だけを受け取る。

```
（任意の空白）
```json          ← 開きの印 (言語札は任意)
{ … }            ← 最上位が入れ物 (object / list) の値ちょうど 1 つ
```              ← 閉じの印 (印だけの行。言語札が付いていたら閉じと認めない)
（任意の後書き。ただし囲みの印を含んではならない）
```

判定は正規表現ではなく**構造の走査**で行う。開きの印の直後から、文字列リテラルとその中の
打ち消しを解釈しながら括弧の対応を追い、深さが 0 に戻った位置を値の終端とする。
**囲みの印を数えるのは、この構造の走査で決まった値の区間の外側だけ**である。よって
応答データの中に現れた ``` は終端に数えない。

### B. 失敗を 6 区分にする (i5) + 切り詰めは推定と明示する (i6)

`LlmOutputInvalidReason` を作り替える。既存 `invalid_json` は 6 区分へ分解して**消す**
(AGENTS.md 思考原則 3: 後方互換の並走を残さない)。`schema_violation` は直交軸なので残す。

| 新しい区分 | 意味 |
|---|---|
| `fence_absent` | 囲みの開きの印が 1 つも無い (素の JSON もここに落ちる) |
| `fence_multiple` | 採った囲みの外にもう 1 つ囲みの印がある |
| `syntax_broken` | 囲みの中身が JSON として読めない |
| `top_level_not_container` | 最上位が入れ物 (object / list) ではない |
| `value_incomplete_inferred` | 値が完結しないまま終端に達した = **切り詰めの推定** |
| `closing_fence_absent` | 値は完結したが閉じの印が無い |
| `schema_violation` | 読めたが形が違う (既存。違反位置 `path` を持つ) |

`value_incomplete_inferred` は **値そのものに "inferred" を入れる**。記録に出る文字列
(`llm_output_invalid_value_incomplete_inferred`) を読んだ人が、これが断定ではないと
値だけで分かるようにするためである (i6)。提供元の停止の理由は現在復号点へ渡していないので
上書きの余地は無い (正典 q2 が未決なので引き回しは作らない = 思考原則 2)。

### C. 依頼文の出力指示を「囲みちょうど 1 つ」へ揃える (受理契約との整合)

現行の依頼文 4 本 (`sop-extract` / `sop-extract-media` / `work-decomposition` /
`scenario-generation`) は「出力は JSON のみ (前後に説明文・コードフェンスを付けない)」と
**囲みを禁じている**。A の受理契約はこれと正面から衝突するので、依頼文の側を
「``` の囲みちょうど 1 つに入れて出す」へ揃える。詳細は「§設計判断 1」。

### D. 緩い入口は作らない (i4)

正典 i4 は緩い入口を「持ってよい (MAY)」としか言っていない。C により**緩い受け取りを
必要とする呼び出しが 1 つも無くなる**ので、緩い入口は作らない (思考原則 2)。
代わりに、復号点の**公開面をちょうど 1 つに機械で pin** する
(`PromptDefenseWindowGateTest` が窓口の公開面を完全一致で pin しているのと同じ形)。
将来緩い入口が要るときは、この pin が赤くなるので登録制を同じ変更で作らざるを得ない。

### E. 迂回の機械検査を新設する (i1)

`tests/Architecture/LlmResponseDecodePointGateTest.php` を新設し、次の 3 つを
deny-by-default で固定する。

1. **依頼文の全数分類**: `app/Prompts/*.php` を全数走査し、1 本ずつ「復号点を通す /
   提供元が形を保証する経路 / 応答を構造化データとして読まない (自由文)」のどれかに
   分類された目録に**完全一致**で載っていること。依頼文を足したら赤くなる
   (= 正典 i1 の「依頼文が増えたときに黙って抜けない形」)。
2. **応答の受け取り口の全数分類**: `app/` の `executeSync()` 呼び出し点を全数走査し、
   目録に載っていること + 「復号点を通す」分類の呼び出し点は、応答が**登録済みの
   受け取り関数の引数として**渡されていること。
3. **自前の読み方の不在**: 登録済みの受け取り関数を持つファイル群 (+ 復号点自身を除く
   `app/DataTransferObjects/Manual/Analysis/` + `app/Services/Manual/` + `app/Prompts/`) に
   `json_decode` と囲みの印の文字列リテラルが現れないこと。

`app/` 全体の `json_decode` を対象にはしない。実測 17 か所のうち 16 か所は OIDC メタデータ・
webhook 署名・冪等キー等の**LLM と無関係な経路**で、全部を目録に載せると
「LLM 応答の復号点」という不変条件と関係のない登録が 16 件混ざり、目録が意味を失う
(走査根は LLM 応答が触るディレクトリに限り、根が消えたら fail-fast させる)。
保証しない範囲は gate の docblock に明記する (AGENTS.md 静的検査の共通規約 (b))。

### F. 区分の拡張を観測へ反映する

`AnalysisPipeline::observabilityCategoryFor()` は `'llm_output_invalid_'.$reason->value` を
組み立てているので、語彙は**自動で広がる**。DB 列には入っていないので移行は不要
(実測: `invalid_json` の文字列は enum 定義の 1 か所にしか無い)。

**再試行の可否は区分ごとに分けない** — `LlmOutputInvalidException` は今と同じく丸ごと
retryable に置く。理由は §設計判断 2。

## 設計判断

### 判断 1: 依頼文を「囲みちょうど 1 つ」へ替える (依頼文を据え置いて緩い入口へ寄せる案は採らない)

正典 i2 の厳しい入口は**囲みが 1 つあること**を要求する。現行の依頼文は逆に囲みを禁じている
ので、二択になる。

- 案 (a) 依頼文を「囲みちょうど 1 つ」へ替え、厳しい入口を主経路にする
- 案 (b) 依頼文を据え置き、3 (実際は 4) 本の呼び出しを登録済みの**緩い入口**へ寄せる

**(a) を採る。** 根拠は 3 つ。

1. **(b) は既定が緩い形をそのまま残す**。全呼び出しが緩い側に並ぶので登録制が形骸化し、
   厳しい入口は呼び出し元 0 の死んだコードになる (思考原則 2 に反する)。正典が
   「緩い方が既定で、誰でも呼べる形は採らない」と書いた状態そのものが温存される。
2. **(a) はモデルの地の振る舞いに逆らわない**。現行の依頼文が「コードフェンスを付けない」と
   **わざわざ禁じている**こと自体が、モデルの既定が囲みを付ける側だという実証である
   (禁じてもなお付けてくるので現行の復号点に剥がす処理が要り、
   `tests/Feature/Projects/AnalysisPipelineTest.php:418` が「コードフェンス付きも受理する」を
   固定している)。囲みを要求する方が非準拠の確率は下がる。
3. **失敗しても止まらない**。囲み無しの応答は `fence_absent` で有界リトライに乗る
   (回数上限 `manual.analysis_llm_max_retries` = 2 → 最大 3 試行、実時間 deadline 1,080 秒)。
   再試行は再サンプリングなので、書式の取りこぼしは高い確率で次試行で直る。

**リスクと受容**: 本番のモデルが囲みを付けない側に偏ると、これまで成功していた解析が
`fence_absent` の連続で失敗しうる (回帰)。受容の根拠は上の 2 と 3 に加えて、
失敗が**区分つきで観測できる**ことである (`llm_output_invalid_fence_absent` の件数が
そのまま非準拠率の実測になる)。現行は同じ事象が `invalid_json` に埋もれて数えられない。

### 判断 2: 再試行の可否を区分ごとに分けない

区分を 6 つに割ると「`fence_multiple` は決定論的だから再試行しない」といった分岐を作りたく
なるが、**作らない**。理由:

- 復号の失敗はすべて**モデルの出力の書式**の問題で、次試行は再サンプリングなので出力が変わる。
  「決定論的」なのは復号の判定であって、応答の生成ではない。
- 非 retryable を増やすと、これまで再試行で救われていた事象が即失敗に変わる
  = 利用者から見た可用性の後退である。正典 i10 が要求するのは「上限があること」だけで、
  区分ごとの可否は要求していない。
- `isTransient()` は「retryable を先・deny を後」の順で書く既存規約 (deny を先に置くと
  将来の型変更で黙って再試行が止まる) を保つ。

したがって `isTransient()` / `userMessageFor()` は**無変更**である。

### 判断 3: 最上位に「並び」を許すかは据え置く

正典 q3 は未決で、現行 aicue は object でも list でも受ける。呼び出し側 3 か所はすべて
object 前提だが、狭める要求は正典に無いので**現行の寛容さを据え置く**
(`top_level_not_container` は「入れ物ではない」= scalar / null だけを落とす)。

### 判断 4: 診断の材料は増やさない

正典 i9 (応答本文を残さない / 辿れる鍵を 1 つ持つ) は HEAD で充足している
(再試行ログは `failure_category` と `failure_path` だけ、鍵は `analysis_job_id` + `step`)。
spirux 形の診断 DTO (長さ・要約値・囲みの個数) は本リポジトリに需要が無いので作らない
(思考原則 2)。代わりに「例外の文言に応答本文が入らない」ことを 6 区分すべてで
**テストで固定**する (区分が増えたときに本文を混ぜ込む改変が入らないようにする)。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」の入口である AI 解析 3 段は、LLM 応答の解釈に
  全面的に依存している。壊れた応答が黙って業務データ (統一 JSON / 作業分解表 / cuts) へ
  流れる余地を、受理契約の厳しさと区分つきの失敗で閉じる。
- **守りの効果**: SOP 本文経由で応答の後ろへ囲みを差し込む経路が閉じる
  (差し込みは `fence_multiple` で拒否。採る値は常に最初の囲みの直後 = 決定論)。
- **運用の効果**: 「なぜ解析が失敗するか」が 6 区分で数えられる。切り詰め
  (max_tokens 不足の疑い) と書式の取りこぼしが分離するので、`max_tokens` を上げるべきか
  依頼文を直すべきかが観測から決まる。
- **回帰の予防**: 依頼文を足したとき・新しい受け取り口を書いたときに、機械検査が赤くなる。

## 実装方針（概要）

| # | 変更 | 主なファイル |
|---|---|---|
| 1 | 失敗区分の作り替え (6 + 直交 1) | `app/Enums/Manual/LlmOutputInvalidReason.php` |
| 2 | 復号点を構造の走査へ作り替え (旧正規表現経路は同じ変更で削除) | `app/Support/Manual/LlmJson.php` |
| 3 | 依頼文 4 本の出力指示を「囲みちょうど 1 つ」へ | `resources/prompts/{sop-extract,sop-extract-media,work-decomposition,scenario-generation}.yaml` |
| 4 | canned 応答 4 本を囲みつきへ | `app/Services/AI/Testing/CannedPromptResponses.php` |
| 5 | 迂回検査の新設 (依頼文の全数分類 + 受け取り口の分類 + 自前の読み方の不在) | `tests/Architecture/LlmResponseDecodePointGateTest.php` + `tests/Support/Llm/` の走査器 |
| 6 | テストの受理契約の更新 (囲み前提へ) | `tests/Unit/Manual/*` / `tests/Feature/Projects/AnalysisPipelineTest.php` / `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` / `tests/Feature/Llm/CannedPromptResponsesTest.php` / `tests/Feature/Projects/ScenarioBookendMaterializeTest.php` / `tests/Feature/Notifications/ManualAnalysisNotificationTest.php` |
| 7 | 復号点の新しい契約の単体テスト (受理 6 / 拒否 6 + 本文非漏洩) | `tests/Unit/Manual/LlmJsonTest.php` (新設) |
| 8 | 文書 (規約 1 項 + アーキテクチャ 1 節) | `AGENTS.md` ドメイン固有規約 / `docs/architecture.md` |

## 制約・前提

- **テストファースト**: 先に赤くするのは (i) 新設 `tests/Unit/Manual/LlmJsonTest.php` の
  6 区分、(ii) 既存 `tests/Unit/Manual/AnalysisDtoTest.php:20-23` (囲み付きと素の JSON の
  両方を受理する、を固定している)、(iii) `tests/Feature/Projects/AnalysisPipelineTest.php:418`
  (「コードフェンス付き JSON も受理する」)。(ii) と (iii) は**削除ではなく契約の書き換え**
  (禁止事項「既存テストの削除・上書き」は、不変条件が変わったときの意図的な書き換えを
  禁じるものではない。旧契約を新契約の名前で書き直し、削った振る舞いを新しいテストで受ける)。
- **後方互換の並走を残さない**: `invalid_json` case と正規表現による剥がしは同じ変更で消す。
- **移行不要**: `invalid_json` は DB 列に入らない (Log context のみ。実測で文字列の出現は
  enum 定義 1 か所)。migration は書かない。
- **テンプレート乖離台帳**: 変更対象は `docs/template-fingerprints.json` の 281 キーに
  1 つも無く、採用時債務一覧にも無い (実測)。よって `docs/template-divergence.md` と
  `LedgerPins` の変更は不要。新設する gate は aicue のドメイン固有 gate であり、
  同種の先例 (`AnalysisTokenBudgetInvariantTest` 等) も台帳に登録されていない。
- **PHP 列挙 ⇔ TypeScript の同期**: `LlmOutputInvalidReason` は
  `tests/js/architecture/enum-ts-sync-discovery.test.ts` の `PHP_ENUM_EXEMPTIONS` に
  理由付きで登録済み (画面へは値が渡らない) なので、case を増やしても TS 側の同期は不要。
- **既存の不変条件を壊さない**: プロンプト YAML の `max_tokens` / `client_options.timeout` の
  pin (`AnalysisTokenBudgetInvariantTest` / `AnalysisTimeBudgetInvariantTest`)、
  窓口の 1 本道 (`PromptGuardrailTest` / `PromptDefenseWindowGateTest`)、
  `strict_types` 全数宣言、`declare` + 日本語コメント、`echo`/`goto`/`global` 禁止、
  静的検査の共通規約 5 条 ((a) 完全修飾名 / (b) fail-closed / (c) 負例 /
  (d) 使わない収集をしない / (e) 語彙一致はトークン完全一致)。

## スコープ外

- 提供元の停止の理由 (`finish_reason` 等) を復号点へ渡すこと (正典 q2 が未決)。
- 提供元が形を保証する経路 (structured output) への移行。i1 の二択の片方だが、
  本リポジトリは復号点側を選んでおり、移行の要求は正典に無い。
  gate の分類語彙には枠だけ用意し、実体は作らない。
- 診断 DTO (応答長・要約値・囲みの個数) の新設 (判断 4)。
- 最上位に list を要求/禁止する狭め (判断 3)。
- 応答の記録・費用 (llm-cost-monitoring)、待ち時間の予算 (llm-prompt-wait-budget)、
  入口側の防御 (prompt-injection-defense) — いずれも別 feature の持ち分。
- `ScenarioRuleCheck` (読み取り後の決定的な規約検査) — 正典が範囲外と明記。
