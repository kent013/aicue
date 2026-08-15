提示された改訂設計のみを対象にレビューしました。

## 施策1: APPROVE

Round 3 の指摘は解消されています。`schema_error` を最優先に評価するため、不正な `run_id` が単なる不一致として誤報される問題もありません。

コンテナ型、行型、status の hashability、終了コード1と3の境界、旧形式救済の削除まで一貫しています。

## 施策2: APPROVE

`StartSession::handleStatefulRequest()` のレスポンス巻き戻り時にセッションが保存され、その後のKernel terminate処理で記録器が読む、という説明に修正されています。

短絡middlewareのdeny-by-default分類、全routeの順序検査、behavioral testによる裏取りも十分です。DTO/JsonResourceおよびInertia Propsは本件の内部観測処理には適用不要です。

## 施策3: APPROVE

`prepare → health check → assert → finalize` の順序により、以下の両方が解消されています。

- 古いJSONL行を今回の同期点と誤認する問題
- 応答受信後、遅れて疎通確認行が追記される問題

古い行を置いた負の対照、遅延追記を待つテスト、無記録・`.error` の失敗テストも揃っています。

## 施策4: APPROVE

schema検証、shard単位の可用性、名前付きrouteの必須化、同一ディレクトリ内のatomic replace、失敗時の既存出力保護まで整合しています。

## 施策5: REQUEST_CHANGES

[Warning] 新設するstale語彙gateが、施策6の実行レーンに登録されていません。

`test_naming_no_stale.py` に検査と自己テストを追加しても、施策6のコマンドは次の2モジュールしか実行しません。

```bash
python3 -m unittest test_correlate test_build_executed
```

このままでは、禁止語が再混入しても`composer test`がgreenになる可能性があり、「不変条件はArchitecture/Featureテストへの登録まで含めて実装済み」という規約を満たしません。

修正案: 施策6の実行対象へ追加してください。

```bash
python3 -m unittest \
  test_correlate \
  test_build_executed \
  test_naming_no_stale
```

[Suggestion] 「実装とREADMEから旧語彙を完全に削除」と、READMEへ「旧語彙だった」と記載する部分は表現上矛盾しています。実装時には、READMEにも旧値そのものを書かず「statusは`ok|blocked`のみ」と肯定形で記述するとgateの契約が明確になります。

## 施策6: REQUEST_CHANGES

[Warning] Python自己テストの実行対象が不足しています。

修正案:

- `test_naming_no_stale` を実行モジュールへ加える。
- 設計中の「2つのPythonモジュール」を「3つ」に修正する。
- 負の対照は引き続き存在しないモジュールで確認する。
- 3モジュールすべてのstdout/stderrを失敗メッセージへ含める。

## 全体判定: CHANGES_REQUESTED

実装本体の施策1〜4は承認できます。残る変更は、施策5のstale語彙gateを施策6の`composer test`配下へ登録することです。これは小規模ですが、テスト登録を実装完了条件とするAGENTS.md上、承認前に必要です。