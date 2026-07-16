# 対応マトリクス: design-review Round 6（CHANGES_REQUESTED / Critical 1・Warning 1・Suggestion 1）

8/10 が APPROVE。残る Critical 1 件は**本質的な指摘**で、私の D10 が作っていた穴だった。

## [Critical] P3→P4 の無料導線が実在しない（**私の D10 が作った穴。F-07 再発の変種**）
- 判断: 対応する（指摘のとおり）
- 問題: D10 で「personal/starter は `is_active=false` で seed、再公開は P8b」と決めたため、**P4 のゲート反転時点で Personal が
  非公開**（eligibility は null、POST は 404）となり、**未契約者は Standard(有償)しか選べない** = F-07 の変種が再発する。
  「P4 rollout 前に true」というリスク欄の記述も、P4 本文・migration・依存順と矛盾していた。
- 対応: **D10 を改訂**し、公開タイミングをプラン別に分離:
  - **Personal は P3 で `is_active=true`**（data migration `activate_personal_plan`）。根拠: **Personal の「購入導線」は
    `activate-personal` そのもの**であり P3 で揃う。ここで公開して初めて **P4 の反転時に無料導線が実在**する。
  - **Starter は P8b のまま**（有償のため checkout UI が要る）。
  - P3 の DoD/変更一覧に migration・**末尾の 1 件アサート**・rollback（`down()` で false。**P4 より前なので単独 revert が安全**）・
    テスト（`PersonalPlanPublishedTest`: `/pricing` に Personal が出る / Starter は出ない / `personalEligibility` が null にならない）を追加。
  - **P4 の DoD に前提条件を明記**: 「P3 の Personal 公開 migration が適用済みで `is_active=true` であること」。
    デプロイ順序を `列/index → backfill 完了・件数検証 → **Personal 公開の確認** → ゲートコード deploy` へ更新。

## [Warning] P6 に `TicketLedgerService` のシグネチャ変更が重複
- 判断: 対応する / 対応: 変更表を「**P1 で変更済み → コード変更なし・prefix 契約の回帰確認のみ**」へ。PHPStan 節の「追加引数」表現も削除。

## [Suggestion] P7 末尾の未決事項に解決済み項目が残る
- 判断: 対応する / 対応: 見出しを「**すべて解決済み**（下記は履歴。正は冒頭 §横断決定 D1-D27）」へ変更し、
  Enterprise case・Welcome CTA 所管が D1/D2/D16 および P7 本文と矛盾して見えないようにした。
