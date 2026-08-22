Round 1のCritical 2件は適切に解消されています。文字列FQCN・非PHP参照、mixed group use、宣言深さ、nullable FQCN、分岐別自己検証、D40はいずれも改善を確認できました。

ただし、新たに1件の静的検出漏れがあり、全体テストも未完了です。

## 指摘

### [Warning] 参照返しメソッドの宣言を検出できない

対象:

- [RemovedSurfaceScanner.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php)
- [OcrFeatureFlagAbsenceGateTest.php](/workspace/.claude/worktrees/tasks/T250/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php)

メソッド名の位置を次の条件で決めています。

```php
$nameIndex = self::isChar($tokens, $i + 1, '&') ? $i + 2 : $i + 1;
```

PHP 8では`function &imagesEnabled()`の`&`が、単なる文字トークンではなく`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`になる場合があります。`isChar()`は`id === null`だけを認めるため、次の合法な宣言を見逃します。

```php
final class AcceptedSourceDocumentTypes
{
    public static function &imagesEnabled(): bool
    {
        // ...
    }
}
```

実行時層の`method_exists()`は検出するため保護全体が失われるわけではありませんが、`scanMethodReferences()`が主張する「対象クラスのメソッド宣言」検出としてはfail-openです。

`&`の文字トークンに加え、少なくとも以下のトークンIDを認識し、正例を追加してください。

- `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`
- 必要なら`T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG`も保守的に扱う

fail-firstとして、深さ検査と同様に参照返し対応を外すと正例が赤くなることを固定するのが適切です。

### [Warning] 全体検証の完了条件がまだ満たされていない

提示時点では以下が再取得中です。

- `composer test`
- `pnpm test`
- `pnpm test:packages`

触ったgateの55テスト、PHPStan、Pintがgreenなのは十分な局所証拠ですが、AGENTS.mdの完了条件は全検証レーンのgreenです。結果が確定するまでは承認できません。

### [Suggestion] broken symlinkは`population()`で共通関数を通っていない

対象:

- [RemovedSurfaceScanTargets.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php)
- [PasswordConfirmSurfaceAbsenceGateTest.php](/workspace/.claude/worktrees/tasks/T250/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php)

`population()`は先に`is_file()`を判定するため、broken symlinkでは次の共通関数に到達しません。

```php
if (! is_file($absolute)) {
    // ...
    continue;
}

$symlinkReason = self::symlinkUnresolvedReason(...);
```

結果自体は`unresolved`になるためfail-openではありません。ただし、「populationも自己検証も必ず`symlinkUnresolvedReason()`を通る」「broken symlink分岐を同じ経路で固定した」という説明とは一致していません。

説明を「外向き・解決可能なsymlinkは共通関数を通り、broken symlinkは通常ファイル判定でもfail-closedになる」と訂正するか、symlink判定を`is_file()`より前へ移すと整合します。

## Round 1対応の判定

- 文字列FQCNと非PHP FQCN — 解消
- mixed group useの`function`/`const` — 解消
- ネスト関数・匿名クラスの誤検出 — 解消
- nullable `resolvedFqcn`の黙殺 — 解消
- M1/M3、parent、trait、複数namespaceの裏取り — 解消
- D40の対象パスと保証範囲 — 解消
- `MiddlewareReference`を2型へ分割しなかった判断 — 妥当。現在のgate側fail-closed処理で必要な保証を満たしており、型の追加は必須ではない
- 全体テストgreen — 未完了

## ファイル別判定

- `docs/template-divergence.md` — 問題なし。16パスの列挙と保証範囲の限定は適切。
- `OcrFeatureFlagAbsenceGateTest.php` — Warning。参照返しメソッド宣言の正例が不足。
- `PasswordConfirmSurfaceAbsenceGateTest.php` — 問題なし。D2b/D4、nullable処理、追加の正負例は適切。
- `PhpNameResolver.php` — 問題なし。mixed group use修正と深さ索引は目的に一致。
- `RemovedSurfaceScanner.php` — Warning。参照返しメソッド宣言を見逃す。
- `RemovedSurfaceScanTargets.php` — Suggestion。broken symlinkについて説明と実経路が一致しないが、fail-closedは維持されている。
- `TermMatchMode.php` — 問題なし。`FqcnReference`の区切り、一致、大小比較の宣言は明確。
- `MiddlewareReference.php` — 問題なし。2型へ分割しなくても利用側でnullを未解決にしている。
- その他のSurfaceRemoval値オブジェクト — 問題なし。
- 追加fixture群 — 提示内容は適切。ただし参照返しメソッド宣言の正例を1本追加する必要あり。
- Svelteコメント2ファイル — Round 1から判断変更なし。DESIGN.md・Atomic Designへの影響なし。
- PHPStan — 提示結果上、level 10適合。widen、baseline、ignoreなし。

CHANGES_REQUESTED