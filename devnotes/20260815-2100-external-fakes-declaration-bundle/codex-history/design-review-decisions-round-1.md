# 対応マトリクス: design-review Round 1

## [Warning] 施策 1: 3-15 の `config('testing')` 完全一致は過剰
- 判断: 対応する
- 根拠: 指摘のとおり。偽物と無関係な testing 設定を足せなくなる。
- 対応内容: 3-15 を (a)「宣言の config キーが `config('testing')` に全件存在する」+
  (b)「`config/testing.php` に現れる `TESTING_FAKE_*` の環境変数名の集合が宣言と一致する」
  へ差し替え、完全一致は要求しないと明記した。

## [Warning] 施策 1: `warnIfExternalsFlagIsUnusable()` の仕様が無い
- 判断: 対応する
- 根拠: 既存テストが警告回数を `once()` で固定しているため、仕様を書かないと実装で崩れる。
- 対応内容: 変更後コードに private method の全文を追加。条件 (外部フラグが true ∧ capability の
  許可環境の外) / 1 度だけ / 外部ログイン・storage・LLM では警告しないことを明記した。

## [Suggestion] 施策 1: `neverSwapped()` と参照走査の責務分担
- 判断: 対応する
- 対応内容: docblock に「本メソッドが止めるのは宣言へ足すことだけ。本番コードの参照走査は
  `FakeClassReferenceInvariantTest`、外部到達点の目録は `ExternalSeamInventory` が担う」を追記。

## [Warning] 施策 2: S-6 の検出力 (論理退行を読めない)
- 判断: 対応する (ただし `&&` の要求はしない)
- 根拠: 現行のガードは「否定の論理和 → 早期 return」の形なので、`&&` を要求する検査は誤りになる。
  静的に論理を読むのは費用対効果が悪い。本リポジトリには「免除の前提を振る舞いテストで固定し、
  目録から名指しする」先例 (`ThrottleExemptionPremiseTest` /
  `IdempotencyExemptionPremiseTest`) があるので、その作法へ寄せる。
- 対応内容: 目録に `guardPremiseTest()` (論理を固定する振る舞いテストのパス) を持たせ、
  S-9 (実在確認) を追加。S-6 に「早期 `return` があること」を足し、負のコントロールも増やした。
  保証範囲の記述も「`&&` を要求しない理由」まで書いた。

## [Warning] 施策 2: `ShellFunctionWindow::of()` の終端が `cmd_` 前提
- 判断: 対応する
- 対応内容: メソッド名を `ofCommand()` にし、`cmd_` で始まらない名前は例外にする
  (誤用の負のコントロールも追加)。`cmd_` 以外の関数窓は既存テストの別の切り出しを
  そのまま使い、2 つを統合しないことを明記した。

## [Critical] 施策 3: `array<string, string>` と「非文字列は違反」が矛盾
- 判断: 対応する
- 根拠: 指摘のとおり型が破綻する。
- 対応内容: `rawEnvironmentValues(): array<string, mixed>` /
  `isUnambiguouslyDisabled(mixed $raw): bool` へ変更し、メッセージ生成時のみ
  `var_export()` で文字列化することを明記した。

## [Warning] 施策 3: `putenv()` の空文字と未設定の差が環境依存
- 判断: 対応する
- 対応内容: 未設定 / 空文字 / `'false'` を別ケースとして固定し、退避と復元を 1 つのヘルパへ
  集約する (`$_SERVER` / `$_ENV` は `unset()` と `= ''` を作り分け、`getenv()` 側は
  `putenv("{$name}")` で未設定へ戻す) と明記した。

## [Critical] 施策 4: P-3 の「本物と厳密一致」が脆い
- 判断: 一部反論する (表明は維持し、根拠と失敗時の読み方を設計へ書く)
- 根拠 (実読で確認):
  - 本物側の 3 本 (`TicketCheckoutGateway` / `StripeGatewayInterface` /
    `AutoRechargeGatewayInterface`) は `AppServiceProvider::register()` で**無条件に**
    bind されている (L122 / L126 / L130)。残り 4 件は具象クラスで自動組み立てできる。
  - `CashierStripeGateway::__construct()` は `SubscriptionSnapshotMapper` だけを受け取り、
    Stripe の資格情報を要求しない (解決時に外部へ出ない)。
  - **同じ表明は in-process の 3-1 が既に緑で持っている**。別プロセスで落ちるなら
    「別プロセスでだけ本物が解決できない」という本物の信号であり、隠すべきではない。
- 対応内容: 表明は維持。リスク欄に上記の根拠と「解決が例外になっても probe が理由を返して
  赤くなる (静かに緑にしない)」を追記した。

## [Critical] 施策 4: probe の `redirect()` が provider 初期化へ踏み込む / 実 IdP secret
- 判断: 対応する
- 根拠: 対照 (フラグ無効) でまで転送先を組み立てる必要は無い。子へ実資格情報を渡さない
  前提も明文化すべきである。
- 対応内容: 転送先の組み立てを**偽物が有効なときだけ**行うよう probe を変更 (P-3 は
  解決クラスだけを観測)。子プロセスへ渡す環境変数を `APP_ENV` / 3 フラグ / `APP_KEY` /
  `CIPHERSWEET_KEY` に限ると明記した。

## [Warning] 施策 4: P-4 が `AppServiceProvider::boot()` の順序に依存する
- 判断: 一部対応する (新しい gate は作らない)
- 根拠: 順序専用の Architecture テストを新設するのは、本件の目的に対して機構が過剰である
  (思考原則 2)。順序に依存する事実を隠さず、赤で気づける形にすれば足りる。
- 対応内容: P-4 を 2 段の表明にした — (a) 非ゼロ終了 (順序に依存しない) /
  (b) メッセージに `TESTING_FAKE_EXTERNALS` が現れる (順序に依存する)。(b) が落ちたときの
  失敗メッセージに「起動時検査の順序が変わった可能性」を書く。

## [Suggestion] 施策 5: `app-update-docs` の対象という受入条件が不確か
- 判断: 対応する
- 対応内容: 当該行を削除し、「文書側に専用の機械検査は足さない (機械検査は施策 2 が持つ)」
  へ書き換えた。
