# 詳細設計レビュー Round 4 (deferred-robustness)

Round 3 の Warning 2 件 / Suggestion 1 件すべてに対応しました。**反論はありません。**
とくに施策 1b の「常に安全側」という過大な保証は、自分で立てた E-7 と矛盾していたため全面撤回しました。

---

## 対応マトリクス (Round 3)

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


---

## 修正した箇所 (差分の該当部分)

### 判定方式の選択規則 + 施策一覧
```
## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1a | `startSetupCheckout()` の unique 握りを**自然キーの同一性**で絞る | `app/Services/Billing/AutoRechargeService.php` | High |
| 1b | `maybeCreateAttempt()` の unique 握りを**制約名**で絞る + `isUniqueViolation()` 削除 | `app/Services/Billing/AutoRechargeService.php` | High |
| ~~1c~~ | ~~`SubscriptionService`~~ — **撤回**(下記 E-6。設計者の誤読だった) | — | — |
| 2 | `TakeUploadService::issue()` の初期 `status` を明示代入 | `app/Services/Capture/TakeUploadService.php` | Medium |
| 3 | 契約の文書化(AGENTS.md ドメイン規約 2 / docs/architecture.md) | `AGENTS.md` / `docs/architecture.md` | Low |

**新規モデルなし** = Factory 追加は不要。**インターフェース変更なし** = TypeScript / Inertia Props /
JsonResource への波及なし(各施策の「波及変更」欄で個別に宣言する)。

### 施策 1a と 1b で直し方が違う理由 —— 判定方式の選択規則

Codex 詳細レビュー Round 1 の [Critical] を受け、**制約名で判定してよい条件を実測で確定した**
(E-7)。導かれた規則:

> **制約名(`$e->index`)で判定してよいのは、「通常のアプリ生成経路では期待制約以外が同時に
> 違反を構成しない」と構造的に言える場合だけである。言えないなら、自然キーで既存行を
> 読み直して同一性を確認する。**
>
> (「同時に違反しえない」という絶対表現は使わない。ULID 衝突のような確率的事象までは
> 排除できないため。**そして同時違反が起きた場合に安全側へ倒れる保証もない** ——
> 報告される 1 本は index 順で決まるので、`tar_attempts_org_pending_unique` が報告されれば
> 別異常を並行 race として握ってしまう。残留リスクとして「保証しないもの」§3 に明記する。)

| 施策 | 期待制約以外が同時に違反しうるか | 採る判定 |
|---|---|---|
| 1a `startSetupCheckout` | **する**。正規 replay では `org_intent_attempt` / `idempotency_key` / `stripe_session_id` の **3 本が同時に**違反する | **自然キーの同一性**(制約名を使わない) |
| 1b `maybeCreateAttempt` | **通常のアプリ生成経路では構成しない**。`attempt_ulid` は毎回新規 ULID、`stripe_invoice_id` は insert 時 NULL(NULL は unique に抵触しない)、pkey は serial。**ただし ULID 衝突等で同時違反が起きた場合の挙動は保証しない**(「保証しないもの」§3) | **制約名** |

---

## 前提の実測(設計の土台。推測を含まない)

### E-1. PostgreSQL の例外から制約名は取れる。ただし構造化フィールドではない
```

### 施策 1b リスク欄
```
- **残留リスク(保証しない)**: 複数の unique が同時に違反した場合、報告される 1 本は
  index 順で決まるため、`tar_attempts_org_pending_unique` が報告されれば別異常を
  no-op として握りうる。通常のアプリ生成経路では同時違反を構成しない(ULID は毎回新規、
  `stripe_invoice_id` は NULL、pkey は serial)が、**「常に安全側」とは言えない**。
  「保証しないもの」§3 に明記する。確率的に極めて小さいため自然キー再照合は導入しない

---
### リスク
- **挙動不変**。DB に入る値は同じ、API 応答も同じ、`bytes_pending` 集計も同じ。
```

