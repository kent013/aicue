## 施策1: REQUEST_CHANGES

[Warning] 不正な `run_id` が schema 違反として記録されません。

現在は不正な `run_id` を `None` に変換するだけなので、`executed_row_invalid` ではなく `executed_run_id_mismatch` と誤分類されます。「非空 `str` を要求する」という改訂後の契約とも一致しません。

修正案: `run_id` が非空文字列でなければ `invalid_row` より意味の明確な `invalid_schema` などへ理由を保持し、終了コード3の schema 違反として返してください。`run_id: null`、空文字、数値のテストも追加します。

その他のコンテナ型検証、暗黙の文字列変換廃止、旧status救済の削除は妥当です。

## 施策2: REQUEST_CHANGES

[Warning] `StartSession` の動作説明がLaravelの実装と一致していません。

セッション保存は通常 `StartSession::terminate()` ではなく、`handleStatefulRequest()` が下流のレスポンスを受け取った後、レスポンスの巻き戻り中に `saveSession()` を呼んで行います。その後、Kernelのterminate処理で記録器が読み取ります。結論として古いflashを引きずらない可能性は高いものの、設計に記載された機序が誤っています。

修正案:

- コメントとREADMEを「`StartSession::handleStatefulRequest()` のレスポンス巻き戻り時に保存・世代更新され、その後に記録器の `terminate()` が読む」へ修正する。
- Laravel内部実装への依存であることと、Featureテストで固定する方針は維持する。

middleware順序のdeny-by-default検査、認可・テナント境界との関係、PHPStan対応は問題ありません。

## 施策3: REQUEST_CHANGES

[Critical] 同じrun×shardを再provisionした場合、古いJSONL行が同期点として誤認されます。

現在の実経路は次の順序です。

1. ヘルスチェックを実行
2. `assert_executed_capture_wired`
3. `init_executed_capture`

既存の `{run}-{shard}.jsonl` が非空なら、現在のヘルスチェックの`terminate()`を待たずにassertが成功します。その後truncateし、遅れて現在の `/login` 行が追記されるため、Round 2と同じ競合が再発します。再provision時の`.error`削除をテストする設計なので、run IDの一意性だけには依存できません。

修正案: 初期化を前後2段に分けます。

1. serve起動またはヘルスチェック前に、既存JSONLと`.error`を消す「prepare」を実行
2. ヘルスチェック成功後、現在の要求による行の出現を待つ
3. 行を確認後、探索開始前にJSONLを空にする「finalize」を実行
4. manifest更新はfinalize時に行う
5. dryrunではprepareとfinalizeだけを行う

自己テストには「事前に古い行が存在していても、それを同期成功と誤認せず、背景からの新しい遅延追記を待つ」を追加してください。

## 施策4: APPROVE

行schema、shard整合、名前付きrouteの可用性、同一ディレクトリでのatomic replace、既存出力の保護まで整合しています。DTO/JsonResourceやInertiaの適用対象でもありません。

## 施策5: REQUEST_CHANGES

[Warning] stale語彙gateが、正当に必要な回帰テストや現在のコードコメントを検出します。

提示された `correlate.py` 自体に引用符付きの `'skipped'` が残っています。また、旧status値を拒否するテストでは、入力fixtureとしてその文字列が必要です。自ファイルである `test_naming_no_stale.py` を除外しても、`test_correlate.py` は検出対象に残ります。

修正案:

- 実装・READMEから旧語彙を完全に削除する。
- `test_correlate.py` で旧値を検証する必要がある場合は、そのテストファイルを対象外にするか、文字列を分割して構築する。
- gate自体について「禁止対象ファイル」と「契約変更を検証するテストfixtureの例外」を明示する。
- gateが実装ファイルでは検出し、許可した回帰テストでは自己衝突しないことをテストする。

## 施策6: APPROVE

既存のArchitectureテスト実行方式に整合し、失敗出力と負の対照も含まれています。

## 全体判定: CHANGES_REQUESTED

残る主要な問題は、再provision時の古いJSONLを同期点と誤認する競合です。ヘルスチェック前の事前初期化と、観測後の最終truncateへ分ければ解消できます。併せて、`StartSession`の機序説明、`run_id`のschema分類、stale語彙gateの回帰テスト例外を修正すれば承認可能です。