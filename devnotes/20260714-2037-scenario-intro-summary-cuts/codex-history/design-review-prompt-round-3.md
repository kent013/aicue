Round 2 の Critical 1 点＋Warning 1 点＋Suggestion 2 点に対応しました。再評価をお願いします。

## 対応（要約）

- [Critical] 102 件 materialize の手動保存可能性: **対応**。原因は `UpdateScenarioRequest` L81 `steps => max:MAX_STEPS(100)`。`ScenarioLimits::MAX_TOP_LEVEL_CUTS = MAX_STEPS + 2`(=102) を追加し、**施策4.5** で save 側 steps 上限を `MAX_TOP_LEVEL_CUTS` に整合。`MAX_STEPS`(生成 DTO 上限)は据え置き。施策6 に「102 件 round-trip 保存（200・順序維持・version+1）」正常系、施策7 に `ScenarioUpdateTest` 上限超過境界 101→103 更新を追加。

- [Warning] 長さ判定が lang 接頭辞を含まない: **対応**。`summarySecondary()` で接頭辞込みの**完成文**（`subtitle_secondary_recap` の render）を基準に件数削減し、1 件でも超過なら完成文を文字 truncate。候補抽出は `recapCandidates(): list<string>` に純粋分離。施策5 の長さテストを (a) 複数→件数削減 / (b) 1 件→完成文 truncate に分離、完成文常時 ≤2000 を検証。

- [Suggestion] preg_replace の失敗: **対応**。`normalize()` の結果を `Assert::string()` で閉じ、`(string)` キャストで握りつぶさない。`truncatedTitle` も normalize 経由に統一。

- [Suggestion] テストの null 型: **対応**。施策7 に `firstWhere`/`get(1)` 結果を `Assert::isInstanceOf(Cut::class)` で閉じる旨明記。

## 変更後コード（施策3 の総括組み立て・normalize）

```php
private function summarySecondary(string $title, array $generatedSteps): string
{
    $candidates = $this->recapCandidates($generatedSteps);
    if ($candidates === []) {
        return $this->clamp(
            $this->line('manual.bookend.summary.subtitle_secondary_fallback', ['title' => $title]),
            ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS,
        );
    }
    $n = max(1, config()->integer('manual.summary_recap_max_points'));
    $picked = array_slice($candidates, 0, $n);
    $render = fn (array $items): string => $this->line(
        'manual.bookend.summary.subtitle_secondary_recap',
        ['points' => implode('／', $items)],
    );
    while (count($picked) > 1 && mb_strlen($render($picked)) > ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS) {
        array_pop($picked);
    }
    return $this->clamp($render($picked), ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
}

/** @param list<ScenarioStepInput> $generatedSteps @return list<string> */
private function recapCandidates(array $generatedSteps): array
{
    $candidates = [];
    foreach ($generatedSteps as $step) {
        foreach ($step->points as $point) {
            $v = $this->normalize($point->subtitlePrimary);
            if ($v !== '') { $candidates[] = $v; }
        }
    }
    if ($candidates !== []) { return $candidates; }
    foreach ($generatedSteps as $step) {
        $v = $this->normalize($step->subtitlePrimary);
        if ($v !== '') { $candidates[] = $v; }
    }
    return $candidates;
}

private function normalize(?string $value): string
{
    if ($value === null) { return ''; }
    $result = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
    Assert::string($result);
    return $result;
}
```

## 施策4.5（UpdateScenarioRequest）
```php
'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_TOP_LEVEL_CUTS], // 導入/総括込み 102 を許容
```
`ScenarioLimits`:
```php
public const int MAX_STEPS = 100;                      // 生成/編集の「手順」上限 (据え置き)
public const int MAX_TOP_LEVEL_CUTS = self::MAX_STEPS + 2; // 導入/総括込みの top-level 総数上限
```

これで Round 2 の全指摘に対応したと考えます。APPROVED 可否をお願いします。特に施策4.5 の整合（102 件保存の正常系）と、完成文ベースの長さ判定に見落としがないかをご確認ください。
</content>
