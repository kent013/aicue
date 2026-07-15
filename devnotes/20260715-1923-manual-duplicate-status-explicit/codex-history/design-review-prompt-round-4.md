Round 3 の PHPStan L10 指摘に対応しました。再レビューし全体判定を返してください。

## [施策3-b Warning] getFileName/getStartLine/getEndLine の型 (int|string|false) narrowing
対応: Webmozart\Assert で明示 guard してから array_slice。cast を除去。

```php
$method = new ReflectionMethod(VideoManualService::class, 'duplicate');
$fileName = $method->getFileName();
$startLine = $method->getStartLine();
$endLine = $method->getEndLine();
Assert::string($fileName);
Assert::integer($startLine);
Assert::integer($endLine);
$lines = file($fileName);
Assert::isArray($lines);
$body = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
expect($body)->toContain("'status' => VideoManualStatus::Draft");
expect($body)->toContain("'scenario_version' => 0");
```
test ファイルに `use Webmozart\Assert\Assert;` を追加。

これで PHPStan L10 も通ります。APPROVED 可否を判定してください。
