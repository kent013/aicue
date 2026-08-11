# 対応マトリクス: design-review Round 1

Round 1 判定: **CHANGES_REQUESTED**。Critical 3 件・Warning 2 件・Suggestion 2 件。
**Critical 3 件はすべて実測で裏を取り、すべて対応した**(うち 1 件は設計者の誤読の訂正)。

---

## [Critical] 施策 1a: 同一 `attempt_token` replay は 1 本の unique 制約だけに当たらない

- 判断: **対応する(指摘は正しい。設計を作り直した)**
- 検証: 推測で受け入れず、`billing_checkout_sessions` と**同じ index 構成・同じ作成順**の
  TEMP 表を作って実測した(`scratchpad/probe3.php`)。

  ```
  --- 正規 replay (3 本すべて同時に違反) ---
    試行0: 報告された制約 = bcs_stripe_session_id_unique
    試行1: 報告された制約 = bcs_stripe_session_id_unique
    試行2: 報告された制約 = bcs_stripe_session_id_unique
  --- 逆順で作った場合 (複合 unique を先に作る) ---
    報告された制約 = bcs2_org_intent_attempt_unique
  ```

  → **報告される 1 本は index の作成順(OID 昇順)で決まる**。アプリの意味論ではない。

  さらに実 DB(migrate 済み)の OID 順も照会した:

  | index | OID |
  |---|---|
  | `billing_checkout_sessions_org_intent_attempt_unique` | **91838**(unique の中で最若) |
  | `billing_checkout_sessions_stripe_session_id_unique` | 91840 |
  | `billing_checkout_sessions_idempotency_key_unique` | 91842 |

  → **今日は偶然、旧案でも動く**(Laravel の Blueprint が明示 unique を fluent unique より
  先に発行するため)。しかし migration に unique を 1 本足す・`pg_dump`/restore で
  再作成順が変わるだけで壊れる。**依存してよい契約ではない**。

- 対応内容:
  - 施策 1a を **自然キーの同一性判定**へ作り直した。
    `(organization_id, intent, attempt_token)` で既存行を読み直し、
    `stripe_session_id` / `idempotency_key` が今回の値と一致するときだけ replay として握る。
    一致しない・行が無いなら再送出(fail-closed)。const `CHECKOUT_ATTEMPT_TOKEN_UNIQUE` は廃止。
  - 施策一覧の直後に **判定方式の選択規則**を明文化した:
    「制約名で判定してよいのは『期待制約以外が同時に違反しえない』ことが構造的に言える場合だけ」。
  - 実測を **E-7** として詳細設計に追加した。
- **施策 1b は制約名判定のままとする**(反論ではなく、規則の適用結果):
  `attempt_ulid` は毎回新規 ULID、`stripe_invoice_id` は insert 時 NULL(NULL は unique に
  抵触しない)、pkey は serial。**期待制約以外が同時に違反しえない**。
  Codex も施策 1b は APPROVE している。

## [Critical] 施策 1c: 「修正前に赤くなる」前提が現行コードと矛盾する

- 判断: **対応する(指摘は正しい。設計者の誤読であり、施策ごと撤回する)**
- 検証: `app/Services/Billing/SubscriptionService.php:550-562` を実読した。

  ```php
  return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
      || (str_contains($message, 'billing_checkout_sessions.organization_id')
          && str_contains($message, 'attempt_token'));
  ```

  **制約名を照合している**。設計者は L546-556 の docblock 冒頭だけを読み、本体を読まずに
  「SQLSTATE しか見ていない / docblock が実装していない保証を宣言している」と断定した。
  **これは設計者の誤読である**。
- 対応内容:
  - **施策 1c を撤回**した。現状は既に fail-closed であり、`$e->index` へ置き換えても
    振る舞いは変わらない。「今必要なものだけ作る」(思考原則 2) に照らしてやらない。
  - 詳細設計に **E-6(誤読の訂正)** を明記した。概念設計の survey 表も訂正した
    (「見ない」→「見る(`str_contains` で index 名照合)」)。
  - R-1c / M-5 は「撤回」と明記して残した(消さずに経緯を残す)。

## [Critical] 施策 1c: 1a と同じく正規 replay が複数 unique に当たり得る

