# Round 2: Round 1 指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] Cashier API allowlist が exact-fit になっていない

- 判断: **対応する**
- 根拠: 指摘のとおり。根拠文さえ書けば `charge` / `cancelNow` を allowlist へ足して
  **検出面を静かに狭められる**。これは gate の fail-open であり、
  `ExternalSeamInventoryTest` が「規則が名乗れる種別」を二重宣言で pin しているのと同じ穴だった。
- 対応内容: 検査 7 に **allowlist キー集合の exact-fit pin** を追加した
  (`['active', 'subscriptions', 'user']` と一致しなければ赤)。
  検出面を狭めるには **2 箇所**を触らせる (意図的な摩擦)。

## [Critical] container binding 経由の実装到達を閉包が辿れない

- 判断: **対応する**
- 根拠: 妥当。`App\Contracts\Foo` を型注入して provider で concrete に bind する形は
  Laravel では通常の依存辺であり、interface で閉包が止まると実装側の `Stripe\StripeClient` に
  到達しない。「依存閉包」という名前が果たすべき役割を満たしていなかった。
- 対応内容: **`implements` の逆向きの辺**を導入した。閉包に interface が入ったら、
  その app/ 内実装クラスを閉包へ引き込む (`deletionPathImplementedInterfaces()` +
  `deletionPathTraverse()` の implementors)。fixture 8 形目で固定した。
  - **`extends` は逆向きに辿らない**。`AccountController extends Controller` から
    app/ の全 Controller が閉包に入り信号が死ぬため。この限定と、
    残る穴 (abstract 基底への bind / closure binding / contextual binding / 別名文字列 bind) は
    冒頭 docblock の「保証しないもの」へ明記した。正のコントロール fixture でも固定した。
  - 実アプリの閉包メンバーは 53 件のまま変化なし (現時点で閉包内に App の interface が無いため)。
    すなわち今日の効果は 0 で、**将来 interface 注入が入ったときに効く**予防である。

## [Warning] `->{'stripe'}()` の literal 動的メソッド呼び出しが検出から落ちる

- 判断: **対応する**
- 根拠: そのとおり。動的からも記号照合からも落ちていたので、この 1 つの書き方で素通りできた。
- 対応内容: `deletionPathLiteralDynamicCalls()` を追加し、literal の `->{'name'}()` /
  `::{'name'}()` を**通常の呼び出しと同じ規則で分類**するようにした (fixture 7 形目)。
  literal は「動的」には二重計上しない。

## [Warning] `DeletionPathSeamExemption` の照合キーが docblock と実装でずれている

- 判断: **対応する**
- 根拠: 実装は `path:line` 入りの記述子を照合キーにしており、docblock の宣言
  (`{FQCN}#{記号}`) と一致していなかった。行移動で免除が壊れる脆い設計でもあった。
- 対応内容: hit を `array{symbol, descriptor}` に分け、**symbol は行番号を含まない安定キー**にした。
  照合は `$class.'#'.$hit['symbol']`。enum の docblock に symbol の実例 4 形を書いた。

## [Warning] 自己参照コントロールが設計どおりでない (edges 未確認 / root の exact-fit が弱い)

- 判断: **対応する**
- 根拠: 妥当。「到達 0 件」と書いていたが実際は edges を見ておらず、
  しかも本ファイルは免除 enum を import するので **0 件は成立しない** = 実装より強い保証を謳っていた。
  root の exact-fit が無かったことは mutation M1 が緑だった事実とも符合する。
- 対応内容:
  - 検査 5 を **edges の exact-fit pin** に変えた
    (`['App\Enums\Security\DeletionPathSeamExemption']` ちょうど)。docblock も実装に合わせた。
  - **検査 8 (起点集合の exact-fit pin)** を新設した。起点を静かに減らせなくなった。

## [Warning] A2 の「API を呼ばない」テストが StripeGatewayInterface にしか効かない

- 判断: **対応する**
- 根拠: 妥当。Cashier / Stripe SDK を直接使えば mock を経由せず、Feature テストは緑のまま通る。
- 対応内容: **検査 9** を新設し、`MarkStripeCustomerRedactedCommand` 自身を静的走査して
  決済事業者記号 0 件・動的呼び出し 0 件を固定した (このコマンドは退会経路の閉包に入らないため
  名指しの検査が要る)。Feature テストの mock は残す (並存)。

## [Warning] architecture.md の未 pin 記述と pin 解消の追記が矛盾して見える

- 判断: **対応する (ただし既存行は書き換えず「畳む」)**
- 根拠: 指摘は妥当。ただし本タスクは並列実行中で、共有ファイルの既存行の書き換えは
  他タスクとの衝突源になるという制約がある。Codex 自身が挙げた 2 案のうち
  「**過去経緯として明確に畳む**」方を採った。
- 対応内容: 直上 bullet の直後に「**⚠ 直上の bullet は経緯として残した過去記述である**。
  2 点は T141 で解消済みで、現在状態は直下の 3 bullet が正本」という bullet を挿入した。

## [Suggestion] runbook の tinker 案内を dry-run 出力に寄せる

- 判断: **対応する**
- 根拠: A3 の「新しい探索経路を作らない」という意図とちょうど一致する。
  dry-run は 1 列も書かないので確認手順として安全でもある。
- 対応内容: §2 手順 1 を `billing:mark-stripe-customer-redacted <id>` の dry-run 出力へ差し替えた。

## [Suggestion] `--customer=` で期待値照合する余地

- 判断: **見送る**
- 根拠: 思考原則 2 (今必要なものだけ作る) と詳細設計 A2 の範囲外。現時点で
  `stripe_id` が差し替わる経路は存在せず、照合すべき「期待値」の出所も運用チケットしかない。
  監査精度が問題になるのは「差し替え経路ができたとき」であり、そのときに
  記録列 (`stripe_customer_redacted_id`) との突合を入れれば足りる (列は既にある)。
  runbook §4 に「事業者側 job id は運用チケットに残す」と明記して補っている。

## [Suggestion] / [Approved] その他

- migration / Organization / factory / DirectFetchInventory は Approved のため対応なし。


## 修正後の差分 (Round 1 で指摘を受けたファイルのみ。他ファイルは Round 1 から未変更)

