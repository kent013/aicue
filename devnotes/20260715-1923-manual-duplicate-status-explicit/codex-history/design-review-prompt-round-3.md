Round 2 の施策3 Warning に対応しました。再レビューし全体判定を返してください。

## [施策3 Warning] fail-first でない / 明示代入削除を検出できない → 対応
`duplicate()` のメソッドソースを ReflectionMethod で取得し、status/scenario_version の明示代入の存在を機械的に要求する契約テスト (3-b) を追加しました。実装前は fail (fail-first)、明示代入を消すと fail します。

```php
test('duplicate() は複製 manual の status/scenario_version を明示代入する (DB default 非依存の契約)', function (): void {
    $method = new ReflectionMethod(VideoManualService::class, 'duplicate');
    $fileName = $method->getFileName();
    expect($fileName)->not->toBeFalse();
    $lines = file((string) $fileName);
    expect($lines)->not->toBeFalse();
    $body = implode('', array_slice(
        (array) $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));
    expect($body)->toContain("'status' => VideoManualStatus::Draft");
    expect($body)->toContain("'scenario_version' => 0");
});
```

振る舞いテスト (3-a: Draft/0 + 元 manual 不変 + created_by) は回帰ガードとして併存。実装順序は 3-b の fail を確認 → 施策1 実装 → 全 green。

これで残件は解消と考えます。APPROVED 可否を判定してください。
