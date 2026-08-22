結論として、実際に保護対象を再導入しても gate が緑になる経路が2系統あります。意図的逸脱1そのものは、保証範囲を明示的に狭めているため直ちに規約違反とは判断しませんが、D40の保証表現とは整合していません。

## 指摘

### [Critical] PHP内の文字列FQCNと非PHPの`RequirePassword`参照を見逃す

対象:

- [RemovedSurfaceScanner.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php)
- [PasswordConfirmSurfaceAbsenceGateTest.php](/workspace/.claude/worktrees/tasks/T250/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php)
- [RemovedSurfaceScanTargets.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php)

`scanMiddlewarePositions()`は文字列リテラルをすべて`AliasString`として返しますが、gate側は`password.confirm`だけで絞っています。そのためLaravelが受理する次のクラス文字列はD1にもD2にも該当しません。

```php
$route->middleware('Illuminate\Auth\Middleware\RequirePassword');
```

また、拡張子なしPHPスクリプトは`isPhp=false`になる一方、Tier 2で探すのは`password.confirm`だけです。したがって次も通過します。

```php
#!/usr/bin/env php
<?php
$route->middleware(\Illuminate\Auth\Middleware\RequirePassword::class);
```

同様に、workflowやシェル内の`php -r`に書かれたFQCNも見逃します。`.github`と拡張子なしスクリプトを必須母集団にした目的と矛盾し、I2の「production surfaceへの参照再流入を止める」を満たしません。

少なくとも以下の正例が必要です。

- PHP middleware位置の文字列FQCN
- 拡張子なしPHPの`RequirePassword::class`
- shell/YAML中のFQCN参照
- ASCII大小違い、別namespace、接尾辞付きの境界例

### [Critical] mixed group useの`function`/`const`をクラスimportとして登録し、対象クラス参照を見逃す

対象:

- [PhpNameResolver.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/PhpNameResolver.php)

`parseImport()`のgroup use処理は`T_FUNCTION`/`T_CONST`を見つけるとトークンを1つ進めるだけです。次の反復で、その後の名前を通常のクラスimportとして登録しています。

そのため、例えば次はPHP上では対象クラスへの静的呼び出しですが、resolverは`App\Other\AcceptedSourceDocumentTypes`へ誤解決し、OCR gateが見逃します。

```php
namespace App\Support\Manual;

use App\Other\{function AcceptedSourceDocumentTypes};

AcceptedSourceDocumentTypes::imagesEnabled();
```

PHPでは関数・定数とクラスのimport空間は別です。docblockの「`use function` / `use const`は取り込み表に入れない」という保証にも実装が一致していません。

group useの各要素について種別を保持し、`function`/`const`要素全体を登録対象外にする必要があります。通常形式とgroup形式の両方について、関数・定数importが同名クラス解決へ影響しない正例が必要です。

### [Warning] `MethodReference`の宣言判定に誤検出がある

対象:

- [RemovedSurfaceScanner.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php)

`T_FUNCTION`が対象クラスの`TypeSegment`内にあることだけでメソッド宣言としています。クラスメソッド内で宣言された名前付き関数も対象クラスのメソッドと誤認します。

```php
class AcceptedSourceDocumentTypes
{
    public function register(): void
    {
        function imagesEnabled(): bool
        {
            return true;
        }
    }
}
```

これはクラスメソッドではありません。宣言位置がクラス本体の直下かを確認する必要があります。匿名クラスを追跡しないため、対象クラス内に置かれた匿名クラスのメソッドにも同種の誤検出余地があります。

### [Warning] `ClassReference`の「解決済み」不変条件が型で守られていない

対象:

- [MiddlewareReference.php](/workspace/.claude/worktrees/tasks/T250/tests/Support/SurfaceRemoval/MiddlewareReference.php)
- [PasswordConfirmSurfaceAbsenceGateTest.php](/workspace/.claude/worktrees/tasks/T250/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php)

`ClassReference`では`resolvedFqcn`が必ず非nullというコメントですが、型は`?string`で、gateは次のキャストによりnullを空文字へ変えて黙って無視します。

```php
strtolower((string) $reference->resolvedFqcn)
```

今のscannerはnullを生成していませんが、将来の退行が「未解決」ではなく「非該当」になるfail-open構造です。少なくとも`ClassReference && resolvedFqcn === null`を未解決として落とすか、型を種別ごとに分けるべきです。

### [Warning] 名前解決とmiddleware位置の自己検証が宣言した分岐を覆っていない

対象:

