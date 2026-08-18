## Round 1 の指摘への対応

### [Warning] AG-135 のスキーマ更新の未追従に、具体的な追跡先が無い → 対応した

TODO 番号をこの場で作ることはできない (設計フローは TODO を登録しない責務分担であり、
存在しない番号を登録簿の根拠欄に書くと `TemplateDivergenceLedgerFormatTest` の
根拠実在検査が落ちる)。そこで、**リポジトリ内に既にある追跡先へ結び付ける**形にした。

- `docs/worktree-isolation-strategy.md` の「既知のギャップ」節 (worktree まわりの未対応事項を
  列挙している実在の節) へ 1 項目足す:
  「正典の `scripts/ci/ensure-test-db.php` は基点 DB のスキーマ更新まで担う形 (家系の裁定 AG-135)
  だが、本アプリの `ensure-test-db.php` は CREATE と出自の記録までで追従していない」
- D30 の「この登録が扱わない範囲」でこの遅れを名指しし、関連欄からその節を指す
- 実装方針に施策 4 として明記した (施策は 5 つになった)
- 追従そのものの TODO 登録は、設計完了報告に提案として付す

### [Suggestion] 出自の記録に失敗した DB が回収されないことを明記 → 対応した

D30 の「保証しないもの」に、`COMMENT ON DATABASE` の付与は best-effort であり、
失敗した DB は `Unlabeled` に落ちて `--include-hash` で人が 1 つずつ名指ししない限り
1 件も回収されないことを書く、と概念設計へ明記した。

### その他の [Suggestion] (使命 / 禁止事項 / 実現可能性 / スコープ / 型安全性)

いずれも本設計の判断を追認する内容だったため、設計の変更はしていない。

---

## 修正後の概念設計 (差分のある箇所のみ)

### 未登録 1 の末尾

> **(b) は「わざと外した点」ではなく追従の遅れである。** 登録簿は逸脱を書く場所であって
> 遅れを書く場所ではない (遅れは追従して消す)。したがって本設計は (a) を登録し、
> (b) は登録の本文で「この登録が扱わない範囲」として名指ししたうえで、
> **リポジトリ内の具体的な追跡先へ結び付ける** — `docs/worktree-isolation-strategy.md` の
> 「既知のギャップ」節に 1 行足し、D30 の関連欄からそこを指す。既にこの節が worktree まわりの
> 未対応事項を列挙しているので、追跡先を新設せずに済む。追従そのものは別の作業項目
> (TODO 登録の提案として本設計の完了報告に付す) で行う。

### 改善アイデアの D30 の説明

> - **D30**: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
>   `scripts/ci/pgsql_test_conn.php` — 孤児テスト DB の回収経路の上積み。状態は `恒久`。
>   「保証しないもの」には、出自の記録に失敗した DB が `Unlabeled` になり
>   `--include-hash` で人が名指ししない限り 1 件も回収されないことを明記する
>   (回収経路を足したことで全部が自動で片づくかのように読ませない)

### 実装方針

> 1. `docs/template-divergence.md` の「登録エントリ: 28 件」を 30 件へ直す
> 2. 同ファイル末尾へ D30 / D31 のエントリを追記する
> 3. `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` の
>    `TEMPLATE_DIVERGENCE_ENTRY_COUNT` を 28 から 30 へ直す
> 4. `docs/worktree-isolation-strategy.md` の「既知のギャップ」へ、正典の
>    `ensure-test-db.php` がスキーマ更新まで担う形 (AG-135) に本アプリが追従していないことを
>    1 項目として足す (D30 の関連欄が指す追跡先)
> 5. `composer test -- --filter=TemplateDivergence` 相当で 3 点一致を確認する

### スコープ外

> - **正典の後発の上積み (AG-135 のスキーマ更新) への追従**。これは遅れであって逸脱ではないので、
>   登録ではなく追従で消す。本設計では D30 の本文で名指しし、
>   `docs/worktree-isolation-strategy.md` の「既知のギャップ」へ追跡先を作るところまでを行う
>   (追従の実施は別の作業項目)

---

以上を踏まえて再判定してください。
