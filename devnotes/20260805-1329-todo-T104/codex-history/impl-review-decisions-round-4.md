# 対応マトリクス: impl-review Round 4

## [Critical] id 欠損検査が severity filter の内側にあり、明示 severity 付き破損が素通りする

- 判断: **対応する (指摘が正しい)**
- 根拠: 実測で再現。`{"advisories":[{"error":"boom","severity":"moderate"}]}` は
  normalizer が明示 `moderate` を維持するため step 4 (high/critical filter) を通過せず、
  moderate warn へ落ちて **exitCode=0** になっていた。

  ```
  normalized: [{"id":"","packageName":"","ecosystem":"npm","severity":"moderate","source":"pnpm-audit"}]
  evaluate  : {"exitCode":0,"failures":[],"moderateWarns":[{...}],"cleanupCandidates":[]}
  ```

  取得結果の破損は severity policy とは別軸の異常であり、
  「unknown severity → high」という別の防壁に依存する設計は脆い、という指摘は妥当。
- 対応内容: id 欠損検査を **severity filter の外へ出し、独立した step 4 に昇格**させた。
  id を持つ advisory だけを `identifiable` に集め、後続の high/critical fail (step 5) と
  moderate warn (step 6) は `identifiable` のみを走査する。
  → id 欠損は severity に関係なく必ず failure になり、warn へ逃げない。
  失敗メッセージも `unidentifiable advisory (missing upstream id, severity=...)` へ変更し、
  severity を含めて出すようにした。
- 偽赤の確認: 実運用の advisory 集合 (4 件) を `loadAuditJson` 経由で正規化し、
  **id が空のものが 0 件**であることを実測で確認済み。`pnpm run audit:gate` は exit 0 のまま。

## [評価] fallback キー統合テストは適切

- 判断: 維持 (変更なし)

## [Critical に付随] moderate / low の負のコントロール追加

- 判断: **対応する**
- 対応内容: `severity` が `moderate` / `low` の id 欠損 advisory それぞれについて、
  (a) 明示 severity が維持されていること、(b) `exitCode=1` になること、
  (c) **`moderateWarns` が空であること** (warn へ逃がしていないこと) を固定するテストを追加。
