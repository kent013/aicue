# 対応マトリクス: impl-review Round 1

## [Critical] `PropagatesToQueueFailure` の前提が gate で検査されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。件数と根拠長だけを見る gate は「後から `catch (Throwable)` を足して
  `getMessage()` をログへ載せても green」という抜け道を残す。deny-by-default を名乗る以上、
  免除の**前提そのもの**を機械で固定すべきである
  (AGENTS.md が言及する `ThrottleExemptionPremiseTest` と同じ作法が既にリポジトリにある = 先例あり)。
- 対応内容:
  - `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` に **検査 21** を新設。
    `PropagatesToQueueFailure` を宣言したクラスのソースに `catch (` が **0 件**であることを要求する。
    保守的な近似 (gateway 呼び出しを囲む catch かどうかまでは見ない) であることをコメントに明記した。
  - mutation coverage 表に **M11** を追加 (検査 20 の期待 ID 集合も同時に更新)。
  - `docs/architecture.md` §オートリチャージの失敗分類 に検査 21 の契約を追記
    (「gate が強制する」という記述が実態に追いついた)。
  - **mutation M11 で赤化を実測**: `SetDefaultPaymentMethodJob` の gateway 呼び出しを
    `try { … } catch (\Throwable $e) { return; }` で囲むと検査 21 が赤くなる
    (`Failed asserting that 1 is identical to 0.`)。復元済み。

## [Warning] 検査 19 が `use` 文しか見ておらず、完全修飾名で回避できる

- 判断: **対応する**
- 根拠: 指摘のとおり。docs で「4 つに閉じる」と保証を書いた以上、`use` 限定の走査は保証が嘘になる。
- 対応内容: PHP 同梱の `token_get_all()` (tokenizer。vendor 依存を増やさない = AST 不使用の方針と両立)
  で走査する `billingSourceReferencesStripeException()` を追加し、検査 19 をこれに切り替えた。
  - `T_COMMENT` / `T_DOC_COMMENT` を除外する。これは必須だった —
    `app/Services/Billing/Contracts/StripeGatewayInterface.php` の docblock が
    `\Stripe\Exception\ApiErrorException` に言及しており、素の文字列走査だと誤検出になる
    (「docblock で名前を挙げる」ことは「型を知っている」ことではない)。
  - 名前トークンだけでなく文字列リテラルも対象にする (`class_exists('Stripe\Exception\X')` 回避を許さない)。
  - **実測**: `SetDefaultPaymentMethodJob` に `\Stripe\Exception\ApiConnectionException::class` を
    1 行足す (import なし) と検査 19 が赤化することを確認。復元済み。
  - docs にも走査方式 (tokenizer / コメント除外) を明記した。

## [Warning] `Schema::rename` による DB 例外注入が Feature テストとして重い

- 判断: **一部対応する (注入方式は維持し、後片付けを二重化して理由を明記する)**
- 根拠 (反論込み):
  - 取りこぼし起票の catch が受けるのは `maybeCreateAttempt()` の**内側で起きる失敗**だけであり、
    gateway は一切通らない。したがって gateway fixture / hook では到達できない。
  - `AutoRechargeService` は `final` なので、テスト用サブクラスや partial mock で
    `maybeCreateAttempt()` を差し替えることもできない。
  - 残る選択肢は (a) 実 DB を一時的に壊す (b) `DB::listen` から擬似的に throw する の 2 つ。
    (b) は「QueryException が実際に上がった」ことの証明にならない (テストが自分で作った例外を
    自分で観測するだけ) ため、**(a) の方が検査として強い**と判断した。
  - PostgreSQL では DDL がトランザクショナルであり、`RefreshDatabase` の巻き戻しで確実に復元される。
- 対応内容: それでも「失敗時の後片付け」の懸念は正当なので、`try { … } finally { Schema::rename(戻す) }`
  で**明示的に戻す**ようにした (RefreshDatabase の巻き戻しと二重)。
  注入点をここに選んだ理由もテスト内コメントに残した。

## [Warning] `mutation-log.md` が diff に無い

- 判断: **説明する (実物は存在する)**
- 根拠: 設計の記述は「設計 dir へ記録」だが、実装スキルの規約では実装成果物 (mutation ログ /
  impl-review) は**実装用 design_dir** (`devnotes/{YYYYMMDD-HHMM}-todo-{todo_id}/`) に置く。
  実物は `devnotes/20260807-1938-todo-T132/mutation-log.md` に存在し、コミット対象に含まれる。
  Round 1 の diff が `app/ tests/ docs/ AGENTS.md` に限定されていたため見えなかっただけである。
- 対応内容: Round 2 のプロンプトで `mutation-log.md` の全文を添付する。
