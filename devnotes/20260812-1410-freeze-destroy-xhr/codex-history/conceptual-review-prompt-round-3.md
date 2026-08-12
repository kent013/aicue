# Round 3: Round 2 指摘への対応

Warning 2 件を対応しました (順序の決定を理由つきで明記 / 監査 metadata を 3 項目へ拡張)。
反論はありません。APPROVED にできるか確認してください。

---

# 対応マトリクス: conceptual-review Round 2

判定 CHANGES_REQUESTED。Critical 0 / Warning 2。**両方対応**(反論なし)。

## [Warning] 契約 4 が仕様決定になっていない (現実装の追認は priority の偶然を契約化する)

- 判断: **対応する**
- 根拠: 妥当。「どちらであれ固定する」は仕様を決めていない。
- 対応内容: 「**順序の決定**」節を新設し、
  **凍結中の即時削除は recent-auth の有無にかかわらず 409** と決めた。理由は
  (a) 凍結状態を知るのは本人で `/settings` に既に表示しており**秘匿すべき相手がいない**、
  (b) **再認証させてから断るのは体験として悪い**、の 2 つ。
  「現在の group/route 順と一致する」ことは**根拠ではなく確認**として書き、
  実行順が変わっても 409 が正であることを契約テストで固定する、とした。

## [Warning] `deletion_requested` だけでは「どうやって通ったか」に到達できない

- 判断: **対応する**
- 根拠: 実査したところ `security_audit_events` は
  `user_id` / `event_type` / `metadata` / `ip_address` / `occurred_at` しか持たず、
  **route 名も HTTP メソッドも残っていない**。
- 対応内容: metadata に **`deletion_requested` / `route` / `method`** の 3 つを載せる形へ拡張した
  (いずれも PII ではない)。既存テーブルが何を持っているかも設計に明記した。


---

## 改訂後の概念設計 (全文)

# 概念設計: freeze-destroy-xhr (凍結中の即時削除、XHR 経路の観測ギャップ)

> bug-hunt run 20260812-100645 の **F-4-Q1 (要確認 / needs_spec)** 起点。

## 背景・課題

### 何が観測されたか

探索エージェントが、退会予約 (凍結) 中の `member-personal@example.com` で
`settings.account.destroy` (即時削除) へ**直 DELETE** したところ、**アカウントが実際に削除**され
ログイン不能になった (DB users 11→10)。

**ただし再現しなかった。** 同一条件を別アカウントで 2 回クリーン再現したところ、
**いずれも設計どおり遮断された**。エージェント自身も「ブラウザナビゲーションと
同一タブ内 fetch の競合、または手法上のアーティファクト」と見立てている。

### 実コードを読んで分かったこと

凍結は `EnsureAccountNotPendingDeletion` middleware が担い、`settings.account.destroy` は
**allowlist (`AccountDeletionFreezeAllowance`) に入っていない** = 遮断対象である
(猶予の迂回口を作らないため意図的に除外されている)。route も
`['auth','verified','not-pending-deletion']` group の内側にある。

**そして遮断は既に Feature テストで固定されている** —
`AccountDeletionFreezeTest` の「予約中は即時削除が遮断され、取消してからなら削除できる」。

### では何が足りないのか (これが本 TODO の主題)

**既存テストが叩いているのは HTML 経路だけ**である:

```php
$this->actingAs($owner)->withSession(freshRecentAuthSession())
    ->delete('/settings/account')
    ->assertRedirect('/settings');   // ← 302 (HTML 経路)
```

middleware は `expectsJson()` のとき **409 を返す別の分岐**を持つが、
その分岐を `settings.account.destroy` で通したテストは**無い**。
JSON 409 のテストは `getJson('/dashboard')` (GET・別 route) で代用されている。

**つまり探索エージェントが使った経路 (XHR/JSON の DELETE) には、
遮断を固定するテストが 1 本も無かった。** 再現しなかったことは無罪証明ではない
(実データが消えた観測が 1 件ある以上、観測ギャップを放置しない)。

## 改善アイデア

**(A) その経路を機械で固定し、(B) 次に起きたときの証拠を残す。**

### (A) 契約をテストで固定する

固定するのは次の 6 つ (Codex Round 1 [Warning] の漏れ指摘を反映):

1. **XHR/JSON の DELETE で 409 が返り、ユーザーが消えていない**こと
   (= 探索エージェントが通った経路そのもの)
2. **recent-auth を満たしていても遮断される**こと
   (step-up を通過しても凍結が優先される = 迂回口にならない)
