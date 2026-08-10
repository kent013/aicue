Round 3 の指摘への対応を報告します。施策 5 の Critical 2 件と Warning 1 件、施策 9 の Warning に
すべて対応しました。再レビューし、各施策の判定と全体判定を出してください。

## 対応マトリクス

### 施策 5
- [Critical] `Llm` 判定が全段へ漏れる → **対応** (指摘どおり。段の切り分けという目的自体を壊す誤分類)。
  `private const array LLM_ATTRIBUTABLE_STAGES = [SmokeStage::Analysis, SmokeStage::LlmEvidence];`
  を定義し、条件 8 を `in_array($stage, LLM_ATTRIBUTABLE_STAGES, true) && (...)` に閉じた。
  設計に「閉じないと capture 失敗やリトライ痕がすべて Llm へ誤分類される」という理由も明記。
- [Critical] ffprobe 非 0 終了を `Render` に写像できない → **対応** (観測値が引数に無いのは設計の穴)。
  `bool $ffprobeFailed` を引数へ追加。`artifact` の 2 分岐を確定:
  出力が読み出せない = `Storage` / 読めたが ffprobe 失敗 = `Render` (合成物が壊れている)。
- [Warning] 「成功した段は分類しない」を classifier テストで証明できない → **対応**。
  Codex が示した 3 案のうち **1 案目**を採用: `bool $stageSucceeded` を入力に加え、
  戻り値を **`?SmokeFailureClass`** にした (成功時 `null`)。
  呼び出し側の契約が型に載るため classifier 単体テストで直接固定できる。

判定順 (確定):
1. $stageSucceeded → null
2. stage=Preflight → Preflight
3. timedOut ∧ queued → Wiring
4. timedOut ∧ running → StageTimeout
5. stage=Render ∧ hasRenderErrorCode → Render
6. stage=Artifact ∧ ¬outputReadable → Storage
7. stage=Artifact ∧ ffprobeFailed → Render
8. stage ∈ {Analysis, LlmEvidence} ∧ (hasLlmFailureRow ∨ ¬hasLlmSuccessRow) → Llm
9. それ以外 → Unknown

`SmokeStage` の case は Preflight / Fixture / Analysis / LlmEvidence / Capture / Render / Artifact の 7 つ。

### 施策 9
- [Warning] 回帰ケース追加 → **対応**。判定表を 12 行へ拡張した。
  Codex が挙げた 5 件をすべて含む:
  - fixture/capture failure + LLM 成功行なし → `Unknown` (ケース 9)
  - LLM retry failure 行あり + capture failure → `Unknown` (ケース 10)
  - artifact + output readable + ffprobe failure → `Render` (ケース 6)
  - artifact + output unreadable → `Storage` (ケース 5)
  - LLM retry 後の段成功 → `null` (ケース 12。`?SmokeFailureClass` にしたため
    **classifier 単体テストで**固定できる。呼び出し側テストへ逃がす必要が無くなった)

### 施策 8
- `Llm` / `Render` の意味は分類表どおり維持する (docs の失敗語彙表も同じ定義で書く)。

## 変更後の詳細設計書 (該当部分のみ抜粋)

### 分類器 (`App\Support\Smoke\SmokeFailureClassifier`)

`GatewayFailureClassifier` と同じ流儀の**純関数**として切り出す
(private メソッドに埋めない = 判定表を Unit テストで直接固定できるようにする)。

`SmokeStage` の case は `Preflight` / `Fixture` / `Analysis` / `LlmEvidence` / `Capture` /
`Render` / `Artifact` の 7 つ。

```php
final readonly class SmokeFailureClassifier
{
    /** LLM が原因になり得る段 (Llm 分類の適用範囲を**この集合に閉じる**) */
    private const array LLM_ATTRIBUTABLE_STAGES = [SmokeStage::Analysis, SmokeStage::LlmEvidence];

    /**
     * 失敗の観測分類。**成功した段では null を返す** (呼び出し側が分類を付けないことを
     * 型で表現する = 「成功した段に failure class を付けない」を機械で固定できる)。
     *
     * @param  bool            $stageSucceeded     段が成功したか
     * @param  JobStatus|null  $jobStatus          観測したジョブ状態 (段によっては null)
     * @param  bool            $timedOut           待機上限に到達したか
     * @param  bool            $hasLlmFailureRow   この実行分に failure_reason 行があるか
     * @param  bool            $hasLlmSuccessRow   この実行分に成功行があるか
     * @param  bool            $hasRenderErrorCode render_jobs.error_code が非 null か
     * @param  bool            $outputReadable     出力オブジェクトを読み出せたか
     * @param  bool            $ffprobeFailed      ffprobe が非 0 終了したか
     */
    public static function classify(
        SmokeStage $stage,
        bool $stageSucceeded,
        ?JobStatus $jobStatus,
        bool $timedOut,
        bool $hasLlmFailureRow,
        bool $hasLlmSuccessRow,
        bool $hasRenderErrorCode,
        bool $outputReadable,
        bool $ffprobeFailed,
    ): ?SmokeFailureClass;
}
```

