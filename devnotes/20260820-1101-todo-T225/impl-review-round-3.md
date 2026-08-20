## ファイル別判定

### [php-enum-catalog.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enum-catalog.ts) — Critical

深さ0以外の enum と深さ0の enum が同じファイルに共存すると、深さ0以外の enum が無視されます。

現在の実装は、深さ0がゼロの場合だけ `unresolvable` にします。

```ts
const depthZero = headers.filter((header) => header.depth === 0);
if (depthZero.length === 0) {
    return { kind: "unresolvable", ... };
}
```

したがって、例えば次の入力では `Nested` が分類から消えます。

```php
enum Outer: string
{
    case A = 'a';
}

if (true) {
    enum Nested: string
    {
        case B = 'b';
    }
}
```

`depthZero` は `Outer` の1件なので、その後 `readPhpEnumValuesFromText()` が `Outer` だけを読み、ファイル全体が `resolved` になります。深さ1の `Nested` は `unresolvable` にも入りません。

深さ0が int/pure enum、深さ1が string enum の場合はさらに明確で、深さ0の backing を見てファイル全体を `undefined` にできるため、対象の string enum が完全に母集団から消えます。

これは docblock の次の保証とも一致していません。

> 深さ 0 でない位置の enum 宣言は `unresolvable` にする

`headers` に深さ0以外が1件でも含まれていれば、深さ0候補の有無にかかわらず安全側へ倒す必要があります。例えば次の順序です。

```ts
const depthZero = headers.filter((header) => header.depth === 0);

if (depthZero.length !== headers.length) {
    return { kind: "unresolvable", ... };
}
```

その後で深さ0の件数・backingを判定すれば、共有走査器が集めた候補を一件も捨てずに済みます。

### [enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts) — Warning

D13/D15/D16 は「非ゼロ深さの候補しかない」ケースを固定していますが、上記の「深さ0と非ゼロ深さが共存する」分岐を固定していません。

少なくとも次の二例が必要です。

- 深さ0の string enum + 非ゼロ深さの string enum → `unresolvable`
- 深さ0の intまたはpure enum + 非ゼロ深さの string enum → `unresolvable`

今回の故障注入も D13/D15/D16 だけが対象なので、この共存時の見逃しは検出できません。

### [php-enums.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enums.ts) — 指摘なし

共有走査器が全深さの候補と深さを返し、既存の値抽出器が自分の責務として深さ0へ絞る設計は適切です。抽出器の二重化もありません。

問題は収集された非ゼロ深さ候補を `php-enum-catalog.ts` が一部の場合に捨てている点です。

### [ts-candidates.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/ts-candidates.ts) — 指摘なし

前回の指摘は解消しています。

- 構文診断は fail-closed
- `.d.ts` 除外が明記されている
- 通常 `.ts` ファイルの母集団が明確になっている

### [enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts) — Suggestion

TSの期待集合とProgram側の集合を独立に作り、完全一致させるテストは前回の懸念を十分に解消しています。

ただし文書では「母集団の単一出典はtsconfig」とされていますが、このテストによって実際には「`resources/js` のファイルシステム上の全 `.ts`（`.d.ts` 除外）とtsconfig結果が一致すること」が不変条件になっています。tsconfigの `exclude` を広げると意図どおり赤くなるため、実質的な期待集合の正本はファイルシステム側です。説明をこの関係に合わせるとより正確です。

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T225/docs/architecture.md) — Warning

「深さ0でない位置のenumは `unresolvable`」という設計記述に対し、共存時は現在の実装がそうなっていません。上記Criticalを直せば記述を維持できます。

TS母集団については、`include/exclude` が自由に母集団を決めるのではなく、追加されたテストが「ファイルシステム上の全通常 `.ts` と一致すること」を強制しています。「tsconfigが単一の出典」という表現は少し不正確です。

### D29関連 — Warning

[docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T225/docs/template-divergence.md) と [TemplateDivergenceLedgerFormatTest.php](/workspace/.claude/worktrees/tasks/T225/tests/Architecture/TemplateDivergenceLedgerFormatTest.php) の変更内容自体は整合しています。

ただし、非ゼロ深さのstring enumが深さ0候補との共存時に分類から消えるため、D29の再判定条件はまだ完全には満たされていません。上記Criticalを修正すれば、D29削除・30件のままで問題ありません。

### その他のファイル — 指摘なし

逆走査、目録のstale検出、DRY化、理由付きexemptionには新たな懸念はありません。

## 全体判定

**CHANGES_REQUESTED**

共有走査器を深さ付き候補へ変更した方向性は適切で、Round 2 の主要な穴はほぼ解消しています。残る問題は、収集した非ゼロ深さ候補を「深さ0候補と共存した場合」に捨てている一点です。全深さ候補と深さ0候補の件数が異なる場合を `unresolvable` にすれば、中心要件の fail-closed が成立します。