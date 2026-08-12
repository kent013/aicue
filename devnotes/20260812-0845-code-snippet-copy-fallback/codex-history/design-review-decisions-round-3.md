# 対応マトリクス: design-review Round 3

全体判定 CHANGES_REQUESTED。Warning 4 / Suggestion 2。**すべて対応**(反論なし)。

## [Warning] `range.cloneRange()` が例外非送出境界の外にある

- 判断: **対応する**
- 根拠: 妥当。選択を張った**後**に `cloneRange()` が投げると、`copy()` 自体が reject し、
  案内も成功表示も出ないまま「所有権を記録していない選択」だけが残る。最悪の中間状態である。
- 対応内容: `createRange` / `selectNodeContents` / `cloneRange` を**既存選択に触る前**の
  同じ try に入れ、成功した複製 (`owned`) を `addRange` 成功後に `ownedRange` へ入れる形にした。

## [Warning] `onDestroy` の実装が変更後コードに含まれていない

- 判断: **対応する**
- 根拠: 設計判断と契約 15 が要求している処理が、実装対象コードの側に書かれていなかった
  (文章と実装の乖離)。
- 対応内容: 変更後コードに `onDestroy`(`attemptId++` / `clearOwnSelection()` /
  `clearTimeout` / `timeoutId = undefined`)を明示した。

## [Warning] M1 は契約 14 を赤くしない

- 判断: **対応する**
- 根拠: 契約 14 は最初から `window.getSelection` を null にしているので、正常実装でも
  `selectCode()` は false。`selectCode()` を常に false にしても観測結果が変わらない。
- 対応内容: M1 の予測を**契約 3 のみ**に修正し、「契約 14 は赤くならない(文面分岐の固定は
  M7 が担う)」と明記した。

## [Warning] M3 は mutation として検出不能

- 判断: **対応する**(計画から削除)
- 根拠: `typeof` ガードだけ外しても try/catch が例外を拾い、外部から観測できる振る舞いが
  変わらない。「赤くならない可能性が高い」と書いた時点で mutation の目的を満たしていない。
- 対応内容: M3 を削除し、「`typeof` ガードは防御的な早期 return であって独立した振る舞い契約
  ではない。契約 6 が外部契約を十分固定している」と整理を明記した。

## [Warning] `clearOwnSelection()` の例外非送出契約がテストされていない

- 判断: **対応する**
- 根拠: Round 2 で追加した重要な判断が、契約側に落ちていなかった。将来 try/catch が
  外れても検出できない。
- 対応内容: **契約 16「選択解除が例外を投げても legacy 成功表示は覆らない」を新設**
  (`removeAllRanges` を呼び出し回数で分岐させ、`clearOwnSelection()` のときだけ throw させる)。
  対応 mutation M15 も追加した。

## [Suggestion] 契約 15 のタイマー検査は差分で見る

- 判断: **対応する**
- 対応内容: 「総呼び出し回数を 0 と断定せず、**unmount 直前の回数からの差分が 0**」に変更した
  (Svelte / テスト環境も内部でタイマーを使いうるため)。

## [Suggestion] Range の 4 点一致は実用上妥当 / 3 箇所での解除にも問題なし

- 判断: **対応不要**(肯定的評価)
- 記録: 「利用者が偶然まったく同じ 4 境界を選び直した場合は自動選択と区別できない」という
  残留制約の指摘は受容する(同じ範囲が維持されている以上、実害は限定的)。
