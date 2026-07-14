Round 1 の Critical 1 点＋Warning 3 点＋Suggestion に対応しました。再評価をお願いします（使命・禁止事項の再掲不要）。

## 対応（要約）

- [Critical] 施策3/F（MAX_STEPS 境界）: **対応**。定数リネームせず意味を再定義して固定。`ScenarioLimits::MAX_STEPS` の doc コメントに「LLM 生成/手動編集 step の上限。導入/総括の定型 2 カットは別枠、materialized トップレベル総数は最大 MAX_STEPS+2(=102)」を明記（施策1 に ScenarioLimits コメント追記を追加）。施策6 に境界テスト（生成 step=100 → 102 materialize、切り捨て/reject なし）を追加。生成 step を削る案は実手順欠落＝使命違反のため不採用。

- [Warning] 施策2/5（line() の静かな見逃し）: **対応**。`line()` を `Lang::has()` チェック→未定義キーは `LogicException`（fail-fast）に変更。`Assert::string()` で型を string に閉じる。施策5 に「全利用 lang キー存在テスト」を追加。

- [Warning] 施策3（trim が全角空白を落とせない）: **対応**。`normalize()` を新設（`preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $v)`）。recap 抽出の空判定を normalize 経由に統一。施策5/6 に「全角空白のみ subtitle_primary は再掲元に採らない」テストを追加。

- [Warning] 施策7/E（件数のみ更新は退行見逃し）: **対応**。既存テスト（成功パス L139-142・再解析 L349-359・CannedAnalysisPipelineTest）の件数を 2→4 に更新する際、位置・型・親子の構造アサートを追加（先頭/末尾 top-level=Hiki・parent_cut_id=null、生成 point は中間 step 配下）。

- [Suggestion]: **対応**。config コメント「0 以下は 1 扱い」追記。施策6 に「再生成の再掲元が今回生成のみ（1回目/2回目で別 point 文言→2回目由来のみ）」テスト追加。施策5 に `summary_recap_max_points=0/-1` 防御テスト追加。DI 解決は施策6 完走テストで担保。

## 変更後の該当コード/テスト計画（抜粋）

### ScenarioBookendBuilder（normalize / line 改訂）
```php
use Illuminate\Support\Facades\Lang;
use LogicException;
use Webmozart\Assert\Assert;

private function recapLine(array $generatedSteps): ?string
{
    $candidates = [];
    foreach ($generatedSteps as $step) {
        foreach ($step->points as $point) {
            $v = $this->normalize($point->subtitlePrimary);
            if ($v !== '') { $candidates[] = $v; }
        }
    }
    if ($candidates === []) {
        foreach ($generatedSteps as $step) {
            $v = $this->normalize($step->subtitlePrimary);
            if ($v !== '') { $candidates[] = $v; }
        }
    }
    if ($candidates === []) { return null; }

    $max = config()->integer('manual.summary_recap_max_points');
    $picked = array_slice($candidates, 0, max(1, $max));
    while (count($picked) > 1 && mb_strlen(implode('／', $picked)) > ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS) {
        array_pop($picked);
    }
    return implode('／', $picked); // 1 件超過は summary() の clamp が文字 truncate
}

private function normalize(?string $value): string
{
    if ($value === null) { return ''; }
    return (string) preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
}

private function line(string $key, array $replace = []): string
{
    if (! Lang::has($key)) {
        throw new LogicException("シナリオ導入/総括の lang キーが未定義: {$key}");
    }
    $value = trans($key, $replace);
    Assert::string($value);
    return $value;
}
```

### 施策7 既存テスト更新（構造アサート）
```php
$topLevel = $cuts->where('parent_cut_id', null)->values();
expect($topLevel)->toHaveCount(3);
expect($topLevel->first()->parent_cut_id)->toBeNull();
expect($topLevel->first()->shot_type)->toBe(ShotType::Hiki);
expect($topLevel->last()->parent_cut_id)->toBeNull();
expect($topLevel->last()->shot_type)->toBe(ShotType::Hiki);
$generatedStep = $topLevel->get(1);
$point = $cuts->firstWhere('type', CutType::Point);
expect($point->parent_cut_id)->toBe($generatedStep->id);
```

これで Round 1 の全指摘に対応したと考えます。APPROVED 可否をお願いします。
</content>
