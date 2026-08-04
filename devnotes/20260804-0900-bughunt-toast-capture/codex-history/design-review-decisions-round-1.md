# 対応マトリクス: design-review Round 1

## [Warning] 施策1: 既存 live region 内のテキストノード差し替えを `seen` で拾えない
- 判断: **対応する**
- 根拠: 指摘のとおり。Svelte が live region の中身を `{expr}` 更新で差し替えると
  `childList` の `addedNodes` は **Text ノード**になるため `collect()` (nodeType!==1 で return) を通らず、
  かつノード置換なので `characterData` も発火しない。短命メッセージだと `present_new` にも間に合わず
  **偽陰性 (取りこぼし)** になる。
- 対応内容: observer に 1 分岐を追加した。
  ```javascript
  if (r.type === "childList" && r.addedNodes.length > 0 && r.target.nodeType === 1) {
      const host = r.target.closest(LIVE);
      if (host) enqueue(host);
  }
  ```
  `addedNodes.length > 0` に限定したのは、**削除だけの childList** で host を記録すると
  「空になった live region」を変化として拾ってしまうため。`closest(LIVE)` が null を返す限り
  live region 外の DOM 変化は素通りするので、ノイズは増えない。
- **実測**: 追加後に jsdom で再検証し、新ケース L1 (テキストノード差し替え → `seen` に `visible:true`) を含む
  **18 assertion すべて PASS** (3 連続実行で安定)。施策 5 のテスト表に L1 を追加した。

## [Suggestion] 施策1: `role+testid+text` の短時間重複除去
- 判断: **見送る**
- 根拠: 判定規約は**件数を使わない** (「空か否か」と「本文が操作結果か」で判断する) ため、
  重複は判定結果に影響しない。probe の状態を増やすとテスト対象と失敗モードが増える割に
  得られるのは表示の見やすさだけで、思考原則 2 (今必要なものだけ) に反する。
  実 run で triage が読みづらいと判明した時点で足す。

## [Warning] 施策2: `installed_now:true` の一律「未検証」は H7 を恒常的に判定不能にしうる
- 判断: **対応する**
- 根拠: full-document 遷移が混ざる導線では H7 が常に未検証になり、
  誤検知を減らす代わりに**取りこぼし**に倒れる。
- 対応内容 (Codex の「肯定証拠のみ採用」を採る):
  - 判定表を書き換え、`installed_now:true` でも **`present_new` または直後の `snapshot` に
    「操作結果を伝える文言」があれば『フィードバックあり』と結論してよい**とした
    (肯定方向のみ採用。基線が無いため常駐 live region が混ざるので**陰性判断には使えない**)。
  - `H7 未検証` を **shard-report の必須集計項目**にした (Phase 4 の骨子に 1 行追加、施策 2 (e))。
    件数が run ごとに増えるなら probe 方式ではなく導線側の観測設計を見直す信号とする、と明記。

## [Suggestion] 施策2: 「操作結果を伝える文言」の判定語彙を最小辞書として明文化
- 判断: **対応する**
- 対応内容: SKILL.md 文面に最小辞書を追記した (網羅列挙ではないと明記)。
  結果として数える: 「〜しました」「保存」「作成」「更新」「削除」「送信」「招待」「変更」「コピー完了」/
  失敗系「〜できません」「失敗」「エラー」。数えない: 「読み込み中」「処理中」「Loading」等の進捗表示、
  および基線で `present_preexisting` に落ちる常駐 Alert。

## [Suggestion] 施策4: `wont_fix` の扱いを注記
- 判断: **対応する**
- 対応内容: 書式ルールの変更文面に
  「`wont_fix` は現時点で該当項目が無いため節を作らない。必要になったら
  `### wont_fix 確定 (再起票しない)` を追加する」を追記した (節の先取り追加はしない = 思考原則 2)。

## [Suggestion] 施策5: childList (Text 差し替え) 専用ケースを追加
- 判断: **対応する**
- 対応内容: テスト表に L1 を追加し 18 項目にした (実測済み)。

## [Suggestion] 施策6: 必須欄チェックを `- **項目名**:` 形式まで見る
- 判断: **対応する**
- 対応内容: 検証する不変条件 (1) に「照合はキー文字列の存在ではなく `- **{項目名}**:` の**行形式**で行う
  (本文中に同じ語が出ただけで PASS する誤検知を避ける)」を追記し、実装スケッチに `FIELD_LINE` を追加した。

## 施策3 の APPROVE
- 判断: 対応不要 (現状維持)
