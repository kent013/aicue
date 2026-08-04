# 対応マトリクス: conceptual-review Round 2

## [Warning] `seen` の可視性保証が不十分 (`display:none` / `visibility:hidden` を拾いうる) (観点 3)
- 判断: **対応する**
- 根拠: 指摘は正しい。一過性 UI は**消えた後では可視性を測れない**ので、記録時の属性足切りだけでは
  「不可視のまま一瞬 DOM に居た live region」を証拠にしてしまい、**H7 finding を不当に抑止する**
  (= 偽陰性)。
- 対応内容: 可視性判定を **2 段**にした (概念設計 §実装方針 1)。
  1. mutation callback: layout 非依存の足切り (`el.hidden` / 祖先 `aria-hidden="true"`)。
     この時点で既に DOM から消えていれば `visible: "gone"` (1 フレーム未満の点滅 = 知覚不能) として確定。
  2. **次フレーム** (`requestAnimationFrame`、無ければ `setTimeout(...,0)` に fallback) で
     `getClientRects().length > 0` + `display` / `visibility` を評価し
     (`tests/Browser/FlashToastTest.php:50-58` と同条件)、`visible: true|false|gone` を刻んでから
     `seen` に確定する。
  - **証拠として数えるのは `visible: true` の entry だけ**。`false`/`gone` は返すが数えない。
  - 未解決件数を `pending` で返し、post-op probe で `pending > 0` なら probe をもう 1 回叩いてから判定する
    (判定表に行を追加)。
  - jsdom は layout を持たないため、テスト側で `getClientRects` / `getComputedStyle` を stub する
    (probe 本体にテスト用フックは入れない)。実ブラウザ挙動は L1/L2 の live 受入条件で固定する。
- **実測**: 本方針の参照実装を jsdom で実行し、**17 assertion 全て PASS** することを確認した
  (常駐 Alert / 残存 error toast を証拠にしない / 消えた後の toast を `visible:true` で捕捉 /
  `display:none` を `visible:false` で記録 / `aria-hidden` 配下は記録しない / サブフレーム点滅は `gone` /
  drain / characterData 更新 / hidden→visible / 記録器喪失検知)。参照実装と検証スクリプトは詳細設計に載せる。

## [Warning] 「probe が空 → H14 候補」は probe 単独では導けない (観点 5)
- 判断: **対応する**
- 根拠: 指摘のとおり。probe が空という結果は「視覚的フィードバック自体が無い」場合と区別できない。
  条件を付けずに H14 へ格上げすると、**誤検知の作り替え**になる。
- 対応内容: 期待効果の「副次」を条件つきに書き換えた。
  H14 候補にできるのは「**snapshot / DOM 調査で視覚的な一過性フィードバックの存在を別途確認でき、
  かつ対応する live region が無い**」ことを示せた場合のみ。それ以外は主張を
  「一過性フィードバックを観測できなかった (H7)」に留める。

## [Warning] 検証計画の契約名が旧名 `present` のままで本文と不一致 (観点 7)
- 判断: **対応する**
- 対応内容: 検証方法の表を書き換え、テスト対象を明示列挙した:
  `installed_now` (arm / 窓喪失検知) / `seen` (消えた後でも捕捉・**不可視 live region が入らない**・
  `visible:gone` の扱い) / `present_new` / `present_preexisting` (常駐 Alert・残存 error toast を
  証拠にしない) / **基線更新** / 可視性判定 (stub) / `pending` / drain (二重計上しない) /
  非 live-region の DOM 変化を拾わない。

## [Suggestion] 観点 1・2・4・6 の肯定コメント
- 判断: 対応不要 (方向性維持)
