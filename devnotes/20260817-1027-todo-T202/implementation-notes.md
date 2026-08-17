# 実装メモ: T202 一覧検索の対象範囲の拡張

詳細設計: `devnotes/20260817-0909-manual-search-scope/detailed-design.md`

## 設計と実装の差 (対応マトリクス)

| # | 設計の記述 | 実装 | 理由 |
|---|---|---|---|
| 1 | `apply()` の受け型は契約 interface (`Illuminate\Contracts\Database\Eloquent\Builder`)、docblock は `@param Builder<\App\Models\VideoManual>` | 受け型は**設計どおり契約 interface** (第 1 案を採用)。docblock の**型引数だけ落とした** (`@param Builder $query`) | この契約 interface は **generic ではない**ため、型引数を書くと PHPStan level 10 が `generics.notGeneric` で落ちる。受け型そのものは設計どおり通っており (第 2 案への切り替えは不要)、`composer phpstan` は緑。帰結 (渡されたクエリが VideoManual を返すことは型で固定されない) をクラスの docblock に明記した |
| 2 | 施策 5 の完了条件「`Schema::getIndexes('cuts')` の migration 前の出力」「dev DB で `EXPLAIN (ANALYZE, BUFFERS)`」 | **テストレーンの pgsql DB** で採取した | dev DB (`app`) はこの環境では 1 表も存在しない (`Schema::hasTable('cuts') === false`)。dev DB へ `migrate` を掛けるのはエージェント判断で行わない (禁止事項 3 の趣旨)。計測場所を変えたことと、それによる保証範囲の縮小は `index-precheck.md` / `explain-notes.md` に明記した |

上記以外は詳細設計どおりに実装した。

## fail-first の確認 (詳細設計「新規テストファイル: テナント境界」)

`ManualKeywordSearch::apply()` の入れ子 group (`$query->where(function (Builder $scoped) ...)`) を
**一時的に外して** `orWhereHas` を素で積む形にし、`ManualKeywordSearchBoundaryTest` を実行した。

```
{"result":"failed","tests":6,"passed":0,"failed":6}
```

**6 本すべてが赤くなった**。失敗の内訳も期待どおりで、

- 別 project / 別 organization / mine の 3 ケース: 一覧の件数が 1 → **2**
- 撮影 PWA の ready/published 制限: 件数が 1 → **3** (draft / analyzing が混ざった)
- 負のコントロール: `whereKey($a->id)` が OR に押し出され、一致語を持つ **B の id が返った**

入れ子 group を戻して 6 本とも緑に復帰することを確認した。

## 索引の実測 (要点)

- migration 前の `cuts` の索引は **`cuts_pkey` 1 本だけ**だった (設計の断定と一致)。詳細は `index-precheck.md`。
- 索引は**本改善の検索 (`%語%` の LIKE) には効いていない** (両面とも `cuts` へ `Seq Scan`)。
  実際に効いたのは**撮影 PWA 一覧の `withCount(['cuts', ...])`** で、3 本の相関副問い合わせが
  すべて `cuts_video_manual_id_index` を使った。詳細と数値は `explain-notes.md`。

## 実機確認が残っているもの

詳細設計 施策 6 のリスク欄「撮影 PWA の主戦場 (iOS Safari の狭幅) で placeholder が読めることを
実装時に確認する」は**実機・実ブラウザでの確認**であり、本実装では未実施である。
扱いは `manual-verification.md` に記録した。
