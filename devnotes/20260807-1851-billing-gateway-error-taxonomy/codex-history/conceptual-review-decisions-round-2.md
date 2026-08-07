# 対応マトリクス: conceptual-review Round 2

Codex 判定: **CHANGES_REQUESTED** ([Critical] 1 / [Warning] 5 / [Suggestion] 4)。
W7 (AGENTS.md 追記) の反論は Codex に受け入れられた。

## [Critical] `UnknownApiErrorException → unknown` と `unknown` の運用契約が矛盾している

- 判断: **対応する。ただし Codex の 3 案はいずれも採らず、より単純な解に変える**
- 根拠: 指摘は正しい。「写像表に載っているのに `unknown`」は
  「`unknown` = 分類器の欠陥 → 表へ追加せよ」という運用契約と両立しない。
  Codex の案は (1) `unknown` の定義を緩める / (2) `classification_status` キーを足す /
  (3) `provider_unknown` case を足す の 3 つだが、**いずれも語彙か項目を増やす**方向である。

  **実装を読んで前提が誤っていたことが分かった。** `vendor/stripe/stripe-php/lib/ApiRequestor.php`
  の `_specificV1APIError()` は HTTP status の `switch` であり、
  `UnknownApiErrorException` は **`default:` 分岐** = 400/401/402/403/404/429 の**いずれでもない
  status** を意味する。つまり **Stripe の 5xx 障害はすべてここに来る**。
  「未知」なのは error type であって、**status は分かっている**。
  5xx を `unknown` に落とす設計は、決済 gateway で最も起きる障害
  (相手側の一時障害 = 待てば直る) を分類できないという致命的な穴だった。
- 対応内容:
  1. `UnknownApiErrorException → unknown` の写像を**撤回**し、
     **HTTP status による 2 分岐**にした (表に載せた唯一の特別規則):
     `getHttpStatus() >= 500` → `provider_unavailable` / それ以外 → `provider_rejected`。
     HTTP status class は Stripe が生成する可変文字列ではなく**標準の有界な語彙**なので、
     「外部語彙を採らない」方針と矛盾しない。
  2. 結果として **写像表の値に `unknown` を持つ entry が 0 件**になった。
     `unknown` は「**写像表に一致が無かった**」ことと 1:1 に対応し、
     運用契約 (`unknown` = 分類器の欠落 → 表へ追加) がそのまま無矛盾に成立する。
  3. これを**機械で固定**する: gate に
     「写像表の値に `GatewayFailureClass::Unknown` が現れてはならない」を追加した。
     Codex が案 2/3 で解こうとした「明示的 unknown と未登録 unknown の区別」は、
     **明示的 unknown を禁止する**ことで区別する必要そのものを消した (思考原則 2)。
  4. 特別規則が増殖しないよう、gate に
     「status で細分するクラスは `UnknownApiErrorException` **ちょうど 1 件**」を exact fit で置いた。

## [Warning] `QueryException → local_infrastructure` は運用行動を誤らせる

- 判断: **対応する** (Codex の案 1 = 名前と定義の修正を採る)
- 根拠: 正しい。`QueryException` は接続障害・SQL 不備・制約違反・データ不整合を包む。
  「インフラ障害」と名乗ると、運用担当が DB のメトリクスだけ見て終わる誤誘導になる。
- 対応内容: case 名を `local_infrastructure` → **`local_failure`** に改名し、定義を
  「**自インフラ層 (DB / cache) の呼び出しが失敗した**。障害・SQL 不備・制約違反のいずれもありうる。
  DB / cache 層と直前のクエリを調べる」とした。
  `invariant_violation` との区別軸を「**誰が検出したか**」に明文化した
  (アプリ自身の `Assert` / 明示的例外 = `invariant_violation` /
  DB・cache 層が返した失敗 = `local_failure`)。

## [Warning] vendor 21 クラスの写像を実際の throw 条件で裏付けよ

- 判断: **対応する (この場で裏付けた)**
- 根拠: 妥当。クラス名だけの推測で分類表を作ると、SDK の実装と食い違う。
- 対応内容: 名指しされた 4 クラス + 主要クラスを vendor のソースで確認し、
  **throw site を写像表の根拠欄に書いた**。
  - Stripe: `_specificV1APIError()` の status `switch` が正本
    (400→InvalidRequest / 400+`idempotency_error`→Idempotency / 400+`rate_limit`→RateLimit /
     401→Authentication / 402→Card / 403→Permission / 404→InvalidRequest / 429→RateLimit /
     default→UnknownApiError)。`_specificV2APIError()` は
     `temporary_session_expired` type のみ `TemporarySessionExpiredException` に振り、
     残りは V1 へ委譲する。
  - Cashier (すべて具象クラス。abstract は無い):
    `PaymentMethod.php:31` `InvalidPaymentMethod::invalidOwner()` /
    `Subscription.php:910,1021,1532` `SubscriptionUpdateFailure::duplicatePrice()`
    `cannotDeleteLastPrice()` `incompleteSubscription()` /
    `ManagesCustomer.php:53,69` `InvalidCustomer::notYetCreated()`
    `CustomerAlreadyCreated::exists()` / `Invoice.php:77` `InvalidInvoice::invalidOwner()`。
    いずれも「アプリが渡した対象が owner に属さない / 前提状態が違う」であり
    `invariant_violation` で正しい。
  - `IncompletePayment` は `HandlesPaymentFailures` 経由で「追加操作 (SCA) が要る」を表す。
    `provider_rejected` (再送では収束しない) で正しい。
- 併せて詳細設計の受入条件に「各 entry の根拠欄に throw site または公式仕様の参照を持つこと」を加えた。

## [Warning] `provider_rejected` は「一次切り分けが決まる」には広すぎる

- 判断: **対応する** (case は分けない。Codex も「分けない判断を支持」と明言)
- 根拠: 妥当。`provider_rejected` の中では「誰が直すか」までは決まらない。
- 対応内容: 期待効果の表現を
  「**再送で収束するか否かの一次切り分けが決まる**」に限定した
  (「待つ / 直す / 調べる」という 3 分法の書き方をやめた)。

## [Warning] `unknown` の受容判断をコメントに残すだけでは機械判定できない

- 判断: **対応する (Critical の解決に吸収)**
- 根拠: 正しい。ただし Critical の対応で **明示的 `unknown` が存在しなくなった**ため、
  「受容済みか未対応か」という状態そのものが消えた。
- 対応内容: 「例外的に写像表のコメントへ残す」という逃げ道を運用契約から**削除**し、
  「`unknown` が出たら必ず写像表へ entry を足す (= 件数 cap が動くので差分に必ず現れる)」に一本化した。
  `docs/architecture.md` に owner (課金基盤の担当) と再評価条件を書く要件は残した。

## [Warning] PHPDoc の型を `class-string<Throwable>` に絞れ

- 判断: **対応する**
- 対応内容: `context()` の戻り値を
  `array{failure_class: string, error_class: class-string<Throwable>}`、
  `map()` を `array<class-string<Throwable>, GatewayFailureClass>` に修正。
  併せて「**enum を保持できる場所では `GatewayFailureClass` のまま扱い、
  ログ境界でのみ `->value` にする**」という実装契約を明記した。

## [Suggestion] 使命・W1/W2 解消・伝播 3 job の免除根拠・AGENTS.md 追記の承認

- 判断: 変更不要 (すべて肯定的評価)。