- [OcrFeatureFlagAbsenceGateTest.php](/workspace/.claude/worktrees/tasks/T250/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php)
- [PasswordConfirmSurfaceAbsenceGateTest.php](/workspace/.claude/worktrees/tasks/T250/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php)

未検証の主な分岐があります。

- `use function` / `use const`とmixed group use
- `parent`の解決
- trait内の`static` / `parent`（テスト名とdocblockは三種を主張するが見本は`self`のみ）
- 1ファイル内の複数namespace
- M1の`withoutMiddleware` / `middlewareGroup` / `appendToGroup` / `prependToGroup`
- M3の`$middleware` / `$middlewarePriority`
- `realpath() === false`になるsymlinkが母集団の`unresolved`へ入る統合経路

今回のmixed group useの欠陥がそのまま通っているため、AGENTS.md (c) の検出力裏取りとして不足しています。

### [Warning] D40の対象パスと保証文が実態に一致しない

対象:

- [template-divergence.md](/workspace/.claude/worktrees/tasks/T250/docs/template-divergence.md)

TD3がディレクトリを許可しないことは、ファイル列挙へ変更する正当な理由です。ただし、D40は`tests/Support/SurfaceRemoval/`共通基盤の逸脱を登録しているのに、11ファイル中3ファイルしか対象パスに列挙していません。代表ファイルだという説明もありません。少なくともD40の逸脱を構成する全ファイルを列挙する必要があります。

また、次の引用は変数・式や動的組み立てを除外している実装より強い保証です。

> 撤去した表面への参照は、走査根8本のgit追跡下の全ファイルで0件である。

「各gateが列挙する静的構文への参照」に狭めるべきです。

意図的逸脱1については、gateとscannerのdocblockが変数・式を明示的に保証外としているため、AGENTS.md (b) が認める「利用側の検出力主張を狭める」選択には該当します。ただし、詳細設計の`$cls`を未解決にする記述、および上記D40の強い保証表現も同時に更新しなければ、設計との一致性は成立しません。

### [Warning] 全体検証はまだgreenではない

提示された`composer test`は1件失敗しており、単独再実行がgreenでも、AGENTS.mdの「全greenでコミット」という完了条件を満たす証跡にはなっていません。環境flakeという分析は妥当そうですが、マージ前に全体レーンのgreenを再取得する必要があります。

## ファイル別判定

- `docs/template-divergence.md` — Warning。対象パス不足と保証の過大表現。
- `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` — 問題なし。コメントのみで描画・props・Atomic Designへの影響なし。
- `resources/js/pages/Settings/Security.svelte` — 問題なし。同上。
- `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php` — Warning。名前解決の自己検証不足。
- `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` — 問題なし。解決済みmiddleware、設定母集団、recent-auth生存の3層は設計どおり。
- `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` — Critical。文字列FQCNをaliasとして無視し、nullable FQCNも黙って除外する。
- `tests/Support/SurfaceRemoval/PhpNameResolver.php` — Critical。mixed group useの関数・定数importを誤登録する。
- `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` — Critical。文字列FQCN・非PHP FQCNの検出欠落。メソッド宣言の誤検出もある。
- `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` — Critical。拡張子なしPHPを非PHPに分類すること自体は宣言済みだが、後段がクラスFQCNを走査しないため組み合わせで見逃しになる。
- `tests/Support/SurfaceRemoval/MiddlewareReference.php` — Warning。種別とnullable値の不変条件が型で表現されていない。
- `ContentClassification.php` / `MethodReference.php` / `MethodReferenceKind.php` / `MiddlewareReferenceKind.php` / `Occurrence.php` / `RemovedTerm.php` / `ScanOutcome.php` / `ScanPopulation.php` / `ScannedFile.php` / `TermMatchMode.php` — 個別の問題なし。
- `tests/Support/TemplateDivergence/LedgerPins.php` — 問題なし。台帳件数との更新は整合。
- `tests/Architecture/fixtures/surface-removal/content/**` — 個別の内容に問題なし。
- `tests/Architecture/fixtures/surface-removal/ocr-flag/**` — 既存見本の内容に問題なし。ただしmixed group use、`parent`、traitの`static`/`parent`見本が不足。
- `tests/Architecture/fixtures/surface-removal/password-confirm/**` — 既存見本の内容に問題なし。ただし文字列FQCN、拡張子なしPHP、shell/YAML中の`RequirePassword`参照見本が不足。
- `resources/js`の2ファイル — DESIGN.mdおよびAtomic Designへの影響なし。
- DTO / JsonResource — 非該当。
- PHPStan — 提示結果上はlevel 10適合。widen、baseline、`@phpstan-ignore`は差分に見当たらない。

CHANGES_REQUESTED