### 保証しないもの (全文)
```
## 保証しないもの(実装後も成立しない事柄。先に宣言する)

1. **`$e->index` が常に取れることは保証しない**。sqlite では常に `null`、
   pgsql でも翻訳ロケールでは `null` になりうる。その場合の挙動は**握らず再送出**
   (fail-closed)だけで、**識別はできない**。「どの制約に当たったか」がログに残るのは
   例外メッセージ本文としてであって、構造化フィールドとしてではない
   (exclusion 制約は `23P01` なのでそもそも `UniqueConstraintViolationException` にならない。E-5)
2. **`$e->index` が「違反した全制約」を表すことは保証しない**。**複数の unique が同時に
   違反したとき PostgreSQL が報告するのは 1 本だけ**であり、どれになるかは index の
   OID 順(= 作成順)で決まる(E-7 の実測)。施策 1b が制約名判定を採れるのは
   「期待制約以外が同時に違反しえない」構造だからであって、一般則ではない
3. **施策 1b は「通常のアプリ生成経路では別制約との同時違反を構成しない」ことを前提とする。
   前提が崩れたときに安全側へ倒れる保証はない**。
   ULID 衝突・sequence drift 等で複数制約が同時に違反した場合、報告される 1 本は
   index 順で決まる(§2)。**報告制約が `tar_attempts_org_pending_unique` になれば、
   別異常を並行 race として no-op で握る可能性を排除しない**。
   確率的に極めて小さい残留リスクであり、自然キー再照合や新 gate は追加しない
   (思考原則 2)。また `ticket_auto_recharge_attempts` に「insert 時点で値が確定していて
   衝突しうる unique」を将来足すとこの前提は崩れるが、**その検出は静的にはできない**
   (足した人が本設計を読む保証はない)
4. **制約名の drift を静的に検出しない**。PostgreSQL は識別子を 63 バイトで黙って切る
   (実 DB に `take_upload_reservations_organization_id_status_expires_at_inde` が実在する)。
   本設計が持つのは「名前がずれたら behavioral テストが赤くなる」という**事後検出だけ**である。
   `pg_indexes` を照合する専用 gate は作らない(思考原則 2)
5. **`SubscriptionService` に残る同型の脆さは直さない**(E-6 / E-7)。
   正規 replay で報告される制約が index 順の変化でずれると 500 になりうる。
   **失敗方向は安全側**であり今日の OID 順では発現しないため、記録のみとする
   (対処の約束ではない)
6. **「app 全体で unique 違反の握り潰しが無い」ことは保証しない**。
   概念設計の survey は本設計時点の手動走査であり、**静的 gate は作らない**。
   新しい握り潰しはレビューで見るしかない
7. **横断的な「既定値依存を禁止する gate」は新設しない**。aicue:T151 の設計が
   「判定式が静的に書けず偽陽性で gate の信用を落とす」として却下済み。蒸し返さない。
   したがって**施策 2 と同型の既定値依存が他に無いことは保証しない**
8. **孤児 Stripe session そのものは防げない**。Stripe session 作成は DB insert より前の
   外部 I/O なので、insert が落ちれば孤児 session は残る。本設計が変えるのは
   **「その状態が正常終了として扱われること」だけ**である
9. **これは観測可能性の改善であって UX 改善ではない**。ユーザー向けエラー文言は
   追加も変更もしない。これまで黙って通っていた障害が 500 相当で表面化する
10. **施策 2 は現在の外部挙動を何も変えない**。DB に入る値も API 応答も `bytes_pending` 集計も同じ。
   守るのは「この経路の意味が migration default に依存しない」ことだけ
11. **`AutoRechargeTriggerJob` の structured no-op の語彙は変えない**。
   変えるのは「no-op に収束させる条件」だけ
12. **並行起票そのものを減らさない**。DB 制約が最終防衛である構造 (aicue:T137/T148) は不変
13. **`migration` の `default` は消さない**。既存行と Factory 以外の INSERT 経路のために残す
14. **施策 1a の同一性判定は「誰が起こした attempt か」を検証しない**。
    同一 org の別 billing 管理者が同じ `attempt_token`(client 供給値)を送った場合、
    先行ユーザーの行を replay として握り、**後続ユーザーは先行ユーザーが作った
    Stripe session へ送られる**。cross-org でも権限昇格でもなく、現状の振る舞いと同じである。
    attribution を検証したい要求が出たら、同一性判定ではなく**別の要件**として設計する
15. **`alternative-probe.txt` の P-1 が緑でも、旧案が正しいことは意味しない**。
    テスト 4 本では旧案と新案を区別できないという**限界の記録**である

---
```