```diff
diff --git a/app/Enums/Security/DeletionPathSeamExemption.php b/app/Enums/Security/DeletionPathSeamExemption.php
new file mode 100644
index 0000000..b24593b
--- /dev/null
+++ b/app/Enums/Security/DeletionPathSeamExemption.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 退会 (アカウント削除) 経路の依存閉包から決済事業者記号へ到達することを認める免除。
+ *
+ * 母集団は `tests/Architecture/AccountDeletionPathGateTest.php` の検査 2 が出す hit で、
+ * 免除は **型付き case + 30 文字以上の根拠**のセットでのみ成立する
+ * (文字列で免除できると根拠なしに穴を開けられるため)。
+ *
+ * ★**現時点で case は 0 本**である。閉包内に決済事業者記号は 1 件も無く、
+ *   gate は「0 本ちょうど」を cap として pin している (余裕枠を持たせない)。
+ *   case を足すときは gate 側の `DELETION_PATH_SEAM_EXEMPTION_RATIONALES` へ
+ *   同じ value をキーに 30 文字以上の根拠を**同時に**登録する必要がある
+ *   (登録しなければ gate が赤くなる = 免除は必ずレビューを通る)。
+ *
+ * ★value の書式は `{クラス FQCN}#{記号}` である (gate の hit が持つ**安定 symbol** と同じ形。
+ *   **行番号を含まない**ので、コードが上下に動いても免除は壊れない)。symbol の実例:
+ *   - 型・クラス参照: `App\Services\Foo#Stripe\StripeClient`
+ *   - 静的呼び出し: `App\Services\Foo#Laravel\Cashier\Cashier::stripe()`
+ *   - メソッド呼び出し: `App\Services\Foo#->newSubscription()`
+ *   - container literal: `App\Services\Foo#container:cashier.stripe`
+ */
+enum DeletionPathSeamExemption: string
+{
+    // 現時点で case は 0 本 (閉包内に決済事業者記号は 1 つも無い)。
+    // 足すときは上の docblock の手順に従うこと。
+}
diff --git a/docs/account-deletion-runbook.md b/docs/account-deletion-runbook.md
new file mode 100644
index 0000000..619dbaf
--- /dev/null
+++ b/docs/account-deletion-runbook.md
@@ -0,0 +1,127 @@
+# 退会 (アカウント削除) runbook — 決済事業者側 customer の redaction
+
+> 対象読者: 運用担当者。
+> 関連: `docs/architecture.md` §退会 (アカウント削除) の課金ガード (T115) /
+> lctl 台帳 feature `account-deletion-billing-guard` 標準形 v1 (裁定 AG-128) の必須 (1)。
+
+## 0. この runbook が扱う範囲
+
+**アプリは決済事業者側の顧客データを自動で消さない**。退会経路から決済事業者 API を
+呼ばないのが T115 からの原則である (自 DB と外部サービスの二重書き込みを避ける /
+解約を代行しない)。この原則は静的 gate
+`tests/Architecture/AccountDeletionPathGateTest.php` と behavioral 2 本
+(`tests/Feature/Auth/AccountDeletionTest.php`) が並存して固定している。
+
+したがって決済事業者側の非表示化 (redaction) は **人手**で行い、
+その**実施記録だけ**をアプリに残す。本 runbook はその手順である。
+
+### 保証しないもの (誇張しない)
+
+- **アプリからの自動 redaction は行わない**。実施はダッシュボード / 事業者 API 操作であり、
+  アプリはその事実を記録するだけである。
+- 記録列は「**実施したと運用者が申告した**」ことの証跡であって、事業者側で実際に
+  非表示化が完了したことの検証ではない (完了確認は事業者側の job status を見る)。
+- 静的 gate は**検知であって遮断ではない**。実行時の外部通信を止める機構ではない。
+
+## 1. 対象組織の解決手順
+
+**新しい探索経路を作らない**。起点は既存の日次バッチである:
+
+```bash
+php artisan billing:detect-orphan-billing-organizations
+```
+
+このコマンドは「Owner 不在かつ生きた課金責務が残る組織 (課金孤児)」を検出し、
+**件数と organization id のみ**を `report()` する (組織名・メール等の PII は載せない)。
+この id が redaction 検討の入口になる。
+
+退会本人からの削除要請で個別に対象が判明した場合も、対象は organization id で名指しする。
+
+## 2. 決済事業者 (Stripe) 側の操作
+
+> **一次情報 (2026-08-10 確認)**
+>
+> - 非表示にする手順・対象オブジェクト・処理時間: <https://docs.stripe.com/privacy/redaction>
+> - 削除要請の扱いと非表示化の位置づけ: <https://docs.stripe.com/privacy/deletion-requests>
+>
+> 引用 (要旨):
+> - 「不正利用とリスクを防ぐために、**ほとんどの取引は作成から 90 日後に**削除できます」
+>   (失敗した取引は直ちに / サンドボックス取引は即時 / 返金された取引は返金完了時点)。
+> - 「すべての関連データを非同期で識別して編集するには、**最大 30 日**かかる場合があります。
+>   この間、ジョブの `status` フィールドは `validating` または `redacting` です」。
+> - 「顧客を削除する予定がある場合は、削除を遅らせる可能性のある新しい取引を防ぐために、
+>   **まず顧客を削除**してください」。
+>
+> **注意 (2026-08-10 時点の観測)**: RedactionJob は**公開プレビュー**と明記されている。
+> 一般提供の状態・API 形状は変わりうるので、実施前に必ず上記 URL を開き直すこと。
+> 本 runbook の数値は上の 2 URL からの引き写しであり、**事業者仕様が変われば無効になる**。
+
+手順:
+
+1. 対象組織の customer id を確認する。**新しい探索経路を作らない**ため、
+   §3 の記録コマンドの **dry-run 出力**をそのまま使う (dry-run は 1 列も書かない)。
+   ```bash
+   php artisan billing:mark-stripe-customer-redacted <organization_id>
+   # => [dry-run] 組織 <id> の customer=cus_xxx を redaction 実施済みとして記録します (--apply で実記録)。
+   ```
+   `stripe_id` を持たない組織ならここで FAILURE になり、そもそも対象外だと分かる。
+2. Stripe ダッシュボード / API で **まず Customer を削除**する
+   (新しい取引が発生して redaction が遅延するのを防ぐため。一次情報の推奨手順)。
+3. redaction job を作成し、検証エラーを解消してから実行する。
+   **90 日の待機が必要な取引が残っている場合、その期間は job が通らない**。
+   通らないことは異常ではない — 期間経過後に再実施する。
+4. **redaction は取り消せない**。非表示にした取引は不審請求の申し立てで自動的に敗訴になり、
+   返金もできなくなる。返金が必要な場合は**返金を先に**行う (一次情報の警告)。
+5. job の完了 (最大 30 日) を待つ。
+
+## 3. 実施の記録 (アプリ側)
+
+実施したら**必ず記録する**。記録が無いと、後から「どの customer を redact 済みか」を
+検証できない。
+
+```bash
+# 既定は dry-run (何も書かない)
+php artisan billing:mark-stripe-customer-redacted <organization_id>
+
+# 実記録
+php artisan billing:mark-stripe-customer-redacted <organization_id> --apply
+```
+
+記録されるのは 2 列セットである:
+
+| 列 | 内容 |
+|---|---|
+| `organizations.stripe_customer_redacted_at` | 実施日時 |
+| `organizations.stripe_customer_redacted_id` | 記録時点の `stripe_id` の写し |
+
+- **日時だけでは足りない**。「**どの** customer を redact したか」が事後に検証できないと、
+  `stripe_id` が差し替わる経路が将来できたときに監査列として意味を失う。
+- **両列は同時に埋まり同時に NULL** である。これはアプリ層だけでなく **PostgreSQL の
+  CHECK 制約** (`organizations_stripe_customer_redaction_pair_check`) が担保しており、
+  アプリを迂回した直接 UPDATE でも片側だけは書けない。
+- **このコマンドは決済事業者 API を呼ばない** (記録専用)。
+- `stripe_id` を持たない組織には記録できない (fail-closed。写す値が無いため)。
+
+### 二重実行したとき
+
+既に記録済みなら **no-op で成功**し、実施日と customer id を表示する。
+**上書きしない** — 最初の実施日が監査証跡だからである。
+
+```
+YYYY-MM-DD に記録済みです (customer=cus_xxx)。何もしません。
+```
+
+## 4. 実施者・実施日の残し方
+
+- アプリが持つのは「いつ・どの customer に対して実施したか」までである。
+  **誰が実施したかはアプリに残らない** (CLI 実行者を記録する仕組みを持たない)。
+- 実施者・実施理由・事業者側 job id は**運用チケット側**に残すこと。
+  本 runbook の URL と確認日、対象 organization id、コマンド出力を貼り付ける。
+
+## 5. 監視
+
+- `billing:detect-orphan-billing-organizations` の `report()` は既に監視対象である
+  (`docs/architecture.md` の監視対象リスト)。
+- 同じ organization id が**日をまたいで再報告され続ける**場合、redaction 待ち (90 日 / 最大 30 日)
+  なのか、対応が止まっているのかを本 runbook の手順で切り分ける。
+  再報告そのものは抑制状態を持たない冪等な観測であり、異常ではない。
diff --git a/docs/architecture.md b/docs/architecture.md
index 219be93..5baa34e 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -942,6 +942,33 @@ ## 退会 (アカウント削除) の課金ガード (T115)
   の handover / 裁定 AG-033 (**確認日 2026-08-05**。一次情報は決済事業者 (Stripe) の公式
   ドキュメントだが、**台帳側に一次情報の URL が pin されていない**)。数値を運用に効かせる前に
   一次情報を引き直し、URL と確認日をここへ追記すること。事業者仕様変更時に更新する対象である
