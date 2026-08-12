# 対応マトリクス: design-review Round 2

全体判定 CHANGES_REQUESTED。Critical 1 / Warning 6 / Suggestion 2。**すべて対応**(反論なし)。

## [Critical] 所有権判定が不十分 (同じ code 内の部分選択を奪う)

- 判断: **対応する**
- 根拠: 妥当。`codeEl.contains(commonAncestorContainer)` は、利用者が同じ `<code>` の
  一部だけを選び直した場合も true になる = 利用者の選択を破棄する。契約 12 (別要素) では
  この穴を検出できない、という指摘も正しい。
- 対応内容: `ownedRange = range.cloneRange()` を保持し、
  `startContainer` / `startOffset` / `endContainer` / `endOffset` の **4 点完全一致**でのみ畳む。
  契約 13 (同じ code 内の部分選択でも奪わない) を新設し、mutation M12 (判定を contains へ弱める)
  で契約 13 だけが赤くなることを実測対象にした。

## [Warning] 再試行開始時に前回の選択が残る

- 判断: **対応する**
- 根拠: 不変条件「選択が残っているのは手動コピーを促しているときだけ」に反する。
- 対応内容: `copy()` 冒頭で `clearOwnSelection()` を呼ぶ。契約 9 を
  「案内も**選択も**残らない」に強化し、契約 8 にも「選択が残らない」を追加。
  mutation M5 (冒頭の解除を削除) を新設。

## [Warning] unmount 後に保留中の試行が状態更新・タイマー登録できる

- 判断: **対応する**
- 対応内容: `onDestroy` で `attemptId++` して保留試行を無効化し、`timeoutId` も
  `undefined` に戻す。契約 15 (保留中に unmount → 解決してもタイマーを登録しない。
  `setTimeout` を spy) を新設し、mutation M10 で赤化を実測する。

## [Warning] `clearOwnSelection()` の失敗が legacy コピー成功を失敗扱いにしうる

- 判断: **対応する**
- 根拠: 後処理の失敗がコピーの成否を覆すのは誤り。
- 対応内容: `clearOwnSelection()` を**例外非送出**にし、所有状態 (`ownedRange = null`) を
  Selection 操作より**先に**手放す形にした。

## [Warning] M2 は修正後も契約 6 を赤くしない

- 判断: **対応する**
- 根拠: そのとおり。`typeof` ガードがある限り、契約 6 では例外自体が起きない。
- 対応内容: **契約 7「`execCommand` が例外を投げても案内へ落ちる」を新設**(throwing stub)。
  M2 (try/catch 除去) は契約 7 を、M3 (`typeof` ガード除去) は契約 6 を狙う形に分離し、
  それぞれ「相方は赤くならない」ことも実測対象として明記した。

## [Warning] M8 は所有権の実装によっては契約 11 だけでは不十分

- 判断: **対応する**
- 対応内容: mutation を M11 (所有判定を完全に外す) と M12 (Range 完全一致を contains へ弱める) の
  2 種に分割し、それぞれ契約 12・13 / 契約 13 のみ、と予測を分けた。

## [Warning] テスト本数の記載が文書内で食い違っている

- 判断: **対応する**
- 対応内容: 「契約 15 件とテストケースを 1 対 1」「既存 3 本無変更 + 既存 2 本を契約 3/5 へ更新 +
  新規 13 本 = ファイル全体 18 本」と数え方を明示し、施策一覧の記載も揃えた。
  fail 先行の記述も「未実装の契約が期待どおり赤になることを記録する」形に改めた。

## [Suggestion] 「案内の解除は 3 つ」を表示状態と所有 Selection に分けて書け

- 判断: **対応する**
- 対応内容: 設計判断に**解除の表**を置き、「表示状態」と「所有 Selection」を別の不変条件として
  記述した。案内 DOM は component 破棄で自然に消えるため `onDestroy` で要るのは Selection だけ、
  という区別も明記。

## [Suggestion] 保留 Promise テストの実装上の注意

- 判断: **対応する**
- 対応内容: 「手動 deferred を使う」「`act()` で DOM 更新だけ flush し保留 Promise を await しない」
  「契約 10 は呼び出し回数ごとに別 Promise を返す mock」「各 deferred は必ず解決させ未処理
  rejection を残さない」「契約 12 の外部選択対象は `document.body` 直下」をテスト実装上の注意として
  設計へ書き込んだ。
