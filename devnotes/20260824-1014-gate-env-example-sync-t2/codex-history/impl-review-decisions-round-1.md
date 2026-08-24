# Codex 実装レビュー Round 1 への対応マトリクス (T256)

Codex (gpt-5.6-sol / reasoning=high) の全体判定は **CHANGES_REQUESTED**。
Critical は 0 件、Warning 2 件、Suggestion 1 件。すべて対応した。

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | 規則 1 の「キー集合が完全一致」が片方向ずつしか裏取りされていない (`kinds` の**不足**と `classifications` の**余分**の負例が無い)。片方向の `array_diff()` へ弱体化しても V1〜V21 が全通する | **対応する** | 負のコントロールへ **V23 (種別の申告のキーが不足している)** と **V24 (分類の申告に余分な種別がある)** を追加。`ENV_EXAMPLE_LEDGER_COUNTEREXAMPLE_IDS` と規則⇔ケースの対応表も同じ変更で更新した |
| 2 | Warning | 規則 4 の負例が V3 (種別をまたいだ重複) だけで、**同一種別内の重複**が無い。旧方式 (値の固定と必須キーの交差だけを見る) へ退行しても V3 は通る | **対応する** | **V22 (同一種別の中の重複)** を追加。申告件数は実件数と合わせてあるので、発火するのは規則 4 の分岐だけである |
| 3 | Warning | `$withEntryField(entries, index, string $field, ?string $value)` は型契約上、未知の項目の追加や `key` / `kind` / `origin` への `null` 代入を許すのに、戻り値を固定 shape として宣言している (詳細設計の「将来 PHPStan へ編入しても耐える型注記」と食い違う) | **対応する** | ヘルパを `$withEntry(entries, index, array $entry)` へ差し替え、`@param` に entry の shape をそのまま宣言した。呼び出し側は `[...$soundEntries[N], '項目' => 値]` と書くので「1 か所だけ壊した」ことは差分で見えたままである |
| 4 | Suggestion | `envExampleCounterexampleIds()` の結果を docblock が「集合」と説明しているが、実装は順序込みの `toBe()` である | **対応する** | 順序込みの並びが不変条件であることを 2 つの識別子定数・2 つの反証データセットの docblock・床の検査のコメントへ明記した (番号を詰めない / 付け替えない規約と対になっており、並べ替えも赤くなるのが意図した挙動である)。あわせて `docs/template-divergence.md` D51 の「識別子集合」も「識別子の並び」へ直した |

## 対応後の確認

```
$ composer test -- --filter='EnvExampleInvariantTest'
tests=61 passed=61 assertions=94       (V22〜V24 の 3 件ぶん増えた)
$ composer phpstan   → No errors
$ vendor/bin/pint --test → passed
```

## 反論・見送りにしたもの

なし (4 件すべて対応)。
