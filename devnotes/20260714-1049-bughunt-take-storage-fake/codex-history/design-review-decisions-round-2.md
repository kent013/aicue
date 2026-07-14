# 対応マトリクス: design-review Round 2

## [Critical] 施策3: 上書き PUT で旧 sidecar が残り「新 object + 旧 meta」を完了扱いする
- 判断: 対応する
- 根拠: object 確定〜新 sidecar 書込みの間、head() が新 object + 旧 meta を complete として返す。sidecar 書込み失敗で不整合が永続。
- 対応内容: `promote()` を「**既存 sidecar 削除 → object rename → sidecar 作成**」順に変更。sidecar 不在期間は未完了 = `head()===null`。テスト追加: 上書き / sidecar 書込み失敗 / 並行 HEAD。

## [Critical] 施策7: provider の capability 別 early return で storage が実行されない
- 判断: 対応する
- 根拠: `register()` は `fake_externals !== true` で early return、`boot()` は `fake_llm !== true` で early return。後ろに storage を追記すると storage flag 単独では bind も route も走らない。
- 対応内容: Stripe / LLM / storage を**独立した private method**（`registerPaymentFakes` / `bootLlmFake` / `registerStorageFakes` / `bootStorageRoutes`）へ分離し、各 capability の guard がメソッド全体を return させない構造にする。既存 Stripe/LLM 挙動は不変。

## [Critical] 施策9: withFakeStorage() が bind/route を手動再実装し provider の欠陥を隠す
- 判断: 対応する
- 根拠: 手動再配線ヘルパだと今回の early-return バグもテストが通ってしまう。
- 対応内容: **provider 配線そのものを検証する統合テスト**を追加。`fake_externals=false・fake_llm=false・fake_storage=true` だけで bind と route が成立することを、**env を投入してアプリを再生成**（`$app` 再作成 = provider の register/boot を実際に走らせる）して検証する。Feature ヘルパも手動再配線ではなく「env セット → refreshApplication」で provider を実走させる方式へ変更（route 二重登録の隠蔽を避ける）。

## [Warning] 施策3: json_decode の戻りを array と断定した PHPDoc は PHPStan を黙らせている
- 判断: 対応する
- 対応内容: `mixed` で受け `Assert::isArray($data)` で実行時 narrow してからキーを読む。

## [Warning] 施策5: 親 disk() 抽象化方針と提示コードが不一致（fake が disk 直書きのまま）
- 判断: 対応する
- 対応内容: fake は `protected function disk(): Filesystem { return Storage::disk(FakeObjectStore::DISK); }` のみ override。`downloadToLocal()` は親を継承（disk() 経由）。`upload()` だけ sidecar 生成のため override。`temporaryPlaybackUrl`/`temporaryDownloadUrl`/`delete` は署名 URL・store 委譲のため override。

## [Warning] 施策9: reflection 契約を「全 public override」にすると意図的継承（contentDisposition/keyPrefixFor）と矛盾
- 判断: 対応する
- 対応内容: reflection テストは「**S3 到達性を持つメソッドの明示 inventory**」を override 必須として検証する（`contentDisposition()`/`keyPrefixFor()` は inventory 外 = 継承 OK）。

## [Warning] 施策9: route 同名再登録を無害扱いしない
- 判断: 対応する
- 対応内容: 上記 C3 対応（env セット → refreshApplication で新 application）に統合。テスト間の route collection リークを避ける。

## [Suggestion] 施策6: FakeStorageKey をセグメント単位で検証
- 判断: 対応する
- 対応内容: `/` 分割し、空 segment・`\`・NUL・`.`/`..` segment を拒否。単純 `str_contains('..')` の誤検知を避ける。

## [Suggestion] 施策3: putStreamWithMeta に take 用 max_take_bytes 流用は概念不一致
- 判断: 対応する（cap を分離）
- 根拠: render 出力は take と別概念（複数テイク連結で take 上限を超え得る）。かつ入力は**サーバ生成の信頼できるローカルファイル**（攻撃者の php://input ではない）。
- 対応内容: `streamInto` の cap を `?int $maxBytes`（null = 上限なし）に一般化。`storeStreamed`（攻撃者入力）は `capture.max_take_bytes` を渡し、`putStreamWithMeta`（信頼できる内部ファイル）は cap なし（null）。新 config 知識は増やさない（過剰設計回避）。
