# 実査ブリーフ: 先送りしていた堅牢性 2 件 (T140 + 予約行の既定値依存)

> このセッション中に「別 TODO」として明示的に先送りした項目のうち、
> **単独で完了でき、かつ実コードで実在を確認できた 2 件**をまとめて閉じる。
> どちらも「今は顕在化していないが、条件が揃うと黙って壊れる」種類である。

---

## 件 1: auto-recharge の unique violation 判定が対象制約を識別しない (既存 TODO aicue:T140)

### 実コードで確認した事実

`app/Services/Billing/AutoRechargeService.php` は `isUniqueViolation($e)` を **2 箇所**で使う。

- L306: `startCheckout` 相当の replay 判定。「同一 attempt_token の replay」として**例外を握り潰す**
- L510: 起票の並行競合。「DB partial unique (`tar_attempts_org_pending_unique`) が最終防衛」として **null を返す**

**`isUniqueViolation()` は SQLSTATE (23505 / 23000) だけを見ており、制約名を識別しない。**
したがって**別の unique 制約の違反も同じ扱いに収束する** = 本当の障害が「並行競合」として黙って握り潰される。

### なぜ今やるか

aicue:T137 で `AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去し、**一回性の担保を DB 制約へ寄せた**。
DB 制約への依存が強まったぶん、「どの制約に当たったか」を識別しないことの危険も増している。
T140 はそのときに起票された追跡先である。

### 設計で決めるべきこと

1. **制約名をどう取るか**。PostgreSQL の例外から制約名を取り出す方法 (`$e->errorInfo` / driver 由来のメッセージ)
   を**実コードと vendor で確認する**こと。**推測で書かない**。取れないなら「取れない」と結論してよい。
2. **期待する制約名をどこに置くか**。呼び出し 2 箇所で期待する制約は異なる可能性がある
   (L306 は attempt_token 系、L510 は `tar_attempts_org_pending_unique`)。**呼び出し側が期待を宣言する形**が素直か。
3. **識別できなかったときの挙動**。**fail-closed** (再送出) が既定であるべき。
   「不明な unique 違反を並行競合として飲み込む」現状こそが問題だから。
4. **他に同型の握り潰しが無いか**。ただし**今必要なものだけ作る** (思考原則 2)。

---

## 件 2: take_upload_reservations.status が DB 既定値に依存する (aicue:T151 の副次発見)

### 実コードで確認した事実

`app/Services/Capture/TakeUploadService.php` L76-84:

```php
$reservation = $lockedCut->uploadReservations()->make([...]);
$reservation->forceFill(['organization_id' => $lockedOrg->id])->save();
```

**`status` を明示代入していない**。`database/migrations/*_take_upload_reservations*` の
`$table->string('status', 20)->default('pending')` に依存している。

**これは aicue:T151 で直した `VideoManualService::create()` とまったく同じ形**である。
T151 の副次発見として記録され、「**戻り値の status を読む呼び出し側が 0 件で顕在化していないため
本件では直さず、別 TODO 候補として記録のみ (対処の約束ではない)**」とされた。

### なぜ今やるか

- **同じ species のバグが 2 箇所目**である。T151 は pipeline-smoke の実走で初めて顕在化した。
  こちらも「呼び出し側が status を読んだ瞬間」に同じ壊れ方をする。
- migration の default を変えると**この経路の挙動だけが黙って変わる**。
- T151 で **`VideoManualService` の docblock が既定値依存の危険を明文化**しており、
  リポジトリの方針としては既に「明示代入する」側に倒れている。

### 設計で決めるべきこと

1. **T151 と同じ直し方でよいか**。`forceFill` に `status` を足すだけで足りるか、
   予約の状態遷移 (pending → verifying → completed / released) の CAS 規約と衝突しないかを確認する。
   **AGENTS.md ドメイン規約 2 (容量 Quota の予約規約)** が「直接 UPDATE を書かない」と定めている点に注意。
   **初期値の INSERT は状態遷移ではない**が、設計者が根拠を示して確認すること。
2. **inventory 登録が要るか**。`take_upload_reservations` の状態を書く経路に
   対応する Architecture テストがあるか実コードで確認する (無ければ不要)。
3. **横断的に潰すか**。同型 (INSERT で状態列を明示代入しない) が他にもあるかを調べてよいが、
   **今必要なものだけ作る**。見つけても本件で直すとは限らず、記録に留めてよい。

---

## 共通の要点

- **どちらも再現テストを先に赤にしてから直す**。T151 では
  「戻り値の status を読むテスト」が `Failed asserting that null is identical to ...` で赤になった。
  件 2 は同じ形で再現できるはず。件 1 は**別の unique 制約に当てて握り潰されることを再現**する。
- **migration の default は消さない** (既存行と他の INSERT 経路に影響する)。
- **保証しないものを明記する**。

## やらないこと

- **課金の振る舞いを変えない** (件 1 は観測と fail-closed の話であって、成功時の挙動は変えない)。
- **予約の状態遷移の CAS 規約を変えない** (件 2 は初期値の話)。
- 横断的な「既定値依存を禁止する gate」の新設は、**判定式が静的に書けず偽陽性で gate の信用を落とす**ため
  aicue:T151 の設計が既に却下している。蒸し返さないこと。
