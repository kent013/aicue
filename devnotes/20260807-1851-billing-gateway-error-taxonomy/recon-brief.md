# 実査ブリーフ: 決済 gateway 境界のエラー分類と観測語彙の統一

> aicue:T131 (job-execution-deduplication の保証側実装) の Codex 合議で
> 「本 PR の範囲外。独立 TODO 起票が妥当」と決着した残課題 2 件を 1 つにまとめたもの。
> lctl 台帳の job-execution-deduplication には残課題として記録済み (revision 10-dff87d5fe7a1)。

## 経緯 (なぜ T131 では入れなかったか)

T131 の impl-review で Codex が「後始末の失敗記録に外部サービス生成のメッセージが
流れている」と指摘し、対応として **呼び出し側でのサニタイズ** を入れた
(`AutoRechargeService::terminateInvoiceBestEffort()`)。原例外は report せず、
例外クラス名だけを有界な値として記録し、previous にも繋がない形である。

Codex はこのとき代替案として **「gateway 境界で決済事業者の例外を、安定した分類
(エラーコード / HTTP status / request id) を持つドメイン例外へ変換する」** を挙げた。
これに対し「詳細設計が AutoRechargeGatewayInterface を変更しないと明記しており、
変更するなら設計の再合議が要る」と反論し、Codex 側も
**「今回の失敗モードは呼び出し側の report() で閉じており interface 契約変更なしに
解消できている。この PR で境界例外化まで広げる根拠は弱い」** として妥当と認定した。

つまり本課題は **T131 の対応が不十分だったから起票するのではない**。T131 は
自分の触った経路については閉じている。残っているのは (a) 境界での恒久的な分類の欠如と
(b) 同一クラス内の別経路との観測語彙の不一致である。

## 残課題 (a): gateway 境界での例外のドメイン化

現状 `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php` は 9 メソッドを持ち、
docblock に「Stripe 障害・設定不備は例外のまま伝播 (fail-closed)」と明記している。
つまり **Stripe SDK の例外がそのまま Service 層まで漏れる契約**である。

呼び出し側は `catch (Throwable $e)` で受けるしかなく、失敗の種類を区別できない。
T131 が例外クラス名を記録する形にしたのはこの制約の裏返しで、
「クラス名しか有界な値が無い」から選んだ最小手である。

境界でドメイン例外へ変換すれば、呼び出し側は安定した分類を得られ、
(b) の是正も「同じ分類を記録する」だけで済むようになる (= (b) は (a) から落ちてくる)。

## 残課題 (b): 同一クラス内の観測語彙の不一致

`app/Services/Billing/AutoRechargeService.php` に `$e->getMessage()` が 3 箇所残る:

- L833 `tryTerminateInvoice()` — 停止側の invoice 終端失敗。
  **T131 が新設した `terminateInvoiceBestEffort()` と同じことをしている姉妹経路**だが、
  こちらは原メッセージを構造化ログへ入れたままである。
  (T131 が `tryTerminateInvoice` を再利用しなかった理由はコード内 L671 に記載あり)
- L994 reconcile の attempt 処理失敗 (1 attempt の失敗を隔離する catch)
- L1014 reconcile の取りこぼし起票の失敗

いずれも T131 が触っていない経路のため手を付けていない。結果として
**同一クラス内で「例外クラス名を記録する経路」と「原メッセージを記録する経路」が
併存**しており、次に触る人がどちらに倣うべきか決まらない状態になっている。

## 設計で最初に決めるべき論点

1. **(a) をどこまでやるか**。9 メソッド全部を変換対象にするのか、
   取り消せない外部副作用を持つもの (createAutoRechargeInvoice / payOffSessionInvoice /
   terminateInvoice) に絞るのか。既存の 3 gateway (サブスク系 / チケット checkout 系 /
   本 interface) のうち本 interface だけ契約が変わると、
   「狭い gateway + gateway 単位の Fake bind」規約の中で非対称になる点をどう扱うか。
2. **分類の語彙をどう定めるか**。Stripe の error code をそのまま採ると外部語彙に
   依存し、増えたときに無音で `unknown` へ落ちる。有界な enum にするなら
   deny-by-default で「未知の code が来たら何が起きるか」を決める必要がある。
   request id は追跡に有用だが、これ自体は外部生成の文字列である
   (ただし形式が安定しており有界とみなせるか要判断)。
3. **(b) の 3 箇所を (a) の完成を待たずに直すか**。reconcile の 2 箇所は gateway 以外の
   例外 (DB 等) も落ちてくるため、(a) だけでは閉じない。
4. **FakeAutoRechargeGateway が同じ分類を返せるか**。fake が本物と違う例外を投げると、
   分類を使う分岐がテストで一度も通らない偽グリーンになる。
   `tests/Architecture/ExternalFakeWiringInvariantTest.php` の既存契約との整合も要る。

## 制約

- AGENTS.md 思考原則 2「今必要なものだけ作る」。分類の粒度を過剰にしない。
- AGENTS.md 禁止事項 3「後方互換の並走を残さない」。
  変換を入れると決めたら同じ PR で `catch (Throwable)` 側を消す。
- 課金経路の振る舞いを変えないこと。**分類は観測のためであり、制御フローを変えない**
  のが既定 (変えるなら理由を設計に書く)。
- 既存の `terminateInvoiceBestEffort()` が持つ「原例外を previous に繋がない」性質は
  T131 の合議で確定した判断なので、蒸し返さない。
