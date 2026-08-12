# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical 1 / Warning 7 / Suggestion 3。**すべて対応**(反論なし)。

## [Critical] component 破棄時に追加コード不要という前提は危険

- 判断: **対応する**(当初案を撤回)
- 根拠: 「DOM が消えれば Selection も畳まれる」はブラウザ差を含めて言い切れない。
  detached node を指す Selection が残りうる、という指摘は妥当。
- 対応内容: `onDestroy` で `clearOwnSelection()` を呼ぶ。ただし**無条件の
  `removeAllRanges()` にはしない** — `selectionOwned` フラグと
  「現在の選択が自分の `codeEl` を指しているか」の 2 条件を満たすときだけ畳む
  (利用者がその後に別の場所を選択していたら奪わない)。契約 10 / 11 とテストで固定する。

## [Warning] `removeAllRanges()` の副作用説明が不正確 (legacy 成功時にも奪う)

- 判断: **対応する**
- 対応内容: (a) リスク欄の「失敗時にしか起きない」を削除し、**legacy 成功時にも起きる**と
  正しく書き直した。(b) 加えて **legacy 成功時は自分が張った選択を畳む**設計にした。
  これで「選択が残っているのは手動コピーを促しているときだけ」という不変条件が 1 本で言える。
  契約 2 で固定する。

## [Warning] `addRange` が投げると既存選択だけ失われる

- 判断: **対応する**(ただし完全には塞がない。正直に残留リスクとして書く)
- 対応内容: range 構築 (`createRange` + `selectNodeContents`) を
  `removeAllRanges()` **より前**に済ませる順序へ変更し、そこで失敗した場合は既存選択に触らない。
  `removeAllRanges()` 後の `addRange()` が投げる窓は塞げないため、
  **残留リスクとして明記**した (塞ぐには旧選択の退避・復元機構がもう 1 つ要る = 思考原則 2)。

## [Warning] 連打・遅延解決の競合

- 判断: **対応する**
- 対応内容: `attemptId` の単調増加を導入し、`await` 後に最新試行でなければ状態を更新しない。
  契約 9 とテストで固定し、mutation M7 で赤化を実測する。

## [Warning] mutation M2 は赤くならない可能性が高い

- 判断: **対応する**
- 根拠: そのとおり。`typeof` 検査を外しても `try/catch` が `undefined is not a function` を
  拾うため案内へ落ちる = テストは緑のまま。
- 対応内容: M2 を「**try/catch を外す**」に変更し、あわせて
  「`typeof` 検査だけ外しても赤くならないこと」も実測して記録する、とした。

## [Warning] mutation M3 の予測が弱い

- 判断: **対応する**
- 根拠: `status = "idle"` を消しても成功時は `markCopied()` が上書きするので契約 7 は緑のまま。
- 対応内容: M3 の予測を「**契約 8 (再試行中の中間状態) のみ**」に修正し、契約 7 が
  赤くならないことも実測対象にした。契約 8 は **解決を保留した Promise** で await 中を観測する。

## [Warning] mutation の「何本赤くなるか」は厳密でない

- 判断: **対応する**
- 対応内容: mutation 計画の見出しに「**赤くなる本数を完了条件にしない**」と明記し、
  各行を「最低これが赤くなるはず」に書き換えた。

## [Warning] jsdom の Selection / execCommand はグローバル状態でテスト間リークする

- 判断: **対応する**
- 対応内容: `afterEach` に `window.getSelection()?.removeAllRanges()` /
  `Reflect.deleteProperty(document, "execCommand")` / `vi.restoreAllMocks()` を追加した。

## [Warning] 破棄時の selection 解除テストが無い

- 判断: **対応する**
- 対応内容: 契約 10 (unmount で自分の選択を畳む) と契約 11 (利用者の別選択は奪わない) を新設。

## [Suggestion] `execCommand` が「その選択で」呼ばれることまで固定せよ

- 判断: **対応する**
- 対応内容: 契約 1 を「stub の**中で**、その時点の選択が code を指していることを assert する」形にした
  (呼び出し後に見るだけでは順序を固定できないため)。

## [Suggestion] 「スマートフォンでは長押し」は実機未確認の割に強い

- 判断: **対応する**
- 対応内容: 文面を「(スマートフォンでは端末のコピー操作)」に変更した。

## [Suggestion] 4 値 enum は妥当 / DESIGN.md・Atomic Design・セキュリティに問題なし

- 判断: **対応不要**(肯定的評価)