### 実装モード + 実装順序 (全文)
```
## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は **2 ファイル・計 3 箇所**の小さな置換で、新モデル・新 migration・新 gate・**新しい共有抽象や制約名台帳**を伴わない(施策 1b が `private const string ATTEMPT_ORG_PENDING_UNIQUE` を 1 本追加するのみ)。DB スキーマも API 形も変えないので main への追従コストが低い。施策 1a / 1b / 2 は互いに独立しており、片方だけ先に入っても他方は壊れない |
| 競合リスク | `AutoRechargeService.php` は auto-recharge 系 TODO と競合しうる(直近では aicue:T137/T140 が触っている)。ただし本変更は catch 節 2 個と private メソッド 1 個の削除に閉じるため衝突しても解決は容易。`TakeUploadService.php` は撮影 PWA 系の TODO と競合しうるが、変更は `forceFill` の 1 キー追加のみ。**`SubscriptionService.php` には触らない**(施策 1c 撤回) |
| 実装順序 | 下表のとおり(Codex Round 3 [Warning] を反映。**検証を済ませてから基準コミットを打つ**) |

### 実装順序(確定)

1. 全再現テスト(R-1a テスト 1〜4 / R-1b / R-2)を書き、**赤を実測して `red-before-fix.txt` に残す**
2. 施策 1a → 1b → 2 → 3(文書)を実装する
3. **`composer fix && composer phpstan && composer test` を通す**(全 green)
4. **基準コミットを打つ**(mutation の復帰基準。`composer fix` の差分もここに含める)
5. mutation **M-1 / M-2 / M-2c / M-3 / M-4 / M-6** を**1 箇所ずつ**実施し `mutation.txt` に残す
6. 代替実装 probe **P-1** を実施し `alternative-probe.txt` に残す
7. **M-7**: すべて戻し `git diff --stat app/` が**空**(= 基準コミットと同一)であることを確認
8. 最終確認: `composer phpstan && composer test`

**`composer fix` をコミット後に走らせない**(差分が出て基準がずれる)。
```

### M-7
```
### M-7: 復帰確認(基準は「全 green を通した後の基準コミット」)

**`git diff --stat app/` が空になることを基準にできるのは、実装順序 3〜4
(全 green → 基準コミット)を先に済ませている場合だけである**(Codex Round 2/3 [Warning])。

手順:

1. 実装順序 3 で `composer fix && composer phpstan && composer test` を通す
2. 実装順序 4 で基準コミットを打つ(`composer fix` の差分もここに含める)
3. 各 mutation / probe のあと、対象ファイルを戻す
4. 全部戻した後 `git diff --stat app/` が**空**であることを確認する
5. `composer phpstan && composer test` が緑であることを確認する

**`composer fix` を基準コミットより後に走らせない**(差分が出て基準がずれる)。

### 全体の最終確認
```

### 施策 1a テスト計画 (テスト 4 の記述)
```
### テスト計画
- [x] バグ修正のため**再現テストを先に書く**(下記「赤化手順 R-1a」)
- [ ] 新規 `tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php`
  1. `別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する`
     — **修正前は赤**
  2. `同一 attempt_token の replay は例外を漏らさず結果を返し行も増えない`
     — **修正前も後も緑**(成功時の振る舞いを変えていないことの固定)
  3. `既存行の stripe_session_id が今回の値と食い違うなら replay として握らない`
     — **修正前は赤**。「行は在るが内容が違う」= 台帳が壊れている状態を飲まないことの固定
  4. `同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る (actor は問わない)`
     — **修正前も後も緑**。actor を同一性判定に入れない契約の固定
     (入れてしまうと赤くなるので、契約が load-bearing であることも同時に示す)。
     **2 人目には対象 org の `manageBilling` を実際に付与し、Controller 経由
     (`POST /billing/auto-recharge/setup`)で叩く**。Service 直呼びでも同一性契約は検査できるが、
     「両者とも認可済み」という**設計根拠そのもの**は Controller 経由でないと固定できない
     (Codex Round 3 [Suggestion])
- [x] **`startSetupCheckout` を叩く既存テストはリポジトリに存在しない**
      (`grep -rn 'startSetupCheckout' tests/` は 0 件)。したがってテスト 2 は
      **replay 経路の初めての固定**であり、施策 1a の回帰防御はこの新規ファイルだけが担う
- [x] 個別の `DatabaseTransactions` を使わない

### リスク
### テスト計画
- [x] 再現テストを先に書く(下記「赤化手順 R-1b」)
- [ ] `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php` に追記:
  - `別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する` — **修正前は赤**
- [x] 既存 3 テスト(pending 検査 / DB 制約が最終防衛 / unique violation の no-op 収束)は
      **すべて緑のまま**であること = 期待制約側の振る舞いを変えていない証拠
- [x] 個別の `DatabaseTransactions` を使わない

```

---

判定を出してください。残る指摘が Suggestion のみなら **APPROVED** としてください。
