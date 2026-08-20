実装上の Critical は解消されています。深さ0以外の候補が混在する場合を必ず `unresolvable` にし、D17/D18で両方向の退行を固定できています。

ただし、TS母集団の正本に関する文書がまだ相互矛盾しています。

## ファイル別判定

### [php-enum-catalog.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enum-catalog.ts) — 指摘なし

次の条件で、全候補が深さ0であることを正しく要求しています。

```ts
if (depthZero.length !== headers.length) {
    return { kind: "unresolvable", ... };
}
```

これにより以下がすべて fail-closed になりました。

- 非ゼロ深さの候補だけがある
- 深さ0のstring enumと非ゼロ深さのenumが共存する
- 深さ0のint/pure enumと非ゼロ深さのstring enumが共存する

D13、D15〜D18と故障注入も、この分岐の検出力を十分に固定しています。

### [enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts) — Warning

TS母集団について、テスト名とコメントに古い説明が残っています。

```ts
it("母集団は明示した tsFiles に依存しない (tsconfig の include が母集団の単一出典)", ...)
```

しかし、追加された完全一致テストによって実際に固定される不変条件は次のものです。

> ファイルシステム上の `resources/js/**/*.ts`（`.d.ts`除外）と、Programに載った対象ファイル集合が完全一致する

したがって、tsconfigはProgramを構築する入力ですが、期待する母集団の単一出典ではありません。このテスト名とコメントは、更新したarchitecture文書の前半に合わせる必要があります。

### [ts-candidates.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/ts-candidates.ts) — Warning

docblockに同じ古い説明が残っています。

```text
母集団の単一出典
実質の母集団は tsconfig.json の include から exclude を引いた集合
```

現在はfilesystem側との完全一致テストがあり、tsconfigの `exclude` を広げても母集団が狭まることは許されません。そのため「tsconfigのinclude/excludeが単一出典」という説明は実際の保証と一致しません。

「Program側の母集団はtsconfigから組み立てるが、filesystemを直接走査した期待集合との完全一致を別テストで強制する」とするのが正確です。

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T225/docs/architecture.md) — Warning

発見の段の本文は正しく更新されています。

> それだけを出典とは言わない  
> filesystem集合とProgram集合の完全一致が不変条件の実体

一方、「保証しないもの」の末尾には逆の説明が残っています。

> 母集団は `tsconfig.json` の `include`/`exclude` が単一の出典

同一セクション内で正本が二つの異なる説明になっています。後者を、本文と同じ「filesystem期待集合との完全一致」へ揃える必要があります。

### [php-enum-catalog.ts](/workspace/.claude/worktrees/tasks/T225/tests/js/support/enum-ts-sync/php-enum-catalog.ts) — Suggestion

catch内のコメントにはまだ「enum宣言らしい並び」とありますが、実装は任意の `enum` 語を拾います。

```ts
// 生のソースに enum 宣言らしい並びが無ければ
```

「`enum` の語が無ければ」に揃えると、docblockと実装に一致します。

### D29関連 — 指摘なし

実装上は全数走査、fail-closedな三分類、逆走査2規則が成立しています。したがって、D29の削除と登録件数30件への変更は妥当です。今回残っているのはTS母集団の説明上の矛盾であり、D29を戻す必要はありません。

## 全体判定

**CHANGES_REQUESTED**

コード上の懸念は解消されており、残件は文書整合だけです。ただし、母集団の正本はgateの保証範囲そのものなので、`docs/architecture.md`・`ts-candidates.ts`・回帰テストの説明を同じ定義へ揃えてから承認とします。