# 対応マトリクス: design-review 確認ラウンド (one-shot / Round 6)

> 詳細設計レビューはセッションモードで 5 ラウンド実施したが APPROVED に至らなかったため、
> app-design SKILL の規定に従い one-shot (`--ephemeral`) の確認ラウンドを 1 回行った。

## 確認ラウンドの結果

- M9 の collector 方式: **成立** (mutation #18 の赤化保証が閉じた)
- M10 の同期: **問題なし**
- 残る指摘: **Warning 1 件**

## [Warning] `Queueable` の `$afterCommit` プロパティ経由の迂回を D1〜D4 が捕捉していない

- 判断: **対応する (指摘は正しい。0 件 pin の主張が嘘になる穴だった)**
- 根拠: `Queue::shouldDispatchAfterCommit()` は
  「`ShouldQueueAfterCommit` の実装 → job の `$afterCommit` プロパティ → 接続 config」の順で
  解決する (vendor `Queue.php` L408-419)。したがって
  `public bool $afterCommit = true;` や `$this->afterCommit = true;` は
  **D1〜D4 のどれにも映らない第 3 の迂回路**である。
- 対応内容: **D5 を追加**した。
  - 既定値: `ReflectionClass::getDefaultProperties()` の `afterCommit` が `true`
    (インスタンス化しないのでコンストラクタ引数が必要な job でも判定できる)
  - 実行時代入: ランタイム PHP に対する `$this->afterCommit = true` / `->afterCommit = true` の走査
  - 0 件 pin テスト 2 本 (4b / 4c)、負のコントロール 2 本 (12b / 12c)
  - mutation #20 / #21 を追加 (「**D1〜D4 では落ちない**」ことも同時に確認する)
  - gate docblock / M10 の AGENTS.md 追記案 / §保証しないもの (14b) にも反映
  - 現状の `app/` に `$afterCommit` プロパティの使用は 0 件であることを確認済み
    (`grep -rn afterCommit app/` の結果はコメントと `->afterCommit()` 1 件のみ)

## 自己検証で見つけた追加の問題 (Codex 指摘外。同ラウンドで修正)

### D1/D2/D5(代入) を素の文字列走査にすると **本設計自身が gate を落とす**

- 根拠: M8 の反転 docblock は旧主張として `->afterCommit()` を引用する。
  コメント・docblock を見る検出器だと、反転を書いた瞬間に D1 が発火して gate が落ちる。
- 対応内容: D1/D2/D5(代入) を **token 走査**に変更し、既存の
  `Tests\Support\PhpTokenScan::normalize()` を再利用する
  (`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` 除去済み。同 docblock が
  「同じ正規化を 2 本持たない」と明記しており、`QueuedJobLeaseInventoryTest` と
  `ExternalClientBoundaryScanner` が既に共用している)。
  文字列リテラル (`T_CONSTANT_ENCAPSED_STRING`) も明示除外する。
  **偽陽性の負のコントロール** (テスト 12d:
  「コメント / docblock / 文字列リテラル中の `->afterCommit()` は検出しない」) を追加した。
- 副次効果: §保証しないもの 15 番を「コメントを誤検出しうる」から
  「token 走査なのでコメント・文字列は対象外。裏を返すと動的呼び出しには沈黙する」へ書き換えた。

## 最終状態

Codex の確認ラウンドの結論は「**この 1 点 (D5) を反映すれば実装フェーズへ進めてよい設計**」。
D5 と token 走査への変更を反映済み。**ただし反映後の再レビューは行っていない**
(ラウンド上限に到達したため)。したがって本設計の**最終判定は CHANGES_REQUESTED のまま**であり、
APPROVED を騙らない。実装フェーズの最初に、D5 と token 走査部分だけを対象に
`app-implement` の impl-review で再確認すること。