判定順 (先に一致したものを返す。**分類は制御フローを変えない**):

| # | 条件 | 返り値 |
|---|---|---|
| 1 | `$stageSucceeded` | **`null`** (分類しない) |
| 2 | `$stage === Preflight` | `Preflight` |
| 3 | `$timedOut && $jobStatus === Queued` | `Wiring` |
| 4 | `$timedOut && $jobStatus === Running` | `StageTimeout` |
| 5 | `$stage === Render && $hasRenderErrorCode` | `Render` |
| 6 | `$stage === Artifact && ! $outputReadable` | `Storage` |
| 7 | `$stage === Artifact && $ffprobeFailed` | `Render` |
| 8 | `in_array($stage, LLM_ATTRIBUTABLE_STAGES, true) && ($hasLlmFailureRow \|\| ! $hasLlmSuccessRow)` | `Llm` |
| 9 | それ以外 | `Unknown` |

**境界の意図 (誤分類を防ぐために明示する)**:

- **`Llm` は LLM が原因になり得る段に閉じる**。閉じないと
  「`capture` が失敗したが LLM 成功行がまだ無い」「LLM がリトライ成功した後に `capture` が失敗した」が
  すべて `Llm` に誤分類される (段の切り分けという目的そのものを壊す)
- **`artifact` の 2 分岐**: 出力が**読み出せない** = `Storage`、
  読み出せたが **ffprobe が失敗** = `Render` (= 合成物が壊れている)

### 出力

### 失敗分類 (SmokeFailureClass) の表
### 失敗分類 (`SmokeFailureClass`)

| case | 判定 |
|---|---|
| `Preflight` | preflight で落ちた (LLM を 1 回も呼んでいない) |
| `Wiring` | ジョブが **`queued` のまま**上限到達 (worker 不在 / connection 取り違え / dispatch 喪失) |
| `StageTimeout` | ジョブが **`running` のまま**上限到達 (worker は取得したが上限内に完了しなかった) |
| `Llm` | **`analysis` / `llm-evidence` 段が失敗している**うえで、この実行分の `llm_call_logs` に `failure_reason` 行がある、または成功行が 1 行も無い (**他の段には適用しない**) |
| `Render` | `render` 段で `render_jobs.error_code` が非 null、または `artifact` 段で出力は読めたが ffprobe が非 0 終了 |
| `Storage` | `artifact` 段で出力オブジェクトが不在 / 読み出し不能 |
| `Unknown` | 写像表に一致が無かった (**写像表の値としては使わない**) |

- 分類は**観測のためであり制御フローを変えない** (ドメイン規約 7 と同じ流儀)。
  `Unknown` は「写像表に一致が無かった」ことを意味する
- ★ **`failure_reason` 行の存在だけで `Llm` にしない**。`AnalysisPipeline::withBoundedRetry` は
  transient 失敗を最大 3 試行まで再試行するため、**リトライして最終的に成功した実行にも
  `failure_reason` 行は残る**。分類は「段が失敗したとき」にだけ行い、成功した段は分類しない
  (成功時にリトライがあったことは診断行に `llm_retry_rows=N` として**情報として**出す)

### 分類器 (`App\Support\Smoke\SmokeFailureClassifier`)

### 施策 9 の分類器テスト判定表
**代わりに固定するもの**: 分類ロジックは `App\Support\Smoke\SmokeFailureClassifier` として
**public static な純関数へ切り出す** (private メソッドに埋めないので Pest から直接呼べる)。
`tests/Unit/Support/Smoke/SmokeFailureClassifierTest.php` が次の判定表を機械的に固定する
(DB 不要):

| # | 入力 | 期待 |
|---|---|---|
| 1 | stage = preflight | `Preflight` |
| 2 | timedOut ∧ jobStatus = queued | `Wiring` |
| 3 | timedOut ∧ jobStatus = running | `StageTimeout` |
| 4 | stage = render ∧ hasRenderErrorCode | `Render` |
| 5 | stage = artifact ∧ ¬outputReadable | `Storage` |
| 6 | stage = artifact ∧ outputReadable ∧ ffprobeFailed | `Render` |
| 7 | stage = analysis ∧ failed ∧ hasLlmFailureRow | `Llm` |
| 8 | stage = llm-evidence ∧ ¬hasLlmSuccessRow | `Llm` |
| 9 | **stage = fixture / capture の失敗 ∧ ¬hasLlmSuccessRow** | **`Unknown`** (`Llm` に漏らさない) |
| 10 | **stage = capture の失敗 ∧ hasLlmFailureRow (リトライ痕)** | **`Unknown`** (同上) |
| 11 | 上記いずれにも一致しない失敗 | `Unknown` |
| 12 | **`$stageSucceeded = true` (リトライの failure 行があっても最終成功)** | **`null`** |

ケース 9・10 は Round 3 で指摘された誤分類 (`Llm` が全段へ漏れる) の**負のコントロール**である。
ケース 12 は「成功した段に failure class を付けない」を**戻り値の型で**固定する
(`classify()` が `?SmokeFailureClass` を返す設計にしたため、classifier 単体テストで証明できる)。

---
