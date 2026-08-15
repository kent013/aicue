# 対応マトリクス: design-review Round 2

## [Critical] 施策 3: `resolveLimit(): ?int` では「未指定」と「不正値」を区別できない
- 判断: 対応する
- 根拠: 指摘のとおり。不正値が `null` に落ちると無制限実行になり、誤操作が全件走行を招く
- 対応内容: 引数の解釈は `InvalidArgumentException` で失敗を表し、`handle()` が捕まえて
  メッセージ表示 + FAILURE に変換する形にした。`resolveLimit()` の戻り値 `positive-int|null` の
  `null` は**未指定だけ**を意味する。テスト計画に「0 / 負値 / 非数値は FAILURE」を明記

## [Warning] 施策 4/5: 滞留述語が stream と Service に複製されている
- 判断: 対応する
- 根拠: 「同じ 1 つの式」と設計に書いておきながらコードが 2 か所にあるのは矛盾。
  片方だけ書き換えられれば、今回塞ぐ誤回収がそのまま再発する
- 対応内容: 候補列挙も Service へ委譲した (`staleJobIds()`)。述語は private の
  `applyStalePredicate(Builder, threshold)` **1 か所だけ**が正本で、候補列挙とロック取得の
  両方がこれを通す。stream は列挙も回収も委譲するだけになった。
  テストに「列挙とロック取得の結果が境界で一致する」(レンダは kind × 状態の直積 4 通り) を追加

## [Critical] 施策 10: `IdSuppliedByInternalCaller` は適用条件を満たしていない
- 判断: 対応する (指摘の選択肢 2 を採る)
- 根拠: 機械検査は通ってしまうが、この case が求める「`calledBy` で identity が解決済みモデルから
  確定していること」を満たさない。通る分類を選ぶのは gate に嘘を登録することと同じ
- 対応内容: 分類語彙を 1 つ増やす — `IdFromRecoveryCandidateEnumeration`。
  適用条件をすべて機械検査にする: private + 引数由来 + request accessor 無し +
  `entryPoint` の実在と当該 private の呼び出し + **`entryPoint` の呼び出し元が
  申告 stream の `candidateIds()` / `recover()` だけである** (deny-by-default の呼び出し元集合検査) +
  申告 stream が回収目録に登録済み。既存 case の検査には触れないので他の登録に影響しない。
  波及ファイル (enum / 名前付きコンストラクタ / Architecture テスト) を施策 10 に明記した

## [Warning] 施策 9: 変数経由の呼び出しは token 走査で受信側クラスを確定できない
- 判断: 対応する (保証範囲を狭める)
- 対応内容: 撤去 gate の検出単位を「撤去コマンド名 (完全一致)」「撤去クラス名 (FQCN / 短縮)」
  「撤去メソッドの**宣言**」の 3 種に限定し、**インスタンス呼び出しの検出は保証範囲から外した**。
  撤去の担保は (a) 宣言の不在 + (b) クラス名・コマンド名の不在 + (c) テスト緑
  (宣言が消えているので呼び出しが残れば実行時に落ちる) の組み合わせで得る、と明記

## [Warning] 施策 3: `withoutOverlapping(cadence * 2)` は期限を超えた実行を並行させる
- 判断: 対応する
- 対応内容: 「重複が起きない」とは書かず、**期限を過ぎれば同一系列が並行実行されうる**ことを
  保証の限界として設計と docs に明記した。多重起動しても壊れないことは各 stream の
  再評価が担保する。想定最大実行時間が期限を下回っていることを運用の監視対象に加える

## [Suggestion] 施策 8: CAS 後に行が消えていた場合の扱い
- 判断: 対応する
- 対応内容: `Recovered` に畳まず `RecoveredWithCleanupFailure` + `report()` にした
  (削除できたか確認できていないため。孤児を成功件数の中に隠さない)。テストも追加

## [Suggestion] 施策 8: 実装形を private helper 経由に統一する
- 判断: 対応する
- 対応内容: `releaseIfStillStale()` (CAS + パス取得) に閉じ、`recover()` から主キークエリを消した
  (DirectFetchInventory の登録単位と実装が一致する)

## [Suggestion] 施策 1: `overlapExpiryMinutes() === cadenceMinutes() * 2` を固定する
- 判断: 対応する

## [Suggestion] 施策 2: stream が `$pageSize` より多く返したときの防御
- 判断: 対応する
- 対応内容: sweeper が `array_slice(..., 0, $pageSize)` で防御的に切る。
  各実装が要求件数以下を返すことは stream 単位のテストでも固定する
