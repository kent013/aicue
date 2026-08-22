# 対応マトリクス: design-review Round 3

Codex 判定: CHANGES_REQUESTED (gpt-5.6-sol / high)。施策別 APPROVE 8 / REQUEST_CHANGES 3。
**Critical 0 / Warning 3**。すべて対応した。

## [Warning] 施策 4: AC-14 自身が正例・負例の命名規約と一致しない

- 判断: **対応する**
- 根拠: 指摘が正確。`test_ac_14_invariant_partition_is_total` 等には `accepts` も `rejects` も無く、
  `SUBJECT_TO_TESTS["AC-14"]` へ登録すると自分の規約で落ちる。
  かといって AC-14 を対応表から外すと「各主題に正例・負例がある」という設計上の主張と食い違う。
  さらに、定数をそのまま assert するだけでは**検出分岐そのもの**が裏取りされていなかった。
- 対応内容: 判定を**入力付きの純関数 `partition_violations()` へ抽出**し、
  実データを渡す正例 2 本 + 合成入力を渡す負例 5 本に組み替えた。
  - 正例: `test_ac_14_accepts_complete_partition` / `test_ac_14_accepts_explicit_subject_to_test_mapping`
  - 負例: `test_ac_14_rejects_missing_invariant`（I 群の丸ごと欠落が実例）/
    `..._rejects_duplicate_classification` / `..._rejects_adopted_without_bearer` /
    `..._rejects_unknown_bearer_id` / `..._rejects_wrong_total`
  これで AC-14 も他の主題と同じ規約に収まり、かつ検出分岐が負例で固定される。

## [Warning] 施策 7: `not_applicable` の説明が未採用の D6 と矛盾

- 判断: **対応する**
- 根拠: 指摘が正確。「実走しないカードが route を消化することにはならない」と書いたが、
  実走除外の契約 (D6) は本作業では**未採用**であり、現行の SKILL.md はそれを保証しない。
  割当母集団から外す設計自体は妥当だが、理由の述べ方が誤っていた。
- 対応内容: 理由を書き直した —
  「`not_applicable` のカードは F2 により `## 手順` 節を持たない。手順が無いカードは
  coverage の消化カードとして数えるべきではないので、割当の母集団から外す。
  実走対象から除外されること自体は D6 のとおり未採用であり、現在該当カードは 0 枚である。」
  あわせて全数対応表の I1 も「1 枚以上の **`applicable` な**カードに載る」へ直し、
  生成器の仕様と一致させた。

## [Warning] 施策 11: S7 追加 route の変換前割当が固定されていない

- 判断: **対応する**
- 根拠: 指摘が正確。「変換後のみが期待リストと一致」だけでは
  `before: []` → `after: [S7]`（元から誰も消化していない route に S7 だけを足す）が通ってしまう。
  設計が意図しているのは `after == before ∪ {S7}` であり、`before` が空でないことが前提である。
- 対応内容:
  - **`EXPECTED_S7_PRIOR_SCREENS`（11 件）と `EXPECTED_S7_PRIOR_OPERATIONS`（9 件）**を新設し、
    route ごとの**変換前集合**（`{S3}` または `{S4}`）を固定した（実測値から起こした）。
  - 判定条件を 3 つから 4 つへ増やし、
    「各 route について `before == EXPECTED_S7_PRIOR_*[route]` かつ `after == before | {"S7"}`」を足した。
  - 11 画面の**選定根拠**を境界の種別で分類した表を検算資料へ残すことにした
    （`projects.show` / `projects.edit` は nested child ではなく project 自身の current-org 境界である、
    という点が「全 nested screen」という散文とずれて見えるため）。

## 指摘外の確認事項

- 施策 7 の `終` の consumer 棚卸しは「漏れは見当たらない」と確認を得た。
  実装時に `KUBUN_NEEDS_REASON` が reason 検査以外に残っていないことを最終確認する旨は、
  設計の棚卸し表がそのままチェックリストになる。
- 施策 5 の PHPStan level 10 適合は Round 2 で「明確な問題は無い」と確認済み。
