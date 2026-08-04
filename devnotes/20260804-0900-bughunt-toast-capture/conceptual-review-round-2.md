全体判定: **CHANGES_REQUESTED**

### 1. 使命との整合性

[Suggestion] 誤検知削減による bug-hunt の信号対雑音比改善は使命に間接的かつ合理的に貢献している。方向性に問題はない。

### 2. 禁止事項違反

[Suggestion] アプリコードを変更せず、probe と台帳にもテストを設ける方針であり、明示された禁止事項への抵触はない。

### 3. 実現可能性

[Warning] `seen` の可視性保証がまだ不十分。mutation callback では `hidden`・`aria-hidden`・接続状態しか確認しないため、`display:none` や `visibility:hidden` の live region が一瞬追加されると、利用者には見えていなくても `seen` に残り、finding を抑止する可能性がある。post-op 時の可視判定では、既に消えた `seen` を補正できない。

修正提案: mutation callback 内で `getClientRects()` と computed style も確認してから `seen` に確定する。描画反映時期が問題になる場合は、候補を一旦保留し `requestAnimationFrame` で可視性を確認する契約にする。jsdom では判定関数を注入・stub 化し、実ブラウザ挙動は L1/L2 で固定する。

### 4. 期待効果の妥当性

[Suggestion] baseline 導入により、常駐 Alertや以前の error toast による偽陰性は適切に除去されている。`installed_now` と組み合わせた判定も、元の誤検知機序に対して十分に対応している。

### 5. リスク

[Warning] 「probe が空なら live region のない一過性 UI を H14 候補にできる」という副次効果は、probe 単独では導けない。空という結果は「視覚的フィードバック自体が存在しない」場合とも区別できない。

修正提案: H14 候補にする条件を「snapshot、DOM 調査などで視覚的な一過性フィードバックの存在を別途確認でき、かつ対応する live region がない場合」に限定する。

### 6. スコープの適切さ

[Suggestion] 正本を `SKILL.md` と probe ファイルに限定し、`bughunt-shard.md` は差分だけにする分割は妥当。template-divergence を増やさない判断にも合理性がある。

### 7. 検証計画の誠実さ

[Warning] jsdom テストの契約表が旧名称の `present` のままで、本文の `present_new` / `present_preexisting` と不一致。

修正提案: テスト対象を明示的に `seen`、`present_new`、`present_preexisting`、baseline 更新、可視性判定、`installed_now`、drain と記載する。特に不可視 live region が `seen` に入らないケースを追加する。

Round 1 の主要指摘は解消されている。残件は、`seen` が本当に利用者へ表示された要素だけを証拠にする契約の補強が中心。