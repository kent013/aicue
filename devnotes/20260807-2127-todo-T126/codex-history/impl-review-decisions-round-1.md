# impl-review Round 1 の指摘に対する判断 (T126)

model=`gpt-5.5` / reasoning=`high` / label=`impl-review` / 全体判定 **CHANGES_REQUESTED**

| # | 種別 | 指摘 | 判断 | 対応 |
|---|---|---|---|---|
| 1 | [Critical] | `DefaultDiskWithoutAwsClient` / `InjectedPinnedControlClient` の免除条件が enum の docblock 上の約束だけで、gate に機械検査されていない。特に既定 disk が env で `s3` を指すと S3 集約 adapter を迂回した外部到達が目録のまま通る | **受諾** | (a) `EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS` を新設し、免除理由ごとに「名乗るなら検出されてはいけない規則 (`forbidden`) / 検出されなければならない規則 (`required`)」を宣言して走査結果と突き合わせる検査 `到達境界: 免除理由の適用条件が走査結果と矛盾しない` を追加。(b) 既定 disk が `s3` を指す場合に備え、「特定の disk 名」ではなく **`driver=s3` の disk 全件**が pin を宣言していることを要求する検査 `AWS config: driver=s3 の disk はすべて http / retries を宣言する` を追加。(c) enum の docblock に「既定 disk が `s3` を指せばこの層も S3 へ到達する。そのときの待ちは**データ系の帯**を継承する = 有界だが長い」と**誇張しない**保証範囲を明記。mutation M21 / M22 で両 gate の実効性を確認 |
| 2 | [Warning] | 設計で代表経路に含まれていた「customer 新規」経路が実装テストから落ちている (helper が常に `stripe_id` を保存するため `createOrGetStripeCustomer` の create 側を behavioral に固定できていない) | **受諾** | `stripeBudgetPendingAttempt()` に `$withStripeCustomer` 引数を足し、dataset に `成功 (customer 新規 = createOrGetStripeCustomer の create 側へ入る経路)` を追加。**回数一致だけでは分岐を取り違えても green になる** (両分岐とも 5 回) ため、既に診断用にあった `CountingStripeHttpClient::$requestedUrls` を検査へ昇格させ、`expectedFirstRequest` (`post https://api.stripe.com/v1/customers` か `get https://api.stripe.com/v1/customers/cus_gate` か) で**どの分岐へ入ったか**も固定した |
| 3 | [Suggestion] | 提示 diff に `mprocs.yaml` の hunk が無い (`--timeout=540 → 300`) | **事実確認のみ** | Codex へ渡した差分の生成対象ディレクトリに `mprocs.yaml` が含まれていなかっただけで、実装には含まれている (`git diff` で `--timeout=300` を確認済み)。`時間予算: mprocs の database worker --timeout が定数と一致する` が値の一致を機械固定しており、mutation M12 で赤化も確認済み。**コード変更なし** |

## 蒸し返さない判断 (設計合議で確定済み)

- Stripe の pin は **プロセス大域** (`ApiRequestor::setHttpClient`) 以外に置き場が無い
  (`BaseStripeClient::DEFAULT_CONFIG` に timeout 系キーが無い)。テナント別 timeout は持たない。
- provider は `CurlClient::instance()` ではなく `new CurlClient` を使う
  (シングルトンを書き換えると「誰が設定したか」が追えず、テストの復元先も曖昧になる)。
- `AWS_S3_TIMEOUT_SECONDS = 900` は短くできない (Flysystem の write 経路は `@http` を
  per-command で転送しないため、client 既定がデータ系を賄う必要がある)。
- 「`Bulk` 面を web 同期経路から呼ばない」は**規約であって機械証明ではない**
  (呼び出しグラフ解析が要る)。既存の web 経路のみ behavioral に固定する。
