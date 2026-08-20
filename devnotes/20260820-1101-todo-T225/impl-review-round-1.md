## ファイル別判定

### [enum-ts-sync-discovery.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery.test.ts) — Critical

逆走査の TS 母集団が `ENUM_TS_MIRRORS` に登録された TS ファイルから組み立てられています。

```ts
createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))])
```

`collectTsUnionCandidates()` は `program.getSourceFiles()` しか走査しないため、既存ミラーから import されない未登録ファイルは候補に入りません。例えば、単独の新規ファイルに PHP 列挙と完全一致する型別名を追加しても、候補数自体は既存ファイルによって非ゼロなので gate は緑のままです。

これは「TS 側も全数走査する」という完了条件と deny-by-default を直接破ります。`resources/js/` の意図した全 `.ts` 母集団から Program を構築し、期待したファイル集合と実際に走査した集合の一致も固定する必要があります。

### [ts-candidates.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/ts-candidates.ts) — Critical

構文診断があるファイルを無言で除外しています。

```ts
if (program.getSyntacticDiagnostics(source).length > 0) continue;
```

これは AGENTS.md §共通規約 (b) の「解決できない形を無言で候補から外さない」に反します。候補を含むファイルに別の構文エラーがあれば、逆走査だけが静かに空振りします。`pnpm typecheck` が別レーンで失敗するとしても、本 gate が主張する fail-closed の代用にはなりません。

少なくとも走査対象ファイルの構文診断は例外または明示的な `unresolvable` として gate を失敗させるべきです。また、現在の「候補が1件以上」という検査では「全対象ファイルを走査した」ことを証明できません。

### [php-enum-catalog.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enum-catalog.ts) — Critical

`headers.length === 0` を即座に非対象扱いするため、有効な string enum が未分類のまま消える構文があります。

代表例は括弧付き namespace です。

```php
namespace App\Example {
    enum State: string {
        case Ready = 'ready';
    }
}
```

`scanEnumHeaders()` は深さ 0 だけを見るため、この enum は検出されず `undefined` になります。全 PHP ファイルは走査していても、PHP string enum の母集団を全数分類できていません。

さらに `scan()` 失敗時の救済正規表現も完全には fail-closed ではありません。

```ts
/\benum\s+[A-Za-z_][A-Za-z0-9_]*/
```

PHP で許される `enum /* comment */ State` や非 ASCII 識別子はこの判定から落ち得ます。D10 は通常の `enum D10` しか試していないため、この穴を固定できていません。

読み切れない有効構文は `unresolvable` に送るか、保護対象を書ける構文を網羅して検出する必要があります。完了条件が「app/ の PHP string enum 全数」である以上、単に保証範囲を深さ 0 に狭めるだけでは解決しません。

同ファイルの docblock にある `AppMcpServer.php` の過剰検出例も、実テストが緑で `KNOWN_UNRESOLVABLE_PHP_ENUMS` に同ファイルが無いことと整合していません。実際の正規表現が拾う形に合わせて説明を訂正すべきです。

### [reverse-sweep.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/reverse-sweep.ts) — Warning

「厳密な名前対応」という説明に対して、`normalizeName()` が英数字以外をすべて削除するのは緩すぎます。

```ts
name.toLowerCase().replace(/[^a-z0-9]/g, "")
```

TS 識別子で有効な `_` や `$` まで消すため、`Foo_Bar` と `FooBar`、`Foo_Values` と `FooValues` などを同一視します。要件の「一致 / +s / +es / +values」より広い判定です。大文字小文字だけを正規化するなど、許可する変換を明示的に限定するのが安全です。

また、`ResolvedPhpEnum.name` を収集しているのに、名前対応ではファイル名から再計算しています。これは共通規約 (d) の観点で死んだ収集結果です。`phpEnum.name` を使えば、宣言から抽出した情報を実際の判定に接続できます。

### [enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts) — Warning

負例行列は充実していますが、上記の検出穴に対する感度確認がありません。

