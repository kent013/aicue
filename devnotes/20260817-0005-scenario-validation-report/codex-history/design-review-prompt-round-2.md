# Round 2: Round 1 指摘への対応 (Warning 6 / Suggestion 7)

Warning 6 件と Suggestion 6 件を**すべて対応**しました。Suggestion 1 件のみ根拠付きで見送ります。

| # | 指摘 (施策) | 判断 | 対応 |
|---|---|---|---|
| W1 | M2 `fromStorage(?array)` が scalar 保存値で TypeError | 対応 | `mixed` 受けへ変更 |
| S1 | M2 `tryFrom` の結果を変数保持 | 対応 | `from()` の二度引きを削除 |
| S2 | M3 migration の日付 | 対応 | `{実装日}` 表記 + 採番規則を明記 |
| S3 | M4 `steps.*` 側と識別できるテスト | 対応 | テスト計画に追加 |
| W2 | M5 `rtrim` のマルチバイト破壊 | 対応 | `preg_replace(/u)` へ |
| W3 | M5 孤児 cut / 数え方が未定義 | 対応 | 「数え方と異常データの扱い」節を追加 |
| S4 | M6 source document が追記型である前提を docs に | 対応 | 実装を確認して前提を明記 |
| W4 | M7 `formatPositions` に count が渡らない | 対応 | 第 2 引数 count を追加 |
| W5 | M7 types が `BadgeTone` に依存 | 対応 | features 層の helper へ分離 |
| W6 | 全体: 検証コマンドが AGENTS.md 全量と不一致 | 対応 | `*:packages` 3 本を追加 |
| S5 | M1/M9 「制御フローに使わない」を YAML と docs で同表現に | 対応 | 追記 |
| S6 | M7 Button に Lucide アイコン | **見送る** | 下記 §見送り |

## W1: `fromStorage` を mixed 受けに

```php
/**
 * ★ 引数は `mixed` である。JSON カラムは cast の結果が array とは限らず
 *   (scalar / string が入っていれば `?array` 型宣言は TypeError で詳細画面を落とす)、
 *   「壊れていても画面を落とさない」という本メソッドの目的と矛盾するため。
 *   null は正常 (未生成)、array 以外は復元失敗として扱う。
 */
public static function fromStorage(mixed $stored, int $analysisJobId): ?self
{
    if ($stored === null) {
        return null;
    }
    try {
        if (! is_array($stored)) {
            throw LlmJson::schemaViolation('validation_json が object ではありません', 'validation');
        }

        return self::parse($stored);
    } catch (LlmOutputInvalidException $exception) {
        Log::warning('解析ジョブの妥当性所見の復元に失敗しました', [
            'analysis_job_id' => $analysisJobId,
            'failure_category' => $exception->reason->value,
            'failure_path' => $exception->path,
        ]);

        return null;
    }
}
```

## S1: verdict の二度引き解消

```php
$rawVerdict = $raw['verdict'] ?? null;
// tryFrom の結果を変数で保持する (from() で二度引かない)
$verdict = is_string($rawVerdict) ? ScenarioVerdict::tryFrom($rawVerdict) : null;
if ($verdict === null) {
    throw LlmJson::schemaViolation('validation.verdict は valid / needs_review / invalid のいずれかでなければなりません', 'validation.verdict');
}
// ...
return new self($verdict, $reason, $works, $split);
```

## W2: 末尾記号の除去を Unicode 対応の正規表現へ

```php
/**
 * ★ `rtrim($s, "。.!！")` は使えない。`rtrim` の charlist は**バイト単位**で解釈されるため、
 *   マルチバイト文字を渡すとその構成バイトが個別に剥がされ、UTF-8 文字列を壊しうる。
 */
private const string TRAILING_MARKS_PATTERN = '/[\s。．.!！]+$/u';

private static function endsPolitely(string $narration): bool
{
    $trimmed = preg_replace(self::TRAILING_MARKS_PATTERN, '', $narration) ?? $narration;
    foreach (self::POLITE_ENDINGS as $ending) {
        if (str_ends_with($trimmed, $ending)) {
            return true;
        }
    }

    return false;
}
```