3. **凍結中に即時削除を試みた後、取消 → 削除ができる**こと
   (遮断が「壊す」のではなく「順序を強制する」だけであること)
4. **recent-auth の有無にかかわらず 409** であること (下記「順序の決定」)
5. **対象 user が実在し続ける**ことを、件数ではなく **その user の `exists`** で見る
6. **`AccountDeletionFreezeAllowance` に `settings.account.destroy` が入っていない**ことの
   deny-by-default な固定 (allowlist へ足した瞬間に赤くなる)。
   あわせて **2FA 必須組織 × 凍結中**でも迂回されないことを 1 本見る

### 順序の決定: 凍結が step-up より先 (Codex Round 2 [Warning])

「現実装がどちらでも、それを追認する」は**middleware priority の偶然を契約にする**危険がある。
先に**あるべき順序を決める**:

> **凍結中の即時削除は、recent-auth の有無にかかわらず 409 を返す** (凍結が先)。

理由:

- **秘匿すべき相手がいない。** 凍結状態を知るのは**本人**であり、本人には既に
  `/settings` で「退会を予約しています」と表示している。step-up を先に課して隠す意味が無い。
- **再認証させてから断るのは体験として悪い。** パスワード等を入力させた末に
  「いまはできません」と返すのは、利用者の時間を無駄に使う。
- 実装上も group middleware (`not-pending-deletion`) が route middleware (`recent-auth`) より
  先に走る現在の構造と一致する。**ただし「一致しているから正しい」ではなく、
  上の 2 つが理由である** (実行順が変わっても 409 が正、と契約テストで固定する)。

### (B) 次に起きたときの証拠を残す (Codex Round 1 [Critical])

テスト追加だけでは「実データが 1 件消えた」観測への手当てとして弱い。
**原因が特定できていないなら、次に起きたときに何が起きたか分かる形にしておく**必要がある。

幸い口は既にある — `OrganizationMembershipService::deleteAccount()` は削除直前に
`SecurityEventRecorder::record(SecurityEventType::AccountDeleted, $freshUser)` を呼んでおり、
recorder は `metadata` 配列を受け取れる。ここに**削除実行時点の凍結状態**を載せる。

- 既存の監査テーブル (`security_audit_events`) が持つのは
  **`user_id` / `event_type` / `metadata` (json) / `ip_address` / `occurred_at`** で、
  **route 名も HTTP メソッドも残っていない** (実査)。「どうやって通ったか」を後から追うには
  それだけでは足りない。
- そこで metadata に載せるのは次の 3 つ。**いずれも PII ではない**:
  - `deletion_requested` (bool) … 削除実行時点で凍結中だったか
  - `route` (string|null) … どの route から到達したか
  - `method` (string) … HTTP メソッド
- 値の出所は**行ロック下で読み直した `$freshUser`** であり、削除と同一トランザクション内。
  「削除された行が、その瞬間に凍結中だったか」が後から確定できる。
- **これは防御ではなく観測である。** 値を見て分岐しない (制御フローを変えない)。

### 防御を足さない理由 (言い方を正確にする)

「現実装が正しいから足さない」ではない。**現時点で壊れ方を特定できておらず、
重複防御は設計を濁して本当の壊れ方を隠すから**である。まず契約テストと観測を増やし、
再発したときに原因へ到達できる状態を作る。

## 期待効果

- 「凍結中の即時削除は通らない」が **HTML と XHR の両方**で機械保証される。
- 将来 `expectsJson()` 分岐や allowlist を触ったときに、**JSON 経路だけ壊れる**退行を捕まえられる。
- F-4-Q1 を「観測ギャップを閉じた」という形で決着できる (再現しないものを放置しない)。

## 保証しないもの（誇張しない）

- **観測された 1 件の原因は特定していない。** 本 TODO はテストを足すだけで、
  「もう起きない」とは言わない。原因が実在するなら、このテストは**それを捕まえる網**である。
- **並行実行 (ブラウザ遷移と fetch の競合) は再現しない。** Feature テストは 1 リクエストずつ
  順に実行するため、探索エージェントが疑った競合そのものは検査できない。
- **防御は増えない。** 増えるのは契約テストと監査 metadata (観測) だけで、
  `deletion_requested` の値で分岐する処理は 1 つも作らない。

## スコープ外（今回やらないこと）

- **防御の追加** (凍結判定の二重化、削除直前の再チェック等)。
- 並行リクエストの再現基盤づくり。
- 凍結 allowlist の見直し。