不足している重要な負例は次です。

- 登録済みミラーから import されない TS ファイルも走査されること
- 走査対象 TS ファイルの構文解析失敗が gate を失敗させること
- 括弧付き namespace 内の string enum
- `scan()` が拒否する字句と `enum /* comment */ Name` が同居するケース
- `FooValue`、`MyFoo`、`Foo_State`、`F$oo` など名前の近接負例

E8 の `CompletelyUnrelatedName` は、部分文字列ベースなどの緩い実装へ壊した場合の感度を十分に示しません。

報告された故障注入は exemption 行を削除する三例であり、最終 gate の集合比較は確認できています。一方、走査根・構文エラー処理・名前対応アルゴリズムを壊した際の感度確認にはなっていません。

### [enum-ts-sync-discovery.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery.test.ts) — Warning

stale 検出は三目録すべてにあり、deny-by-default の集合判定自体は適切です。

ただし `catalog.unresolvable[].reason` は収集されるだけで、判定にも失敗メッセージにも使われていません。共通規約 (d) に合わせ、未知の抽出不能項目を `path: reason` で表示するなど、収集結果を診断へ接続してください。

`KNOWN_UNRESOLVABLE_PHP_ENUMS` についても、他の exemption と同様に重複、パス形式、理由の非空または最低文字数を検査すると目録の品質が揃います。

86件の `PHP_ENUM_EXEMPTIONS` は、列挙ごとの用途と「TS に値を渡さない理由」が概ね具体的です。単一の `REVERSE_SWEEP_EXEMPTIONS` も、件数が1件であること自体は網羅性不足ではなく、純関数テストと stale 検査が十分なら問題ありません。

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T225/docs/architecture.md) — Warning

現状の実装に対して、以下の保証は過大です。

- `resources/js/` 配下の型別名を「全数走査」
- app/ の string enum を未分類なく分類
- D29 の再判定条件を満たした

TS Program の母集団と PHP の深さ 0 制約を修正するまで、記述を成立済みにはできません。

### [AGENTS.md](/workspace/.claude/worktrees/tasks/T225/AGENTS.md) — Warning

「TS 側も全数走査で逆走査する」という規約追加が現在の実装より強い保証になっています。実装側を全数走査へ直してから採用すべき内容です。

### [docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T225/docs/template-divergence.md) — Warning

D29 の再判定条件はまだ満たされていません。特に未登録・未 import の TS ファイルが沈黙するため、「逆走査を持つ」状態へ完全には移行できていません。

上記 Critical を直すまでは D29 を残し、登録件数も31件のままにする必要があります。

### [TemplateDivergenceLedgerFormatTest.php](/workspace/.claude/worktrees/tasks/T225/tests/Architecture/TemplateDivergenceLedgerFormatTest.php) — Warning

D29 削除が時期尚早なので、件数30への更新も連動して戻す必要があります。D29の条件を満たした後なら変更自体は正しいです。

### 指摘なし

以下は提示差分の範囲では問題ありません。

- [enum-ts-sync.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync.test.ts): 既存 gate の目録参照先変更であり、同期検査の責務は維持されています。
- [mirror-inventory.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/mirror-inventory.ts): `ENUM_TS_MIRRORS` の単一出典化は DRY の完了条件に合致しています。
- [php-enums.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enums.ts): `detectEnumHeaders` と既存抽出器が同じ `scan()`／`scanEnumHeaders()` を共有しており、PHP 抽出器の二重化はありません。
- [mixed.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts): 現在の基本的な正例・非対象例としては妥当です。

## 全体判定

**CHANGES_REQUESTED**

stale 検出、目録の単一出典化、二つの逆走査規則、理由付き exemption はよく実装されています。しかし、TS の走査母集団が登録済み目録から派生している点と、PHP の有効な string enum を未分類のまま除外できる点により、中心要件である「全数走査による既定拒否」がまだ成立していません。報告済みのテスト成功や exemption 削除の故障注入では、この二つの見逃しは検出できません。