+- **⚠ 直上の bullet は経緯として残した過去記述である**。「台帳側に一次情報の URL が pin されていない」
+  「数値を運用に効かせる前に一次情報を引き直せ」の 2 点は **T141 で解消済み**で、
+  現在状態は直下の 3 bullet が正本である (直上の未 pin 記述を現在状態として読まないこと)
+- **一次情報の pin (T141)**:
+  <https://docs.stripe.com/privacy/redaction> と
+  <https://docs.stripe.com/privacy/deletion-requests> を **2026-08-10 に確認**した。
+  90 日は「**取引**は作成から 90 日後に非表示にできる」(失敗した取引は直ちに / サンドボックスは即時 /
+  返金済みは返金完了時点)、最大 30 日は「関連データを非同期で識別して編集するのに最大 30 日」を指す。
+  **customer 単体の待機期間ではない**点に注意 (上の運用注記の要約より条件が細かい)。
+  なお RedactionJob は同日時点で**公開プレビュー**と明記されている。手順・保証しないもの・
+  実施記録コマンドは **`docs/account-deletion-runbook.md` が正本**
+- **redaction の実施記録 (T141)**: 実施は人手で行い、アプリは記録だけ持つ。
+  `organizations.stripe_customer_redacted_at` (実施日時) と
+  `organizations.stripe_customer_redacted_id` (記録時点の `stripe_id` の写し) の **2 列セット**で、
+  記録経路は `billing:mark-stripe-customer-redacted` (既定 dry-run / `--apply` で実記録 /
+  既記録なら no-op。**決済事業者 API を呼ばない**)。日時だけだと「**どの** customer を
+  redact したか」が事後に検証できないため 2 列必要で、**両列同時**の不変条件は
+  PostgreSQL の CHECK 制約 (`organizations_stripe_customer_redaction_pair_check`) が
+  アプリ層を迂回した UPDATE に対しても担保する
+- **「決済事業者 API を呼ばない」の静的 gate (T141)**:
+  `tests/Architecture/AccountDeletionPathGateTest.php` が退会経路の**依存閉包**を走査し、
+  閉包内のクラスが決済事業者記号へ到達しないことを固定する (免除は
+  `App\Enums\Security\DeletionPathSeamExemption` + 30 文字以上の根拠。現在 0 件)。
+  behavioral 2 本は「その経路で今日呼ばれなかった」しか言えず**新しい依存を注入した瞬間に沈黙する**
+  ため、静的 gate と behavioral は**並存**させる (behavioral 側は変更しない)。
+  **保証しないもの**は gate 冒頭 docblock が正本 (変数 container 解決 / vendor 内部の通信 /
+  docblock のみの受け手 / 実行時 bind 差し替え。**そもそも検知であって遮断ではない**)
 - **決済手段の前提**: subscription Checkout は `payment_method_types` を指定せず Stripe
   ダッシュボード設定に委ねている。**非同期決済 (コンビニ払い等) を有効化する場合、`incomplete` を
   退会ガードで通過させている判断を再確認すること** (滞留時間が伸びるため)
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
new file mode 100644
index 0000000..de0eccf
--- /dev/null
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -0,0 +1,1216 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\DeletionPathSeamExemption;
+use Laravel\Cashier\Billable;
+use Laravel\Cashier\Subscription;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceScanResult;
+use Tests\Support\ReferenceSite;
+use Webmozart\Assert\Assert;
+
+/*
+ * Architecture invariant: **退会 (アカウント削除) 経路の依存閉包から決済事業者 SDK へ到達しない**。
+ *
+ * SoT = lctl 台帳 feature `account-deletion-billing-guard` の標準形 v1 (裁定 AG-128) と
+ * docs/architecture.md §退会 (アカウント削除) の課金ガード (T115)。
+ *
+ * ★なぜ静的検査か (behavioral では捕まらない):
+ *   既存の tests/Feature/Auth/AccountDeletionTest.php の 2 本
+ *   (「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも呼ばない」) は
+ *   **その経路で今日呼ばれなかった**ことしか言えない。新しい依存を注入した瞬間に沈黙する
+ *   (注入しただけで呼ばれない依存は behavioral では観測できず、次の変更で呼ばれた時に初めて壊れる)。
+ *   laravel-claude-template では実際に「依存閉包の抽出が**型宣言だけの注入**を素通りさせていた」
+ *   fail-open が実装レビューで見つかっている。よって静的 gate と behavioral 2 本は**並存**させる
+ *   (behavioral 側は 1 行も変更しない)。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: 起点から辿れる app/ 内クラスの閉包が目録 (DELETION_PATH_CLOSURE) と exact-fit
+ *   - 検査 2: 閉包内のどのクラスも決済事業者記号を参照しない
+ *     (Stripe\* / Laravel\Cashier\Cashier / Cashier Billable・Subscription の API メソッド名 /
+ *      名前に stripe を含む呼び出し / 決済 binding の container literal)
+ *   - 検査 3: 免除は DeletionPathSeamExemption (型付き enum) + 30 文字以上の根拠のみ。現在 0 本ちょうど
+ *   - 検査 4: 空振り検知 (走査ファイル数 / 解決できた到達辺 / 閉包サイズが 0 でない・閉包が実在クラス)
+ *   - 検査 5: 自己参照コントロール (本ファイル自身を走査して記号 hit 0 件・辺は exact-fit)
+ *   - 検査 6: 閉包内に**動的メソッド名の呼び出しが 0 件** (`->{$m}()` / `::$m()` は名前が字句的に
+ *     確定せず記号照合を迂回できるため、閉包内では deny-by-default で 0 件に pin する)
+ *   - 検査 7: Cashier API 名の導出が生きている (ローカル判定 allowlist は **exact-fit の二重宣言**
+ *     + 30 文字以上の根拠つき。allowlist へ足すことは検出面を狭めることと同義なので摩擦を置く)
+ *   - 検査 8: 起点集合の exact-fit pin (起点を静かに減らせない)
+ *   - 検査 9: redaction 記録コマンドが決済事業者記号を持たない (記録専用であることの静的固定)
+ *   - 正負 fixture: 型注入のみ / facade / static call / app()・resolve()・make() の literal 引数 /
+ *     trait 経由 (+ alias 上書き) / 動的メソッド名 / literal 動的メソッド名 /
+ *     interface 経由の container binding / 基底クラス継承を辿らないこと / コメント・文字列の非誤検出
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **文字列キーが変数の container 解決** (`$c->make($name)`)。受け手を解決できない
+ *   - **vendor 内部から出る通信**。Cashier の WebhookController / Billable の内部実装は閉包の外
+ *   - **完全修飾 docblock だけで型宣言も import も無い受け手** (docblock 解析はしない)
+ *   - **実行時 config による bind 差し替え** (静的走査は bind 先を知らない)
+ *   - **container binding のうち `implements` 関係で表せないもの**。閉包は interface の
+ *     実装クラスを逆向きに引き込むが、**abstract 基底クラスへの bind / closure binding /
+ *     contextual binding / 別名文字列 bind** は辿らない (`extends` を逆向きに辿ると
+ *     `AccountController extends Controller` から app/ の全 Controller が入り信号が死ぬため、
+ *     意図的に `implements` だけに限定している)
+ *   - **`use Billable;` のような trait 取り込み**そのもの。PhpReferenceScanner はクラス本体の
+ *     `use` を import として扱い site を出さないため、trait 名は記号照合に載らない
+ *     (帰結として Cashier の**構造的な取り込み**は検出せず、**呼び出し**だけを見る)
+ *   - **`Laravel\Cashier\` 名前空間の型参照そのもの** (`Subscription extends CashierSubscription` /
+ *     `use Billable;`) は記号にしない。接頭辞走査は値オブジェクト・例外・モデル継承を巻き込んで
+ *     信号を殺すため (ExternalSeamScanner が同じ理由で接頭辞走査を禁じている)
+ *   - **メソッド呼び出しの受け手は解決しない**。決済 API 名の照合は名前だけで行うため、
+ *     同名の無関係な呼び出しは偽陽性になりうる (fail-closed 側に倒している)
+ *   - **これは検知であって遮断ではない**。実行時の外部通信を止める機構ではない
+ *
+ * ★閉包の粒度は**クラス**である (起点は method 名で指すが、閉包はクラス単位で辿る)。
+ *   同一クラス内の private メソッド経由の到達 (`deleteAccount` → `$this->organizationsBlockingDeletion()`)
+ *   を落とさないための意図的な過大近似 = fail-closed。method 粒度にすると
+ *   「private メソッドへ移せば gate を迂回できる」抜け道ができる。
+ *
+ * 解析は Tests\Support\PhpReferenceScanner に乗せる (namespace 解決 / alias / scope 追跡を
+ * ExternalSeamInventoryTest / ExternalClientTimeoutInventoryTest と共有する。自前の走査器を作らない)。
+ * 走査は正規化済みトークン列に対して行うため、**この説明コメント自身では偽赤にならない** (検査 5)。
+ * DB 不使用 (Architecture lane は TestCase のみ)。
+ */
+
+/**
+ * 退会経路の起点 (`FQCN::method`)。
+ *
+ * ★**PR-A の時点では 2 つ**である。PR-B (猶予期間つき削除) で日次執行バッチ
+ *   `App\Console\Commands\Account\PurgeDeletionRequestsCommand::handle` を 3 つ目として足す。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_ROOTS = [
+    'App\Http\Controllers\Settings\AccountController::destroy',
+    'App\Services\Organization\OrganizationMembershipService::deleteAccount',
+];
+
+/**
+ * 起点から辿れる app/ 内クラスの閉包 (exact-fit の目録)。
+ *
+ * ★増減はどちらも赤くする。増えたら「退会経路の依存が広がった」ことのレビューを、
+ *   減ったら「走査が壊れた / 起点が外れた」ことの検出を意図している。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_CLOSURE = [
+    'App\DataTransferObjects\Invitations\PendingInvitationForUserDto',
+    'App\DataTransferObjects\Notification\InvitationReceivedPayload',
+    'App\DataTransferObjects\Notification\ManualJobPayload',
+    'App\DataTransferObjects\Notification\TicketBalanceLowPayload',
+    'App\DataTransferObjects\Organizations\AccountDeletionBlockerDto',
+    'App\Enums\AccountDeletionBlockReason',
+    'App\Enums\AccountDeletionBlockerAction',
+    'App\Enums\AdminConsoleRole',
+    'App\Enums\Billing\PlanPriceKind',
+    'App\Enums\Billing\ScheduleSetupStatus',
+    'App\Enums\Billing\SubscriptionState',
+    'App\Enums\Billing\TicketLedgerKind',
+    'App\Enums\Billing\TicketReservationStatus',
+    'App\Enums\Billing\TicketSource',
+    'App\Enums\CheckoutIntent',
+    'App\Enums\CheckoutSessionStatus',
+    'App\Enums\Manual\AnalysisStep',
+    'App\Enums\Manual\JobStatus',
+    'App\Enums\Manual\RenderErrorCode',
+    'App\Enums\Manual\RenderKind',
+    'App\Enums\Manual\RenderStep',
+    'App\Enums\Manual\VideoManualStatus',
+    'App\Enums\Notification\NotificationType',
+    'App\Enums\OrganizationRole',
+    'App\Enums\ProjectRole',
+    'App\Enums\SecurityEventType',
+    'App\Enums\TwoFactorStatus',
+    'App\Http\Controllers\Controller',
+    'App\Http\Controllers\Settings\AccountController',
+    'App\Models\AnalysisJob',
+    'App\Models\Billing\BillingCheckoutSession',
+    'App\Models\Billing\OrganizationQuota',
+    'App\Models\Billing\Plan',
+    'App\Models\Billing\Subscription',
+    'App\Models\Billing\TicketLedgerEntry',
+    'App\Models\Billing\TicketReservation',
+    'App\Models\Organization',
+    'App\Models\OrganizationInvitation',
+    'App\Models\Project',
+    'App\Models\RenderJob',
+    'App\Models\SecurityAuditEvent',
+    'App\Models\User',
+    'App\Models\VideoManual',
+    'App\Notifications\InApp\InvitationReceivedNotification',
+    'App\Notifications\InApp\ManualAnalyzedNotification',
+    'App\Notifications\InApp\ManualRenderedNotification',
+    'App\Notifications\InApp\TicketBalanceLowNotification',
+    'App\Notifications\OrganizationInvitationNotification',
+    'App\Services\Billing\AccountDeletionBillingGuard',
+    'App\Services\Notification\NotificationCenterService',
+    'App\Services\Organization\OrganizationMembershipService',
+    'App\Services\Project\DefaultProjectResolver',
+    'App\Services\Security\SecurityEventRecorder',
+];
+
+/**
+ * Cashier の API 表面から**除外**するメソッド名 (小文字) => ローカル処理である根拠。
+ *
+ * ★決済 API 名の集合は `Laravel\Cashier\Billable` / `Laravel\Cashier\Subscription` の
+ *   public メソッドから**リフレクションで導出**する (Cashier が API を増やしたら自動で
+ *   母集団に入る = fail-closed)。ここに載せた名前だけがその母集団から外れる。
+ * ★走査は受け手を解決しない (`PhpReferenceScanner` の MethodCall は名前だけ)。よって
+ *   ここに載るのは「Cashier と同名だが実際には決済到達でない呼び出し」の allowlist である。
+ * ★`stripe` を含む名前は載せられない (検査 7 が拒否する)。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_CASHIER_LOCAL_METHODS = [
+    'subscriptions' => 'Billable が生やす subscriptions リレーションの取得。AccountDeletionBillingGuard は '
+        .'ローカル subscriptions 行を読むだけで決済事業者 API を呼ばない (T115 の設計そのもの)',
+    'active' => 'OrganizationInvitation の「有効な招待か」を判定するローカル述語。Cashier Subscription の '
+        .'同名メソッドとは無関係で、招待テーブルの列 (accepted_at / expires_at) だけを見る',
+    'user' => 'Request::user() / SecurityEventRecorder の actor 取得。Cashier Subscription の owner 取得と '
+        .'同名なだけで、認証済み actor をローカルに読むだけの呼び出しである',
+];
+
+/**
+ * 決済 binding とみなす container literal (小文字で部分一致)。
+ *
+ * `app('cashier.stripe')` のように **文字列キーで client を取り出す**形を捕まえる。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_CONTAINER_LITERAL_MARKERS = ['stripe', 'cashier'];
+
+/**
+ * 免除 (case value => 30 文字以上の根拠)。**現在 0 件ちょうど**。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_SEAM_EXEMPTION_RATIONALES = [];
+
+/**
+ * mutation 被覆表 (設計 §共通/mutation の本 gate 該当分)。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_MUTATION_COVERAGE = [
+    'M1' => '起点から deleteAccount を外すと閉包が縮み検査 1 (exact-fit) が赤くなる',
+    'M2' => 'OrganizationMembershipService へ Stripe\StripeClient を型注入するだけの property を足すと検査 2 が赤くなる',
+    'M3' => '同じ注入を app(\'cashier.stripe\') の literal 呼び出しで書くと検査 2 が赤くなる',
+];
+
+/** @var list<string> */
+const DELETION_PATH_MUTATION_IDS = ['M1', 'M2', 'M3'];
+
+/**
+ * app/ 配下 1 ファイルぶんの走査結果。
+ *
+ * @return array{
+ *     class: string,
+ *     edges: list<string>,
+ *     implements: list<string>,
+ *     payment: list<array{symbol: string, descriptor: string}>,
+ *     dynamic: list<string>,
+ * }
+ */
+function deletionPathScanSource(string $relativePath, string $source): array
+{
+    $result = PhpReferenceScanner::references($relativePath, $source);
+    $tokens = PhpReferenceScanner::tokens($source);
+
+    $class = deletionPathClassFromPath($relativePath);
+    $edges = deletionPathEdges($result, $tokens);
+    $payment = deletionPathPaymentHits($relativePath, $result, $tokens);
+    $dynamic = deletionPathDynamicCallSites($relativePath, $tokens);
+
+    // container literal は到達辺にもなる (`app(App\Foo::class)` は NameReference で拾えるが、
+    // `app('App\Foo')` は文字列なので site を出さない)。
+    foreach (deletionPathContainerLiterals($tokens) as $literal) {
+        if (str_starts_with($literal, 'App\\')) {
+            $edges[] = $literal;
+        }
+    }
+
+    return [
+        'class' => $class,
+        'edges' => array_values(array_unique($edges)),
+        'implements' => deletionPathImplementedInterfaces($result, $tokens),
+        'payment' => $payment,
+        'dynamic' => $dynamic,
+    ];
+}
+
+/**
+ * このファイルが `implements` している interface の FQCN。
+ *
+ * ★**なぜ必要か**: 退会経路が `App\Contracts\Foo` を型注入し、service provider が
+ *   concrete 実装へ bind している場合、型宣言の辺だけでは interface で止まり
+ *   **実装クラス側の決済事業者記号に到達しない** (impl-review Round 1 [Critical])。
+ *   interface が閉包に入ったら、その app/ 内の実装クラスも閉包へ引き込む
+ *   (逆向きの辺。過大近似 = fail-closed)。
+ * ★**`extends` は辺にしない**。`AccountController extends Controller` の基底クラスを
+ *   逆向きに辿ると **app/ の全 Controller** が閉包に入り信号が死ぬ。基底クラスの継承は
+ *   container binding の代替ではないため対象外とする (残る穴は冒頭の「保証しないもの」に明記)。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathImplementedInterfaces(ReferenceScanResult $result, array $tokens): array
+{
+    /** @var array<int, ReferenceSite> $byTokenIndex */
+    $byTokenIndex = [];
+    foreach ($result->sites as $site) {
+        $byTokenIndex[$site->tokenIndex] = $site;
+    }
+
+    $names = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_IMPLEMENTS) {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token['id'] === null && ($token['text'] === '{' || $token['text'] === ';')) {
+                break;
+            }
+            if ($token['id'] === T_NAME_QUALIFIED || $token['id'] === T_NAME_FULLY_QUALIFIED) {
+                $names[] = ltrim($token['text'], '\\');
+
+                continue;
+            }
+            // 短縮名は alias 解決済みの site から引く (`use App\Contracts\Foo;` + `implements Foo`)。
+            $site = $byTokenIndex[$j] ?? null;
+            if ($site !== null && $site->kind === ReferenceKind::NameReference) {
+                $names[] = $site->name;
+            }
+        }
+    }
+
+    return array_values(array_unique(array_filter(
+        $names,
+        static fn (string $name): bool => str_starts_with($name, 'App\\'),
+    )));
+}
+
+/**
+ * PSR-4 (`app/` => `App\`) でファイルパスからクラス FQCN を導く。
+ */
+function deletionPathClassFromPath(string $relativePath): string
+{
+    $withoutExtension = preg_replace('/\.php$/', '', $relativePath);
+    Assert::string($withoutExtension);
+
+    return str_replace('/', '\\', 'App'.substr($withoutExtension, strlen('app')));
+}
+
+/**
+ * 到達辺 (`App\` で始まる参照先 FQCN)。
+ *
+ * 型宣言 / `new` / `::class` / instanceof は NameReference・Construction として、
+ * 静的呼び出しの受け手は StaticCall の receiver として拾う。
+ * import を辺に数えるのは意図的な過大近似 = fail-closed (使われていない import も辺にする)。
+ *
+ * ★**import は alias マップ (`ReferenceScanResult::$imports`) から取らず、正規化トークン列の
+ *   修飾名トークンから直接取る**。`PhpReferenceScanner` はクラス本体の `use SomeTrait;` も
+ *   `use` 文として処理するため、**同名の短縮キーで先頭の import を上書きし FQCN を失う**
+ *   (`use App\Models\Concerns\Foo;` + `use Foo;` → alias マップは `foo => 'Foo'`)。
+ *   alias マップだけを見ると **trait 経由の到達辺が丸ごと消える** = fail-open になる
+ *   (実測: 本 gate の fixture 5 形目で発覚)。トークンを直接見れば上書きの影響を受けない。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathEdges(ReferenceScanResult $result, array $tokens): array
+{
+    $names = [];
+    foreach ($result->sites as $site) {
+        if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
+            $names[] = $site->name;
+        }
+        if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null) {
+            $names[] = $site->receiver;
+        }
+    }
+    foreach ($tokens as $token) {
+        if ($token['id'] === T_NAME_QUALIFIED || $token['id'] === T_NAME_FULLY_QUALIFIED) {
+            $names[] = ltrim($token['text'], '\\');
+        }
+    }
+
+    return array_values(array_unique(array_filter(
+        $names,
+        static fn (string $name): bool => str_starts_with($name, 'App\\'),
+    )));
+}
+
+/**
+ * 決済事業者記号の hit。
+ *
+ * ★`symbol` は**行番号を含まない安定キー**である (免除 `DeletionPathSeamExemption` の value は
+ *   `{クラス FQCN}#{symbol}` で書くため。行番号を含めると免除が行移動で壊れる)。
+ *   `descriptor` は失敗メッセージ用に path:line を含む。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<array{symbol: string, descriptor: string}>
+ */
+function deletionPathPaymentHits(string $relativePath, ReferenceScanResult $result, array $tokens): array
+{
+    $apiMethods = deletionPathPaymentApiMethods();
+    /** @var array<string, array{symbol: string, descriptor: string}> $hits 重複排除キー => hit */
+    $hits = [];
+
+    foreach ($result->sites as $site) {
+        $symbol = deletionPathClassifySite($site, $apiMethods);
+        if ($symbol !== null) {
+            $hits[$symbol.'@'.$site->line] = [
+                'symbol' => $symbol,
+                'descriptor' => $relativePath.':'.$site->line.' '.$symbol,
+            ];
+        }
+    }
+
+    // import だけを持ち site を出さないファイル (`use Stripe\StripeClient;` のみ) も拾う。
+    // alias マップではなくトークンを見る (辺の収集と同じ理由。上書きの影響を受けない)。
+    foreach ($tokens as $token) {
+        if ($token['id'] !== T_NAME_QUALIFIED && $token['id'] !== T_NAME_FULLY_QUALIFIED) {
+            continue;
+        }
+        $name = ltrim($token['text'], '\\');
+        if (deletionPathIsPaymentNamespace($name)) {
+            $hits[$name.'@name'] ??= [
+                'symbol' => $name,
+                'descriptor' => $relativePath.':'.$token['line'].' name '.$name,
+            ];
+        }
+    }
+
+    // literal の動的メソッド名 (`->{'stripe'}()`)。名前が字句的に確定するので
+    // **動的扱いにせず通常の呼び出しと同じ規則で分類する** (impl-review Round 1 [Warning])。
+    foreach (deletionPathLiteralDynamicCalls($tokens) as $call) {
+        $symbol = deletionPathClassifyMethodName($call['name'], $apiMethods);
+        if ($symbol !== null) {
+            $hits[$symbol.'@'.$call['line']] = [
+                'symbol' => $symbol,
+                'descriptor' => $relativePath.':'.$call['line'].' '.$symbol.' (literal 動的メソッド名)',
+            ];
+        }
+    }
+
+    foreach (deletionPathContainerLiterals($tokens) as $literal) {
+        $lower = mb_strtolower($literal);
+        foreach (DELETION_PATH_CONTAINER_LITERAL_MARKERS as $marker) {
+            if (str_contains($lower, $marker)) {
+                $symbol = 'container:'.$literal;
+                $hits[$symbol] = [
+                    'symbol' => $symbol,
+                    'descriptor' => $relativePath.' container literal '.$literal,
+                ];
+
+                break;
+            }
+        }
+    }
+
+    return array_values($hits);
+}
+
+/**
+ * site 1 件が決済事業者記号かを判定し**安定 symbol** を返す (該当しなければ null)。
+ *
+ * @param  array<string, string>  $apiMethods  小文字メソッド名 => 正規表記
+ */
+function deletionPathClassifySite(ReferenceSite $site, array $apiMethods): ?string
+{
+    if (($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction)
+        && deletionPathIsPaymentNamespace($site->name)
+    ) {
+        return $site->name;
+    }
+
+    if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null
+        && deletionPathIsPaymentNamespace($site->receiver)
+    ) {
+        return $site->receiver.'::'.$site->name.'()';
+    }
+
+    if ($site->kind !== ReferenceKind::MethodCall && $site->kind !== ReferenceKind::StaticCall) {
+        return null;
+    }
+
+    return deletionPathClassifyMethodName($site->name, $apiMethods);
+}
+
+/**
+ * メソッド名 1 つが決済事業者 API かを判定し安定 symbol を返す。
+ *
+ * @param  array<string, string>  $apiMethods  小文字メソッド名 => 正規表記
+ */
+function deletionPathClassifyMethodName(string $name, array $apiMethods): ?string
+{
+    $lower = mb_strtolower($name);
+    if (str_contains($lower, 'stripe') || array_key_exists($lower, $apiMethods)) {
+        return '->'.$name.'()';
+    }
+
+    return null;
+}
+
+/**
+ * 決済事業者の名前空間か (**接頭辞走査は Stripe SDK だけ**。Cashier は facade 1 本に限定する)。
+ */
+function deletionPathIsPaymentNamespace(string $fqcn): bool
+{
+    return str_starts_with($fqcn, 'Stripe\\') || $fqcn === 'Laravel\Cashier\Cashier';
+}
+
+/**
+ * Cashier の API 表面とみなすメソッド名 (小文字 => 正規表記)。
+ *
+ * @return array<string, string>
+ */
+function deletionPathPaymentApiMethods(): array
+{
+    /** @var array<string, string>|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $methods = [];
+    foreach ([Billable::class, Subscription::class] as $target) {
+        $reflection = new ReflectionClass($target);
+        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
+            if (! str_starts_with($method->getDeclaringClass()->getName(), 'Laravel\Cashier')) {
+                continue; // Eloquent 由来の継承メソッドは Cashier の API 表面ではない
+            }
+            $methods[mb_strtolower($method->getName())] = $method->getName();
+        }
+    }
+
+    foreach (array_keys(DELETION_PATH_CASHIER_LOCAL_METHODS) as $local) {
+        unset($methods[$local]);
+    }
+
+    $cache = $methods;
+
+    return $methods;
+}
+
+/**
+ * `app('...')` / `resolve('...')` / `->make('...')` の literal 第 1 引数。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathContainerLiterals(array $tokens): array
+{
+    $literals = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_STRING) {
+            continue;
+        }
+        if (! in_array(mb_strtolower($tokens[$i]['text']), ['app', 'resolve', 'make'], true)) {
+            continue;
+        }
+        $open = $tokens[$i + 1] ?? null;
+        $argument = $tokens[$i + 2] ?? null;
+        if ($open === null || $argument === null) {
+            continue;
+        }
+        if ($open['id'] !== null || $open['text'] !== '(') {
+            continue;
+        }
+        if ($argument['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+
+        $literals[] = deletionPathUnquote($argument['text']);
+    }
+
+    return array_values(array_unique($literals));
+}
+
+/**
+ * 文字列リテラルトークンから値を取り出す。
+ *
+ * ★`stripcslashes()` を通さない。単引用符の `'App\Foo'` に掛けると `\F` が escape として
+ *   消費され `AppFoo` になり、**クラス名の literal が丸ごと辺から落ちる**。
+ */
+function deletionPathUnquote(string $token): string
+{
+    $quote = $token[0] ?? "'";
+    $inner = substr($token, 1, -1);
+
+    return $quote === "'"
+        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner)
+        : stripcslashes($inner);
+}
+
+/**
+ * literal の動的メソッド呼び出し (`->{'stripe'}()` / `::{'charge'}()`)。
+ *
+ * 名前が字句的に確定するので**動的扱いにせず記号照合へ載せる** (載せないと
+ * `->{'stripe'}()` で記号照合を素通りできる = fail-open。impl-review Round 1 [Warning])。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<array{name: string, line: int}>
+ */
+function deletionPathLiteralDynamicCalls(array $tokens): array
+{
+    $calls = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $id = $tokens[$i]['id'];
+        if ($id !== T_OBJECT_OPERATOR && $id !== T_NULLSAFE_OBJECT_OPERATOR && $id !== T_DOUBLE_COLON) {
+            continue;
+        }
+        $open = $tokens[$i + 1] ?? null;
+        $inner = $tokens[$i + 2] ?? null;
+        $close = $tokens[$i + 3] ?? null;
+        $paren = $tokens[$i + 4] ?? null;
+        if ($open === null || $inner === null || $close === null || $paren === null) {
+            continue;
+        }
+        if ($open['id'] !== null || $open['text'] !== '{') {
+            continue;
+        }
+        if ($inner['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if ($close['id'] !== null || $close['text'] !== '}') {
+            continue;
+        }
+        if ($paren['id'] !== null || $paren['text'] !== '(') {
+            continue;
+        }
+
+        $calls[] = ['name' => deletionPathUnquote($inner['text']), 'line' => $tokens[$i]['line']];
+    }
+
+    return $calls;
+}
+
+/**
+ * 動的メソッド名の呼び出し (`->{$m}()` / `->$m()` / `::{$m}()` / `::$m()`)。
+ *
+ * ★literal の `->{'stripe'}()` はここには含めない。名前が字句的に確定するので
+ *   `deletionPathLiteralDynamicCalls()` が拾い、通常の呼び出しと同じ規則で記号照合する。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathDynamicCallSites(string $relativePath, array $tokens): array
+{
+    $sites = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $id = $tokens[$i]['id'];
+        if ($id !== T_OBJECT_OPERATOR && $id !== T_NULLSAFE_OBJECT_OPERATOR && $id !== T_DOUBLE_COLON) {
+            continue;
+        }
+
+        $next = $tokens[$i + 1] ?? null;
+        if ($next === null) {
+            continue;
+        }
+
+        // `->$m(` 形
+        if ($next['id'] === T_VARIABLE) {
+            $after = $tokens[$i + 2] ?? null;
+            if ($after !== null && $after['id'] === null && $after['text'] === '(') {
+                $sites[] = $relativePath.':'.$tokens[$i]['line'].' '.$tokens[$i]['text'].$next['text'].'()';
+            }
+
+            continue;
+        }
+
+        // `->{expr}(` 形 (literal は除く)
+        if ($next['id'] === null && $next['text'] === '{') {
+            $inner = $tokens[$i + 2] ?? null;
+            $closing = $tokens[$i + 3] ?? null;
+            $isLiteral = $inner !== null && $inner['id'] === T_CONSTANT_ENCAPSED_STRING
+                && $closing !== null && $closing['id'] === null && $closing['text'] === '}';
+            if (! $isLiteral) {
+                $sites[] = $relativePath.':'.$tokens[$i]['line'].' '.$tokens[$i]['text'].'{...}()';
+            }
+        }
+    }
+
+    return array_values(array_unique($sites));
+}
+
+/**
+ * app/ 全体の走査結果 (1 回だけ実行してテスト間で使い回す)。
+ *
+ * @return array{
+ *     files: int,
+ *     edges: array<string, list<string>>,
+ *     implementors: array<string, list<string>>,
+ *     payment: array<string, list<array{symbol: string, descriptor: string}>>,
+ *     dynamic: array<string, list<string>>,
+ *     edgeCount: int,
+ * }
+ */
+function deletionPathScanApp(): array
+{
+    /**
+     * @var array{
+     *     files: int,
+     *     edges: array<string, list<string>>,
+     *     implementors: array<string, list<string>>,
+     *     payment: array<string, list<array{symbol: string, descriptor: string}>>,
+     *     dynamic: array<string, list<string>>,
+     *     edgeCount: int,
+     * }|null $cache
+     */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $files = PhpReferenceScanner::phpFiles(base_path('app'), 'app');
+
+    $edges = [];
+    $implementors = [];
+    $payment = [];
+    $dynamic = [];
+    $edgeCount = 0;
+
+    foreach ($files as $relativePath => $source) {
+        $scan = deletionPathScanSource($relativePath, $source);
+        $edges[$scan['class']] = $scan['edges'];
+        $payment[$scan['class']] = $scan['payment'];
+        $dynamic[$scan['class']] = $scan['dynamic'];
+        $edgeCount += count($scan['edges']);
+        foreach ($scan['implements'] as $interface) {
+            $implementors[$interface][] = $scan['class'];
+        }
+    }
+
+    $cache = [
+        'files' => count($files),
+        'edges' => $edges,
+        'implementors' => $implementors,
+        'payment' => $payment,
+        'dynamic' => $dynamic,
+        'edgeCount' => $edgeCount,
+    ];
+
+    return $cache;
+}
+
+/**
+ * 起点から辿れる app/ 内クラスの閉包 (ソート済み)。
+ *
+ * @return list<string>
+ */
+function deletionPathClosure(): array
+{
+    $scan = deletionPathScanApp();
+
+    $roots = array_map(deletionPathRootClass(...), DELETION_PATH_ROOTS);
+
+    return deletionPathTraverse($roots, $scan['edges'], $scan['implementors']);
+}
+
+/**
+ * 閉包の到達計算 (純関数。fixture から合成データで検証できるよう切り出してある)。
+ *
+ * @param  list<string>  $roots
+ * @param  array<string, list<string>>  $edges  クラス => 参照先クラス
+ * @param  array<string, list<string>>  $implementors  interface => 実装クラス (逆向きの辺)
+ * @return list<string>
+ */
+function deletionPathTraverse(array $roots, array $edges, array $implementors): array
+{
+    $queue = $roots;
+    $seen = [];
+
+    while ($queue !== []) {
+        $class = array_shift($queue);
+        if (array_key_exists($class, $seen) || ! array_key_exists($class, $edges)) {
+            continue;
+        }
+        $seen[$class] = true;
+        foreach ($edges[$class] as $next) {
+            $queue[] = $next;
+        }
+        // interface を経由した container binding 越しの到達 (逆向きの辺)。
+        foreach ($implementors[$class] ?? [] as $implementor) {
+            $queue[] = $implementor;
+        }
+    }
+
+    $closure = array_keys($seen);
+    sort($closure);
+
+    return $closure;
+}
+
+/** `FQCN::method` からクラス部分を取り出す。 */
+function deletionPathRootClass(string $root): string
+{
+    $position = strpos($root, '::');
+
+    return $position === false ? $root : substr($root, 0, $position);
+}
+
+/** `FQCN::method` からメソッド部分を取り出す。 */
+function deletionPathRootMethod(string $root): string
+{
+    $position = strpos($root, '::');
+
+    return $position === false ? '' : substr($root, $position + 2);
+}
+
+// ---------------------------------------------------------------------------
+// 検査
+// ---------------------------------------------------------------------------
+
+test('検査 1: 退会経路の依存閉包は目録と exact-fit で一致する', function (): void {
+    $closure = deletionPathClosure();
+    $inventory = DELETION_PATH_CLOSURE;
+    sort($inventory);
+
+    $missing = array_values(array_diff($closure, $inventory));
+    $stale = array_values(array_diff($inventory, $closure));
+
+    expect(['未登録' => $missing, '残骸' => $stale])->toBe(['未登録' => [], '残骸' => []],
+        '退会経路の依存閉包が変わりました。DELETION_PATH_CLOSURE を更新する前に'
+        .'「この依存は本当に退会経路に必要か」「決済事業者へ到達しないか」をレビューしてください。');
+});
+
+test('検査 2: 閉包内のどのクラスも決済事業者記号を参照しない', function (): void {
+    $payment = deletionPathScanApp()['payment'];
+    $exemptions = array_map(
+        static fn (DeletionPathSeamExemption $case): string => $case->value,
+        DeletionPathSeamExemption::cases(),
+    );
+
+    $violations = [];
+    foreach (deletionPathClosure() as $class) {
+        foreach ($payment[$class] ?? [] as $hit) {
+            // 免除キーは `{クラス FQCN}#{symbol}`。symbol は行番号を含まない安定キーで、
+            // enum の docblock が宣言している書式と同一である (行移動で免除が壊れない)。
+            if (in_array($class.'#'.$hit['symbol'], $exemptions, true)) {
+                continue;
+            }
+            $violations[] = $class.' : '.$hit['descriptor'];
+        }
+    }
+
+    expect($violations)->toBe([],
+        '退会経路の依存閉包から決済事業者記号へ到達しています。退会経路は決済事業者 API を呼びません '
+        .'(T115: 自 DB と外部サービスの二重書き込みを避ける / 解約を代行しない)。'
+        .'やむを得ない場合のみ DeletionPathSeamExemption へ 30 文字以上の根拠つきで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 3: 免除は型付き enum + 30 文字以上の根拠で、現在 0 件ちょうど', function (): void {
+    $cases = array_map(
+        static fn (DeletionPathSeamExemption $case): string => $case->value,
+        DeletionPathSeamExemption::cases(),
+    );
+    $keys = array_keys(DELETION_PATH_SEAM_EXEMPTION_RATIONALES);
+    sort($cases);
+    sort($keys);
+
+    expect($keys)->toBe($cases,
+        'DeletionPathSeamExemption に case を足したら DELETION_PATH_SEAM_EXEMPTION_RATIONALES へも'
+        .'同じ value をキーに根拠を登録してください (免除を型と根拠の両方で縛るための二重宣言です)。');
+
+    $short = [];
+    foreach (DELETION_PATH_SEAM_EXEMPTION_RATIONALES as $value => $rationale) {
+        if (mb_strlen($rationale) < 30) {
+            $short[] = $value.': 根拠が '.mb_strlen($rationale).' 文字';
+        }
+    }
+    expect($short)->toBe([], implode(PHP_EOL, $short));
+
+    // exact-fit cap: 余裕枠は「根拠なしに免除できる枠」になるため 1 でも持たせない。
+    expect($cases)->toHaveCount(0,
+        '免除は現在 0 件です。増やす場合はこの cap も同時に更新し、増やした理由をレビューで残してください。');
+});
+
+test('検査 4: 走査が空振りしていない', function (): void {
+    $scan = deletionPathScanApp();
+
+    expect($scan['files'])->toBeGreaterThan(300, '走査対象ファイルが想定より少ない (ディレクトリ構成の変更を疑う)');
+    expect($scan['edgeCount'])->toBeGreaterThan(0, '到達辺を 1 件も解決できていない (走査が死んでいる)');
+
+    $closure = deletionPathClosure();
+    expect(count($closure))->toBeGreaterThan(1, '閉包が起点だけになっている (辺の解決が死んでいる)');
+
+    // 起点が実在すること (クラス名 / メソッド名のタイポで空振りしない)。
+    foreach (DELETION_PATH_ROOTS as $root) {
+        $class = deletionPathRootClass($root);
+        $method = deletionPathRootMethod($root);
+        expect(class_exists($class))->toBeTrue("起点クラスが実在しません: {$class}");
+        expect(method_exists($class, $method))->toBeTrue("起点メソッドが実在しません: {$root}");
+        expect($closure)->toContain($class);
+    }
+
+    // 閉包の要素がすべて実在の型であること (PSR-4 導出の健全性)。
+    $unresolved = array_values(array_filter(
+        $closure,
+        static fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)
+            && ! trait_exists($class) && ! enum_exists($class),
+    ));
+    expect($unresolved)->toBe([], '閉包に実在しない型が含まれます (PSR-4 導出の破綻): '.implode(', ', $unresolved));
+});
+
+test('検査 5: 自己参照コントロール (本 gate 自身は記号 hit なし・辺も exact-fit)', function (): void {
+    $self = 'tests/Architecture/AccountDeletionPathGateTest.php';
+    $source = file_get_contents(base_path($self));
+    Assert::string($source, '本 gate 自身を読み込めません');
+
+    $scan = deletionPathScanSource($self, $source);
+
+    // 説明コメント・nowdoc fixture は正規化で落ちるため記号 hit にならない。
+    expect($scan['payment'])->toBe([],
+        '本 gate 自身が決済事業者記号を持っています (コメントで偽赤になっていないか確認してください)。');
+    expect($scan['dynamic'])->toBe([]);
+
+    // ★「到達 0 件」ではなく **exact-fit** で pin する。本ファイルは免除 enum を import するので
+    //   辺は 1 本ある。0 件を主張すると実装より強い保証を謳うことになる (impl-review Round 1)。
+    //   nowdoc fixture 内の `App\...` は code token にならないため辺に現れない = 自己汚染しない。
+    expect($scan['edges'])->toBe(['App\Enums\Security\DeletionPathSeamExemption'],
+        '本 gate が app/ の型を追加参照しています。nowdoc fixture が code として走査されていないか確認してください。');
+});
+
+test('検査 6: 閉包内に動的メソッド名の呼び出しは 0 件', function (): void {
+    $dynamic = deletionPathScanApp()['dynamic'];
+
+    $violations = [];
+    foreach (deletionPathClosure() as $class) {
+        foreach ($dynamic[$class] ?? [] as $site) {
+            $violations[] = $class.' : '.$site;
+        }
+    }
+
+    expect($violations)->toBe([],
+        '退会経路の閉包に動的メソッド名の呼び出しがあります。名前が字句的に確定しないため'
+        .'決済事業者記号の照合を迂回できます (deny-by-default で 0 件に pin しています)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 7: Cashier API 名の導出が生きており、ローカル allowlist は exact-fit で根拠つき', function (): void {
+    $api = deletionPathPaymentApiMethods();
+
+    // 導出が死んでいないこと (Cashier の API 表面は数十件ある)。
+    expect(count($api))->toBeGreaterThan(50, 'Cashier API 名の導出が壊れています (リフレクションの前提を確認)');
+    expect($api)->toHaveKey('newsubscription')
+        ->and($api)->toHaveKey('charge')
+        ->and($api)->toHaveKey('cancelnow');
+
+    $violations = [];
+    foreach (DELETION_PATH_CASHIER_LOCAL_METHODS as $name => $rationale) {
+        if (str_contains($name, 'stripe')) {
+            $violations[] = "{$name}: 名前に stripe を含むメソッドは allowlist へ載せられません";
+        }
+        if (mb_strlen($rationale) < 30) {
+            $violations[] = "{$name}: 根拠が ".mb_strlen($rationale).' 文字';
+        }
+        if (! method_exists(Billable::class, $name)
+            && ! method_exists(Subscription::class, $name)
+        ) {
+            $violations[] = "{$name}: Cashier に実在しないメソッド名です (残骸)";
+        }
+        if (array_key_exists($name, $api)) {
+            $violations[] = "{$name}: allowlist が効いていません";
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+
+    // ★**exact-fit cap**。根拠文さえ書けば `charge` / `cancelNow` を allowlist へ足して
+    //   検出面を静かに狭められる (fail-open) ため、キー集合そのものを別宣言で pin する
+    //   = 検出面を狭めるには 2 箇所を触らせる (impl-review Round 1 [Critical])。
+    $allowlist = array_keys(DELETION_PATH_CASHIER_LOCAL_METHODS);
+    sort($allowlist);
+    expect($allowlist)->toBe(['active', 'subscriptions', 'user'],
+        'Cashier API のローカル判定 allowlist を変えるときは、この pin も同時に更新してください'
+        .' (allowlist に足すことは決済到達の検出面を狭めることと同義です)。');
+});
+
+test('検査 8: 起点集合は exact-fit で pin される', function (): void {
+    // ★起点を静かに減らすと閉包が縮み、以後の到達検出が丸ごと消える。
+    //   PR-B (猶予期間つき削除) で PurgeDeletionRequestsCommand::handle を 3 本目として足すときは
+    //   この pin も同時に更新する (意図的な摩擦)。
+    expect(DELETION_PATH_ROOTS)->toBe([
+        'App\Http\Controllers\Settings\AccountController::destroy',
+        'App\Services\Organization\OrganizationMembershipService::deleteAccount',
+    ], '退会経路の起点を変えるときは、なぜ変えるのかをレビューで残してください。');
+});
+
+test('検査 9: redaction 記録コマンドは決済事業者記号を参照しない (記録専用の静的固定)', function (): void {
+    // ★A2 の「決済事業者 API を呼ばない」は Feature テストの StripeGatewayInterface mock だけでは
+    //   足りない (Cashier / Stripe SDK を直接使えば mock を経由しない)。記録コマンドは退会経路の
+    //   閉包には入らないので、ここで名指しの静的検査を置く (impl-review Round 1 [Warning])。
+    $relativePath = 'app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php';
+    $source = file_get_contents(base_path($relativePath));
+    Assert::string($source, 'redaction 記録コマンドを読み込めません');
+
+    $scan = deletionPathScanSource($relativePath, $source);
+
+    expect($scan['payment'])->toBe([],
+        'redaction の記録コマンドが決済事業者記号を参照しています。このコマンドは**記録専用**で'
+        .'決済事業者 API を呼びません (実施は人手。docs/account-deletion-runbook.md)。');
+    expect($scan['dynamic'])->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// 正負コントロール fixture
+// fixture は nowdoc (文字列トークン) なので本ファイルの走査では code にならない (検査 5)。
+// ---------------------------------------------------------------------------
+
+/**
+ * hit の symbol 一覧 (fixture の assert 用)。
+ *
+ * @param  list<array{symbol: string, descriptor: string}>  $hits
+ * @return list<string>
+ */
+function deletionPathSymbols(array $hits): array
+{
+    return array_values(array_map(static fn (array $hit): string => $hit['symbol'], $hits));
+}
+
+test('負のコントロール 1 形目: 型宣言だけの注入を検出する', function (): void {
+    // laravel-claude-template で実際に fail-open していた形。呼び出しが 1 つも無くても赤くする。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use Stripe\StripeClient;
+    class Fixture {
+        public function __construct(private readonly StripeClient $stripeClient) {}
+        public function run(\Stripe\Customer $customer): void {}
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))
+        ->toContain('Stripe\StripeClient')
+        ->toContain('Stripe\Customer');
+});
+
+test('負のコントロール 2 形目: facade 経由の client 取得を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use Laravel\Cashier\Cashier;
+    class Fixture {
+        public function run(): void { Cashier::stripe()->customers->delete('cus_1'); }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))
+        ->toContain('Laravel\Cashier\Cashier')
+        ->toContain('Laravel\Cashier\Cashier::stripe()');
+});
+
+test('負のコントロール 3 形目: 完全修飾の static 呼び出しを検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(): void { \Stripe\Customer::retrieve('cus_1'); }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))
+        ->toContain('Stripe\Customer')
+        ->toContain('Stripe\Customer::retrieve()');
+});
+
+test('負のコントロール 4 形目: app() / resolve() / make() の literal 引数を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(\Illuminate\Contracts\Container\Container $container): void {
+            app('cashier.stripe');
+            resolve('stripe.client');
+            $container->make('Stripe\StripeClient');
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))->toBe([
+        'container:cashier.stripe',
+        'container:stripe.client',
+        'container:Stripe\StripeClient',
+    ]);
+});
+
+test('負のコントロール 5 形目: trait / import 経由の到達を辺として拾う', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use App\Support\Billing\SomeBillingTrait;
+    class Fixture {
+        use SomeBillingTrait;
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['edges'])->toContain('App\Support\Billing\SomeBillingTrait');
+});
+
+test('負のコントロール 5 形目 (b): クラス本体の use が先頭 import を上書きしても辺を失わない', function (): void {
+    // ★`PhpReferenceScanner` の alias マップは `use App\...\Foo;` と クラス本体の `use Foo;` を
+    //   同じ短縮キーで扱うため、後者が前者を上書きして FQCN を失う。alias マップを辺に使うと
+    //   **trait 経由の到達が丸ごと消える** (fail-open)。トークン直読みでこれを防いでいることを固定する。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Models;
+    use App\Models\Concerns\ShadowedTrait;
+    class Fixture {
+        use ShadowedTrait;
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Models/Fixture.php', $fixture);
+    // 前提の実測: alias マップ側は上書きで短縮名に潰れている (この前提が崩れたら本テストは不要になる)。
+    expect($result->imports['shadowedtrait'] ?? null)->toBe('ShadowedTrait');
+
+    $scan = deletionPathScanSource('app/Models/Fixture.php', $fixture);
+    expect($scan['edges'])->toContain('App\Models\Concerns\ShadowedTrait');
+});
+
+test('負のコントロール 6 形目: 動的メソッド名を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(object $billable, string $method): void {
+            $billable->{$method}();
+            $billable->$method();
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['dynamic'])->toHaveCount(2);
+});
+
+test('負のコントロール 7 形目: literal の動的メソッド名も記号照合に載せる', function (): void {
+    // ★`->{'stripe'}()` は名前が字句的に確定するので**動的扱いにしない**。
+    //   動的にも記号にも載せないと、この書き方 1 つで検出を素通りできる (fail-open)。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(object $billable): void {
+            $billable->{'stripe'}();
+            $billable->{'charge'}(100);
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))->toBe(['->stripe()', '->charge()']);
+    // literal なので「動的」には数えない (二重計上しない)。
+    expect($scan['dynamic'])->toBe([]);
+});
+
+test('負のコントロール 8 形目: interface 経由の container binding 越しの到達を閉包へ引き込む', function (): void {
+    // ★退会経路が interface を型注入し、service provider が concrete へ bind している形。
+    //   型宣言の辺だけでは interface で止まり、実装側の決済事業者記号に到達しない
+    //   (impl-review Round 1 [Critical])。implements の逆向きの辺で閉包へ引き込む。
+    $consumer = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use App\Contracts\BillingRedactor;
+    class Consumer {
+        public function __construct(private readonly BillingRedactor $redactor) {}
+    }
+    PHP;
+    $implementation = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    use App\Contracts\BillingRedactor;
+    use Stripe\StripeClient;
+    class StripeBillingRedactor implements BillingRedactor {
+        public function __construct(private readonly StripeClient $client) {}
+    }
+    PHP;
+    $interface = <<<'PHP'
+    <?php
+    namespace App\Contracts;
+    interface BillingRedactor {}
+    PHP;
+
+    $consumerScan = deletionPathScanSource('app/Services/Organization/Consumer.php', $consumer);
+    $implementationScan = deletionPathScanSource('app/Services/Billing/StripeBillingRedactor.php', $implementation);
+    $interfaceScan = deletionPathScanSource('app/Contracts/BillingRedactor.php', $interface);
+
+    expect($implementationScan['implements'])->toBe(['App\Contracts\BillingRedactor']);
+
+    $edges = [
+        $consumerScan['class'] => $consumerScan['edges'],
+        $implementationScan['class'] => $implementationScan['edges'],
+        $interfaceScan['class'] => $interfaceScan['edges'],
+    ];
+    $implementors = ['App\Contracts\BillingRedactor' => [$implementationScan['class']]];
+
+    $closure = deletionPathTraverse([$consumerScan['class']], $edges, $implementors);
+
+    expect($closure)->toContain('App\Services\Billing\StripeBillingRedactor');
+    expect(deletionPathSymbols($implementationScan['payment']))->toContain('Stripe\StripeClient');
+});
+
+test('正のコントロール: 基底クラスの継承は逆向きに辿らない (信号を殺さない)', function (): void {
+    // ★`implements` だけを逆向きの辺にする。`extends` を辿ると
+    //   `AccountController extends Controller` から app/ の全 Controller が閉包に入り信号が死ぬ。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Http\Controllers\Other;
+    use App\Http\Controllers\Controller;
+    class OtherController extends Controller {}
+    PHP;
+
+    $scan = deletionPathScanSource('app/Http/Controllers/Other/OtherController.php', $source);
+
+    expect($scan['implements'])->toBe([]);
+    // 実アプリの閉包にも兄弟 Controller は入っていない (検査 1 の目録が 53 件に収まっている根拠)。
+    expect(deletionPathClosure())->not->toContain('App\Http\Controllers\Settings\ProfileController');
+});
+
+test('正のコントロール: コメント・文字列中の決済事業者記号を誤検出しない', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    /** Stripe\StripeClient を呼ばないことがこのクラスの契約である (Cashier::stripe も同様)。 */
+    class Fixture {
+        public function run(): void {
+            $note = 'Stripe\StripeClient';
+            // Cashier::stripe()->customers->delete($id);
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->toBe([]);
+});
+
+test('検査: mutation 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
+    expect(array_keys(DELETION_PATH_MUTATION_COVERAGE))->toBe(DELETION_PATH_MUTATION_IDS);
+});

```

## 再検証結果 (修正後)

- `composer phpstan` : OK (841 files, No errors)
- `vendor/bin/pint --test` : passed
- `composer test` : 4145 tests / 4143 passed / 2 skipped / 0 failed (17795 assertions)
- `AccountDeletionPathGateTest` 単体: 21 tests / 21 passed
- 実アプリの閉包は **53 クラスのまま変化なし** (implements 逆向き辺の追加で今日増えるものは無い)

## 質問

1. Round 1 の [Critical] 2 件・[Warning] 5 件・[Suggestion] 2 件への対応は十分か。
2. **見送った [Suggestion] (`--customer=` 照合)** の判断は妥当か。妥当でないなら理由を挙げよ。
3. **既存行を書き換えず「畳む」形にした architecture.md の対応**は、運用文書として許容できるか。
4. 新たに入れた `implements` 逆向きの辺に、**新しい fail-open や偽陽性**が生まれていないか。
   特に「interface が閉包に入ったら実装を全部引き込む」ことで信号が死ぬ危険はないか。
5. 他に **[Critical]** が残っているか。

全体判定 (APPROVED / CHANGES_REQUESTED) を最後に 1 行で書け。
