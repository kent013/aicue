# Round 3: 詳細設計の改訂版 (RC-7 の `on delete` 意味論)

Round 2 の指摘への対応マトリクスは
`devnotes/20260815-2057-retention-table-classification/codex-history/design-review-decisions-round-2.md`
に記録した。修正必須とされた 1 点 (RC-7 の `on_delete` 意味論) を含め、全指摘に対応した。

## 対応の要点

| # | 指摘 | 判断 | 対応 |
|---|---|---|---|
| 1 | [Warning] RC-7 が `on delete set null` を誤検出する | **対応 (設計の誤りだった)** | `on delete` の動作別に定義し直した (下記) |
| 2 | [Suggestion] NC-4 を cascade / restrict にし `set null` の正のコントロールを足す | 対応 | 境界の両側を固定するテストを計画に追加 |
| 3 | [Warning] 施策 5 の文書にも `on delete` の扱いを反映 | 対応 | 動作別の一覧と実例を書く指示にした |
| 4 | [Suggestion] AGENTS.md では RC-7 の条件を書かず architecture へ委譲 | 対応 | 規約の骨子に明記 |
| 5 | [Suggestion] `hasHorizon()` の docblock | 対応 | 「削除期限の実在を保証する述語ではない」を追記 |
| 6 | [Suggestion] `entries()` の PHPDoc | 対応済み (Round 1) | — |
| 7 | [Suggestion] 「必要な区分だけ照会する」旧記述の削除 | 対応済み | Round 1 の修正時点で消えている (grep で 0 件) |
| 8 | [Suggestion] RC-5 の失敗理由を分けて集める | 実質対応済み | 収集時に `=> class …` / `=> command …` と接頭辞を分けており、欠けている側が読める。テストを 2 本に割っても直す作業は同じなので分けない |

## 指摘 1 の裏取り (設計を直した根拠)

指摘は本リポジトリの実物で裏が取れた。

- `llm_call_logs` は `organization_id` / `user_id` を **`nullOnDelete()`** で持つ
- `security_audit_events` は `user_id` を **`nullOnDelete()`** で持つ

つまり組織削除・退会の後も**行は残る**。ここを一律違反にすると、
「残る表を『親と一緒に消える』と偽って分類する」方向へ検査が働き、事実と逆になる。
(なおこの 2 表は初期分類でも「未確定」に置いている — 行が残るのに保持期限が決まっていないため。)

## 改訂後の RC-7

```php
/**
 * RC-7 の判定 (**純関数**)。期限を持たない区分の表が、期限が要る区分の表を
 * **矛盾する `on delete` で**参照していないか。
 *
 * ★**外部キーの存在だけでは違反にしない**。親が消えたときに子がどうなるかで意味が変わる:
 *   - `cascade`      = 子も消える              → 「期限を持たない」と矛盾する (違反)
 *   - `restrict` / `no action` = 親を消せなくする → 親の期限の執行を止める (違反)
 *   - `set null`     = 子は列が空になって残る    → 子自身は期限の連鎖の外にある (**違反にしない**)
 *   - `set default`  = 子は既定値になって残る    → 意味が既定値の指す先に依存する。
 *                      本リポジトリに 1 本も無いため、**現れたら分類の見直しが要る**ものとして
 *                      保守的に違反へ倒す
 *   - `null` (取得できない) = 未知              → 保守的に違反へ倒す
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, on_delete: string|null}>>  $foreignKeys
 * @return list<string> `{表名} -> {親表名} (on delete …)` の形の違反一覧
 */
function retentionHorizonParentViolations(array $entries, array $foreignKeys): array
{
    // 子が残らない / 親を消せなくする / 意味が確定しない、の 3 つを矛盾とみなす
    $conflicting = ['cascade', 'restrict', 'no action', 'set default', null];
    // …
}
```

検査一覧の RC-7 の説明も次に差し替えた:

> RC-7 | 「基準データ」「基盤が寿命を持つ」が、期限が要る区分の表を**矛盾する `on delete` で**参照していない | 期限の連鎖の中にある表を「期限を持たない」と分類した

設計本文に置いた注記:

> **`on delete set null` を違反にしない理由**: 親が消えても子の行は残る (列が空になるだけ) ので、
> 子自身は期限の連鎖の外にある。実際 `llm_call_logs` / `security_audit_events` は
> 組織・利用者への外部キーを `set null` で持っており、**退会・組織削除の後も行が残る**。
> ここを一律違反にすると、残る表を「親と一緒に消える」と偽って分類させることになり、
> 検査が事実と逆の方向へ働く。

## 改訂後のテスト計画 (指摘 2)

- NC-4: 「基準データ」の表が「定期実行が消す」表を **`cascade`** で参照すると RC-7 が点灯する
  (`restrict` でも点灯することを同じテストで確かめる)
- **正のコントロール**: 同じ参照を **`set null`** にすると RC-7 が点灯しない。
  これが無いと「外部キーがあれば全部赤」に退化しても気付けない

## 改訂後の文書方針 (指摘 3・4)

`docs/architecture.md` に書く内容へ次を追加した:

> - RC-7 は「期限が要る表への外部キーを一律禁止」ではない。
>   **親が消えたときに子がどうなるかで判断する**ことを `on delete` の動作別に列挙する
>   (`cascade` / `restrict` / `no action` / `set default` / 取得できない = 矛盾、
>   `set null` = 矛盾ではない)。`llm_call_logs` / `security_audit_events` が
>   `set null` で組織・利用者を参照し、**退会後も行が残る**実例をそのまま書く

`AGENTS.md` の規約骨子には次を明記した:

> **外部キーをどう読むか (`on delete` の動作別の扱い) は規約本文に書かず**、正本の
> `docs/architecture.md` §表ごとの保持期限の分類 へ委譲する
> (規約本文に条件を写すと必ず食い違う)

## 質問

1. RC-7 の `on delete` 意味論はこれで正しいか。とくに `set default` を保守的に違反へ倒す判断
   (本リポジトリに 1 本も無いため、現れたら分類の見直しを促す) は妥当か。
2. 残る [Critical] / [Warning] があれば挙げてほしい。実装時に決めれば足りるものは
   [Suggestion] にしてほしい。

各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示すること。