- 判断: **指摘は正しい。ただし施策 1c 自体を撤回したため、修正ではなく記録に留める**
- 根拠: `SubscriptionService` にも E-7 の脆さは残る。ただし**失敗方向は安全側**である
  (黙って飲まず 500 になる)。今日の OID 順(複合 unique が最若)では発現しない。
  T140 のスコープ外であり、「今必要なものだけ作る」に照らして本設計では直さない。
- 対応内容: 詳細設計 E-6 / E-7 と「保証しないもの」§5 に**記録のみ**として残した
  (**対処の約束ではない**と明記)。既存テスト
  `並行 race: INSERT 直前に同 token 行が割り込んでも…` が緑なのは、勝者行が
  **別 session id・別 idempotency_key** なので複合 unique 1 本しか違反しないためであり、
  この脆さの反証にならないことも明記した。

## [Warning] M-1 の mutation は「const が load-bearing」を十分に証明しない

- 判断: **対応する**
- 根拠: 指摘のとおり。旧案では replay が複数制約に当たるため、const を壊して赤くなっても
  「報告制約名にたまたま依存している」ことの証明にしかならない。
- 対応内容: 施策 1a が制約名を使わなくなったので mutation を作り直した。
  - **M-1**: 同一性検査の `stripe_session_id` 比較 1 条件だけを削除 →
    テスト 3 のみ赤 / テスト 1・2 は緑(意味論側の非対称)
  - **M-2**: catch 節の `throw` を削除(= 常に握る)→ テスト 1・3 が赤 / テスト 2 は緑
  - **M-2b**: **撤回した旧案(制約名判定)に差し替える** → 3 本とも緑。
    これは旧案が正しいことの証明ではないので、E-7 の実測と併せて
    「**テストでは差が出ないが依存してよい契約ではない**」ことを mutation ログに明記する。
    テストで守れない差を正直に記録するために実施する。

## [Warning] exclusion 制約は 23505 ではなく 23P01

- 判断: **対応する(指摘は正しい。実測で確認した)**
- 検証:
  ```
  errorInfo = [ 0 => '23P01', 1 => 7,
                2 => 'ERROR:  conflicting key value violates exclusion constraint "ex1_a_excl" ...' ]
  ```
- 対応内容: E-5 の表を書き直した。exclusion 制約は
  `PostgresConnection::isUniqueConstraintError()`(`'23505' === getCode()`)を通らないので
  **素の `QueryException` として catch 節の外へ出る**。`$index === null` の fail-closed は
  「unique 違反だが Laravel が index を抽出できなかった場合」の話であると書き分けた。
  「保証しないもの」§1 も同じ趣旨に直した。概念設計の確認 2 の表も訂正した。

## [Suggestion] 施策 1b のテストに「pending 検査後の race を model event で作る」旨を明記

- 判断: **対応する**(コスト 0)
- 対応内容: R-1b のコメントに既に
  「pending 検査の**後**・INSERT の**直前**に、**別 org**で**同じ attempt_ulid** の行を作る」
  と書いてある。加えて「別 org なので部分 unique には触れず attempt_ulid unique だけが違反する」
  という**単一制約性の根拠**を明記済み。

## [Suggestion] R-2 の `created` フックの意図をコメントで補う

- 判断: **対応する**(コスト 0)
- 対応内容: R-2 のコード内コメントに
  「service が save() した当のインスタンス」と既に書いてある。加えて
  「既存テストは `uploadReservations()->sole()` = **DB 再読込**なので DB default で緑になり
  この欠落を検出できなかった」という**なぜ既存テストで漏れたか**の説明を残した。

---

## 施策 1a を作り直した結果、テスト計画も変わった点(Round 2 で見てほしい)

- テスト 2(正規 replay が緑)が**旧案の Critical を捕まえるテスト**である。
  ただし今日の OID 順では旧案でも緑なので、**このテストだけでは旧案と新案を区別できない**。
  区別は E-7 の実測と M-2b の mutation ログで担保する。**これを正直に書いた**。
- テスト 3(既存行の session id 食い違い)を新設した。
  「行は在るが内容が違う = 台帳が壊れている」を飲まないことの固定。
- `grep -rn 'startSetupCheckout' tests/` は **0 件**であり、この経路には
  **既存テストが一切ない**ことを確認した。新規ファイルが唯一の回帰防御である旨を明記した。
