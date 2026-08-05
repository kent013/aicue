# Round 2: Round 1 指摘への対応確認

Round 1 は APPROVED (Critical 0 / Warning 0 / Suggestion 3) でした。
Suggestion のうち 1 件のみ採用し、残り 2 件は見送りました。対応後の差分を確認してください。

## 対応マトリクス

### [Suggestion] Tier A / Tier B 分離と cap-defense-ok の bypass 不能性
- 判断: 対応不要 (確認依頼への肯定回答として受領)

### [Suggestion] 日本語表記 `cap は 8` / `N は 8` / `--parallel 8` も Tier A に含める余地がある
- 判断: **対応する**
- 対応内容: 区切りを `(?:\s*(?:=|は|:)\s*|\s+)` に共通化して 4 パターンへ適用。
  負のコントロールに `--parallel 8` / `N は 8` / `cap は 8` を追加。

### [Suggestion] SHARD_RE / SHARD_DB_RE の構造検査が最初の代入行しか見ていない
- 判断: 見送る
- 根拠: 行頭アンカー `/^SHARD_DB_RE=/m` はトップレベル代入のみを対象とする設計。
  唯一の再代入は `cmd_self_test` 内 sandbox 初期化 (インデント済み = 非一致) で、
  施策 2-0 として意図的に入れたもの。cap から同じ式で導出している。
  任意箇所の再代入追跡には bash のスコープ解析が要りコストが釣り合わない。
  実行時の allowlist 実効値は self-test [c] (`bug_hunt_5` / `bug_hunt_8` が abort) が直接固定する。

## 追加差分 (tests/Architecture/BughuntShardCapInvariantTest.php のみ)

```php

```

```php
test('負のコントロール: Tier A 割り当て値 (--parallel=8 / parallel は 2/4/6/8 / N=8 / cap=8 / shard 1..8) を検出すること', function (): void {
    $lines = [
        '--parallel=8', '--parallel は 2/4/6/8', '--parallel 8 で走らせる',
        'N=8 で走らせる', 'N は 8 とする', 'cap=8 とする', 'cap は 8 である', 'shard 1..8 に配る',
    ];
    foreach ($lines as $line) {
        $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
        expect(implode("\n", $violations))->toContain('[Tier A]');
    }

    expect(bughuntCapAllocationValues('--parallel は 2/4/6/8'))->toBe([2, 4, 6, 8]);
    expect(bughuntCapAllocationValues('cap=8'))->toBe([8]);
});

```

## 再検証結果

- `composer test`: 3156 tests / 3154 passed / 0 failed / 2 skipped, 12285 assertions
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- 割り当て散文 10 ファイルに対する Tier A/B 走査: 違反 0 件 (偽陽性なし)
- `scripts/bug-hunt-shard.sh self-test`: all passed

上記対応で問題ないか、全体判定 (APPROVED / CHANGES_REQUESTED) を返してください。