## W3: 数え方と異常データの扱い (M5 に節を追加)

```
- stepCount = parent_cut_id === null の cut 数 (ScenarioBookendBuilder が付ける導入/総括カットも
  識別子が無いのでここに含まれる)。
- pointCount = 親をこの cut 集合の中で解決できた子 cut の数。
- 孤児 cut (parent_cut_id が非 null だが集合内に親がいない) は DB 制約上発生しないが、
  防御的に pointCount にも規約検査にも含めない (「手順 N-M」で位置を表記できない =
  表示できない指摘を出さない)。Unit テストで 1 ケース固定する。
- 走査順は取得側が orderBy('sort_order')->orderBy('id') で決める (CutSequencer と同じ並び)。
  同値 sort_order で位置表記が揺れないようにするため、ScenarioDocumentData::fromManual の
  orderBy('sort_order') のみとはあえて揃えない。
```

テスト計画にも「導入/総括カットが stepCount に含まれる」「孤児 cut は数えない」を追加。

## S4: 鮮度を id で見る前提 (実装を確認)

`SourceDocumentService::appendDocument()` は毎回新しい行を INSERT し、`file_path` を上書き更新する
経路は存在しない (差し替え = 新しい行)。解析対象は `AnalysisJobService::trigger()` が行ロック下で
`latest('id')` を選ぶ。よって id 比較で正しい。この前提を `ScenarioReportBuilder` の注釈と
docs (M9) の両方に明記し、「in-place 更新の経路を作るときは比較方法を見直す」と書き添えた。

## W4/W5: UI helper の分離と formatPositions

`types/manual.ts` には union 2 つと props 型のみを残し、表示語彙は新規
`resources/js/components/features/manual/scenario-report.ts` に移した
(同階層の `insufficient-tickets.ts` が先例)。

```ts
import type { BadgeTone } from "@/components/atoms/Badge.types";
import type { ScenarioRuleCode, ScenarioVerdict } from "@/types/manual";

export const SCENARIO_VERDICT_LABELS = { ... } as const satisfies Record<ScenarioVerdict, string>;
export const SCENARIO_VERDICT_TONES = { ... } as const satisfies Record<ScenarioVerdict, BadgeTone>;
export const SCENARIO_RULE_LABELS = { ... } as const satisfies Record<ScenarioRuleCode, string>;

/**
 * 位置の整形。「手順 2」/「急所 2-3」。
 * count は positions.length と別に受け取る — positions は先頭 5 件で打ち切られており、
 * 「ほか」を出すかは総件数でしか判定できないため。
 */
export function formatPositions(
    positions: { step: number; point: number | null }[],
    count: number,
): string {
    const labels = positions.map((p) => (p.point === null ? `手順 ${p.step}` : `急所 ${p.step}-${p.point}`));

    return count > positions.length ? `${labels.join(" / ")} ほか` : labels.join(" / ");
}
```

理由も設計書に明記しました:「`BadgeTone` は atom の型であり、ドメイン型定義ファイルが UI atom に
依存すると責務が混ざる。既存の `STATUS_TONES` が types 側にあるのは先行実装の事情で、今回それを増やさない」。

## W6: 検証コマンド

```
composer test / composer phpstan / vendor/bin/pint --test /
pnpm lint / pnpm typecheck / pnpm test / pnpm build /
pnpm typecheck:packages / pnpm build:packages / pnpm test:packages
末尾 3 本 (*:packages) は本変更が触らない packages/ 配下が対象だが、規約上は実行対象なので省略しない。
```

## §見送り (S6): Button に Lucide アイコンを足さない

既存 `AnalysisPanel` / `RenderPanel` の副次導線も `variant="ghost"` のテキストボタンで、
アイコンは主要 CTA (AI 解析 = Sparkles / 撮影 = Camera) に限られています。
ここでアイコンを足すと主要 CTA との視覚的な優劣が崩れるため、既存の使い分けに合わせて
テキストボタンのままにします (DS token 準拠は維持)。

以上を踏まえて再判定をお願いします。
