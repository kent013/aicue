# 対応マトリクス: design-review Round 2

Round 2 判定: **CHANGES_REQUESTED**(Critical 0 / Warning 4 / Suggestion 1)。
Round 1 の Critical 3 件はすべて解消と認定された。残りはすべて対応した。

---

## [Warning] 施策 1a: 同一性判定から `initiated_by_user_id` が抜けている

- 判断: **対応する(ただし「足す」ではなく「足さない契約を宣言し、テストで固定する」)**
- 検証: `BillingController::startAutoRechargeSetup` L449 を実読し、
  `attempt_token` が **client 供給値**(`StartAutoRechargeSetupRequest` の validated)であることを確認した。
  したがって同一 org の別 billing 管理者が同じ token を送る経路は理屈上存在する。
- 根拠(なぜ足さないか):
  1. 握ってよい条件は「書きたかった attempt が既に在る」であり、attempt の同一性を決めるのは
     `(organization_id, intent, attempt_token)` である。`initiated_by_user_id` は
     「誰が起こしたか」の記録で、同一性を構成しない。既存行が A を指すのは**正しい**
  2. 両者とも `Gate::authorize('manageBilling', $organization)` を通過した同一 org の
     billing 管理者であり、setup checkout は org 単位の操作。cross-org でも権限昇格でもない
  3. **足すと成功時の振る舞いが変わる**。現状は actor を問わず握っている。actor 一致を
     条件に加えると、**今まで正常終了していた B の呼び出しが 500 になる**。本設計は
     「成功時の振る舞いを変えない」と宣言しており、これを破る変更はスコープ外である
- 対応内容:
  - 施策 1a に節「同一性判定に `initiated_by_user_id` を**入れない**(契約として宣言する)」を追加
  - 「保証しないもの」に **§14** を追加(「誰が起こした attempt か」を検証しない)
  - Codex の要求どおり **テスト 4**(別 actor が同 token を送っても replay として握る)を追加
  - さらに **M-2c**(actor 条件を**足す** mutation)を追加。テスト 4 のみ赤になることで
    「actor を入れない契約」が load-bearing であることを実証する
  - 概念設計の「保証しないもの」にも §1c として参照を置いた

## [Warning] 施策 1a: M-7 の復帰確認が成立しない

- 判断: **対応する(指摘は正しい)**
- 根拠: 実装後の `app/` には本設計の変更が残るため、mutation を戻しても
  `git diff --stat app/` は空にならない。基準の取り方が誤っていた。
- 対応内容: M-7 を「基準を先に固定する」手順へ書き換えた。
  (a) 実装を 1 度コミットしてから mutation する / (b) `baseline.patch` を保存して比較、
  の 2 案を示し、**実装順序では (a) を採る**と明記。実装順序の表にも
  「(6) 実装をコミット(mutation の復帰基準を固定する)」を挿入した。

## [Suggestion] M-2b は mutation から分離した方がよい

- 判断: **対応する**
- 根拠: 指摘のとおり。M-2b は「実装を壊すと赤くなる」の確認ではなく、
  テストで識別できない代替実装の比較実験である。mutation 節に置くと
  「全 mutation が kill された」という読み方と衝突する。
- 対応内容: M-2b を mutation から削除し、独立節
  **「代替実装 probe(mutation ではない)」の P-1** として切り出した。
  出力先も `mutation.txt` ではなく `alternative-probe.txt` に分けた。
  「P-1 が緑でも旧案が正しいことは意味しない」を「保証しないもの」§15 にも明記した。

## [Warning] 施策 1b の「同時に違反しえない」は絶対表現が強すぎる

- 判断: **対応する(指摘は正しい)**
- 根拠: ULID 衝突は数学的に不可能ではない。「しえない」は嘘になる。
- 対応内容: 判定方式の選択規則を
  「**通常のアプリ生成経路では期待制約以外が同時に違反を構成しない**と構造的に言える場合だけ」
  へ弱めた。加えて「絶対表現は使わない。ULID 衝突のような確率的事象までは排除できない。
  ただしその場合も報告制約が期待名と一致せず**再送出**= 安全側に倒れる」と明記した。
  施策一覧の表の 1b 行にも同じ趣旨を書いた。

## [Warning] 概念設計の「保証しないもの」§1 に exclusion 制約の古い説明が残っている

- 判断: **対応する(指摘は正しい。詳細設計だけ直して概念設計を直し忘れていた)**
- 対応内容: 概念設計の「保証しないもの」§1 を Codex の提案どおりの表現へ統一し、
  併せて §1b(複数 unique 同時違反時の報告は 1 本のみ)と §1c(actor 非検証)を追加した。

## [Warning] 実装モードの「新 const を伴わない」が自己矛盾

- 判断: **対応する(指摘は正しい)**
- 根拠: 施策 1b は `private const string ATTEMPT_ORG_PENDING_UNIQUE` を 1 本追加する。
- 対応内容: 「新モデル・新 migration・新 gate・**新しい共有抽象や制約名台帳**を伴わない
  (施策 1b が `private const string ATTEMPT_ORG_PENDING_UNIQUE` を 1 本追加するのみ)」へ訂正した。

---

## Round 2 で反論した点

**なし。** Warning 4 件・Suggestion 1 件すべてに対応した。
うち 1 件([Warning] actor)は「指摘の修正案どおりに足す」のではなく
**「足さない契約を宣言し、テストと mutation で固定する」**という形で対応した。
Codex 自身が「意図的に actor を問わない契約なら、その理由と保証範囲を明記し、
別ユーザーによる同 token のテストを追加する必要があります」と代替を認めており、
その条件(理由・保証範囲・テスト)をすべて満たしている。
