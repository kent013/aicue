# 対応マトリクス: design-review Round 3

Round 3 判定: **CHANGES_REQUESTED**(Critical 0 / Warning 2 / Suggestion 1)。
施策 1a・1c・2 は APPROVE。**反論はなし。2 件の Warning はどちらも設計者の誤りであり、全面的に受け入れた。**

---

## [Warning] 施策 1b: ULID 同時違反時に「必ず再送出される」とは言えない(E-7 と自己矛盾)

- 判断: **対応する(指摘が正しい。設計者の自己矛盾だった)**
- 根拠: Round 2 で「ULID 衝突が起きても pg は `attempt_ulid_unique` 側を報告して再送出される
  (安全側)」と書いた。**これは自分で立てた E-7 の結論(報告される 1 本は index 順で決まり、
  意味論として保証できない)と矛盾する**。今日の OID 順(`attempt_ulid_unique`=91897 <
  `tar_attempts_org_pending_unique`=91901)ではたまたま安全側になるだけで、
  再作成順が変われば pending unique が報告され、**別異常を並行 race として握りうる**。
- 対応内容:
  - 判定方式の選択規則の括弧書きから「その場合も報告制約が期待名と一致せず再送出=安全側」を削除し、
    **「同時違反が起きた場合に安全側へ倒れる保証もない」**と書き直した
  - 施策一覧の 1b 行を「**ただし ULID 衝突等で同時違反が起きた場合の挙動は保証しない**」へ訂正
  - 「保証しないもの」§3 を Codex の提案どおりの趣旨へ全面書き換え
    (「報告制約が pending unique になると別異常を no-op として握る可能性までは排除しない」)
  - 施策 1b の「リスク」欄にも**残留リスク**として明記した
  - **自然キー再照合や新 gate は追加しない**(確率的に極めて小さく、思考原則 2)。
    Codex も「追加する必要まではない」と述べている

## [Warning] 施策 3: mutation 基準コミットが検証前に置かれている

- 判断: **対応する(指摘が正しい)**
- 根拠: Round 2 の修正で「(6) 実装をコミット → …… → (10) `composer fix && composer phpstan && composer test`」
  という順にしてしまい、**AGENTS.md の「全 green でコミット」と整合しない**。
  さらに `composer fix`(Pint)をコミット後に走らせると差分が出て、
  mutation の復帰基準そのものがずれる。
- 対応内容: 実装順序を Codex の提案どおり 8 手順の表へ書き直した。
  1. fail-first → 2. 実装 → **3. `composer fix && composer phpstan && composer test` で全 green**
  → 4. 基準コミット → 5. mutation → 6. probe → 7. M-7(`git diff --stat app/` が空)
  → 8. 最終 `composer phpstan && composer test`。
  M-7 の節にも「`composer fix` を基準コミットより後に走らせない」を明記した。

## [Suggestion] テスト 4 は 2 人目に `manageBilling` を付与し Controller 経由で叩く

- 判断: **対応する**
- 根拠: 妥当。Service 直呼びでは「両者とも認可済み」という**設計根拠そのもの**が検査されない。
  actor を同一性判定から外す理由の 2 番目が「両者とも `manageBilling` 保持者」なので、
  そこを固定しないと根拠が宙に浮く。
- 対応内容: テスト 4 の記述に
  「**2 人目には対象 org の `manageBilling` を実際に付与し、Controller 経由
  (`POST /billing/auto-recharge/setup`)で叩く**」を明記した。

---

## Round 3 で反論した点

**なし。**
