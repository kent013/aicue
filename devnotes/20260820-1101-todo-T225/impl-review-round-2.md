Round 1 の TS 母集団に関する指摘は撤回します。`createMirrorProgram([])` の回帰テストにより、登録済みミラーの import グラフに閉じないことは十分固定されています。

ただし、PHP 側にまだ fail-open となる有効構文が残っています。

## ファイル別判定

### [php-enum-catalog.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enum-catalog.ts) — Critical

波括弧付き namespace の検出が一部の有効な PHP 構文しか扱っていません。

```ts
/^[ \t]*namespace\s+[A-Za-z_][A-Za-z0-9_\\]*\s*\{/m
```

少なくとも次の有効な形を検出できません。

```php
namespace {
    enum State: string {
        case Ready = 'ready';
    }
}
```

これはグローバルな波括弧付き namespace です。`detectEnumHeaders()` は深さ1の enum を返さず、`BRACKETED_NAMESPACE_DECLARATION` にも一致しないため、`classifyPhpFile()` は `undefined` を返します。string enum が三分類のどこにも入らない実質的な fail-open です。

ほかにも、PHP キーワードは大文字小文字を区別しないのに正規表現に `i` がないため、次も漏れます。

```php
NAMESPACE App\Example {
    enum State: string { /* ... */ }
}
```

コメントを挟む `namespace /* comment */ App\Example {` や非ASCII名前も同じ問題を持ちます。

さらに、問題は namespace だけではありません。現在は「深さ0でヘッダーが見つからなかった理由」を named bracketed namespace に限定して推測しています。有効な条件付き enum 宣言など、別の理由で深さが0でない場合も同様に消えます。

個別の namespace 正規表現を増やすより、`scan()` が成功した場合も「コード状態にある `enum` トークンは存在するが、受理可能な深さ0ヘッダーとして解決できなかった」ことを判別し、`unresolvable` へ送る設計が堅牢です。共有走査器から深さ付きの enum 候補を返せば、抽出器の二重化も避けられます。

### [enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts) — Warning

D13 は named namespace の成功例だけなので、上記の穴を固定できません。最低限、次の負例が必要です。

- `namespace { enum State: string ... }`
- 大文字の `NAMESPACE`
- namespace 宣言にコメントを挟む形
- named namespace 以外の非ゼロ深さに enum がある場合を保証外とするのか、`unresolvable` にするのか

また、TS 母集団の新しい回帰テストは「ミラー引数に依存しない」ことは十分証明していますが、「`resources/js/` の対象ファイルを全件含む」ことまでは証明していません。例えば tsconfig の `exclude` に一つの型ファイルが加わっても、`toast.ts` が残っていればテストは緑です。

全数性を主張するなら、意図した `.ts` ファイル集合と Program に載った非宣言ファイル集合の差分が空であることを固定する方が確実です。

### [ts-candidates.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/ts-candidates.ts) — Warning

構文診断を例外にした修正は適切で、前回の fail-closed 指摘は解消しています。

一方、次の分岐は docblock の「保証しないもの」に明記されていません。

```ts
if (source.isDeclarationFile) continue;
```

実測説明では `vite-env.d.ts` の除外を「正しい」としていますが、docblock と `docs/architecture.md` は単に「`.ts` ファイル」と書いています。手書きの `.d.ts` に literal union が置かれた場合も無言で対象外になります。

`.d.ts` を意図的に対象外にするなら、その制限を走査器の docblockと正本ドキュメントへ明記してください。

### [reverse-sweep.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/reverse-sweep.ts) — 指摘なし

前回の二点は解消しています。

- 名前正規化は大小文字だけに限定された
- `ResolvedPhpEnum.name` が実際の判定に使われる
- E10〜E13 が緩い一致とファイル名への逆戻りを防いでいる

二つの逆走査規則の優先順位と集合比較にも問題は見当たりません。

### [enum-ts-sync-discovery.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery.test.ts) — Suggestion

`unresolvable.reason` が失敗メッセージへ接続され、共通規約 (d) の問題は解消しています。既知の抽出不能目録についても、実在・重複・理由長・stale が検証されています。

docblock の次の説明だけは実装更新前の表現です。

> 生のソースに enum 宣言らしい並びが無ければ

現在の実装は「宣言らしい並び」ではなく、コメントを含む任意の `enum` 語を安全側に拾います。正確には「`enum` の語が無ければ」と揃えると、`php-enum-catalog.ts` および architecture 文書と一致します。

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T225/docs/architecture.md) — Warning

named bracketed namespace 以外の有効構文が未分類になるため、現時点では「未分類なく既定拒否」とする記述がまだ過大です。

また、TS 側について `.d.ts` を除外することを保証外へ追加する必要があります。全数性を tsconfig に委ねる設計なら、`include` だけでなく `exclude` と宣言ファイル除外も母集団の定義に影響することを明記するのが正確です。

### [docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T225/docs/template-divergence.md) — Warning

PHP string enum が三分類を迂回できるため、D29 の「全数走査による既定拒否」という再判定条件はまだ完全には満たされていません。上記 Critical を直すまではD29削除を確定できません。

### [TemplateDivergenceLedgerFormatTest.php](/workspace/.claude/worktrees/tasks/T225/tests/Architecture/TemplateDivergenceLedgerFormatTest.php) — Warning

D29の扱いに連動します。PHP母集団の穴を修正してD29削除を維持できれば30件で正しく、修正前なら31件へ戻す必要があります。

### 指摘なし

以下は提示差分の範囲で懸念ありません。

- [AGENTS.md](/workspace/.claude/worktrees/tasks/T225/AGENTS.md)
- [enum-ts-sync.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync.test.ts)
- [mirror-inventory.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/mirror-inventory.ts)
- [php-enums.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enums.ts)
- [candidates-broken/broken.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts)
- [candidates/mixed.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts)

## 全体判定

**CHANGES_REQUESTED**

Round 1 の大部分は適切に解消され、特に TS Program の母集団、構文診断、名前対応、stale目録の改善は確認できました。ただし、グローバルな波括弧付き namespace など、有効な PHP string enum が未分類のまま消える経路が残っています。ここを `unresolvable` へ fail-closed に接続すれば、D29削除を含めて承認可能な状態に近づきます。