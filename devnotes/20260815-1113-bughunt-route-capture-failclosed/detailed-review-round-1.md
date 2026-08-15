実ファイル確認は、今回の「コマンド実行禁止」に従って行っていません。以下は提示された設計書・抜粋ベースのレビューです。

**全体判定: CHANGES_REQUESTED**

## 施策 1: 照合器の fail-closed 化

判定: **APPROVE**

[Suggestion] `load_executed()` 側でも `status` / `shard` / `run_id` の型・値をもう少し検査すると堅いです。現状案でも生成器経由なら成立しますが、手編集・旧 fixture 混入時に `None` や未知 status が `statuses` に入る余地があります。

[Suggestion] `shards` が空でも `executed_routes` から推定して通す設計に見えます。生成器を正本にするなら、`shards` は非空必須にしてもよいです。

## 施策 2: 実行済み route の記録器

判定: **REQUEST_CHANGES**

[Critical] 提示コードは `RuntimeException` を使っていますが `use RuntimeException;` がありません。実装すると PHPStan / 実行時で落ちます。  
修正案: `use RuntimeException;` を追加するか、`throw new \RuntimeException(...)` にしてください。

[Critical] 「handle に到達した = すべての遮断 middleware を通過した」という保証が、現在の priority 固定だけでは不足しています。設計では `Authenticate` / tenant guard / subscription / pending deletion など主要なものだけを列挙していますが、route ごとに付く `signed` / `password.confirm` / recent-auth / ensure-login-method / throttle 系など、短絡しうる middleware が Bughunt より後ろに来ると false positive になります。  
修正案: `TenantBoundaryOrderingTest` など既存 inventory の短絡 middleware 分類を再利用し、**全 web route について BughuntExecutedRouteMiddleware が短絡 middleware より後ろ**であることを Architecture test で固定してください。必要なら priority list に「短絡しうる全 middleware → BughuntExecutedRouteMiddleware」の相対順を追加します。

[Warning] `classify()` の `session()->has('errors')` は、古い flash error が残っている場合に成功 PRG を `blocked` に誤分類する可能性があります。  
修正案: テストで「直前 request に errors がある状態で成功 302」を追加し、誤分類するなら validation failure を示すより狭い条件にしてください。少なくともこの限界は README に明記が必要です。

[Warning] `markFailure()` はディレクトリ作成をしないため、`buildLine()` 失敗時など `append()` 前に落ちた場合 `.error` も残せない可能性があります。  
修正案: `markFailure()` 内でも `dirname()` を作成してください。

## 施策 3: bug-hunt 環境への配線

判定: **REQUEST_CHANGES**

[Warning] ヘルスチェック後に JSONL を空にする方針は妥当ですが、`curl` がレスポンス受信後、Laravel の `terminate()` 書き込みがまだ残っている場合に `/login` が混入する小さな競合があります。  
修正案: 初期化前に対象 JSONL の更新が止まったことを確認する、または healthcheck 完了後に短い待機＋再 truncate するなど、競合をテスト可能な形で潰してください。

[Warning] dryrun 分岐で `init_executed_capture()` が実ファイルを作る設計は、dryrun の意味と少しずれます。  
修正案: self-test 用の明示モードとして扱う、または dryrun が storage に副作用を持つことをスクリプト内コメントと README に明記してください。

## 施策 4: executed.json の生成器

判定: **REQUEST_CHANGES**

[Critical] JSONL 行の schema validation が不足しています。`status` が未知値、`shard` がファイル名と違う、`http_status` が int でない、`route_name` が string/null でない場合も、現設計だと壊れた入力を集計できてしまいます。  
修正案: 各行で `run_id == --run-id`、`shard == 処理中shard`、`status in {"ok","blocked"}`、`http_status is int`、`route_name is None or non-empty string` を検査し、違反は exit 3 にしてください。

[Warning] 失敗時に `--out` を作らない契約は、直接書き込みだと途中失敗で破れます。  
修正案: 一時ファイルへ書き、成功時だけ atomic rename してください。失敗時は temp を削除し、既存の `--out` は上書きしないのが安全です。

[Warning] `route_name: null` だけの shard は、生成器では成功して `unresolved` のみ出せる一方、照合器の `validate_executed()` では `executed_no_rows` / shard mismatch になり得ます。  
修正案: 生成器側で「named route が 0 件なら exit 3」に倒すか、照合器側が `unresolved` を可用性行として扱うか、契約を一方に揃えてください。

## 施策 5: 手順・契約の文書更新

判定: **APPROVE**

[Suggestion] stale 文言 gate には、旧 `skipped` 語彙も対象に入れるとよいです。生成器は `ok|blocked` の 2 値に寄せる方針なので、README / fixture / test に旧語彙が戻ると信号が濁ります。

## 施策 6: Python 自己テストの実行レーン結線

判定: **APPROVE**

[Suggestion] `python3 -m unittest test_correlate test_build_executed` の cwd 固定はよいです。失敗時に stdout/stderr を Pest の failure message に含める設計にしておくと、CI で原因追跡しやすいです。

**主要な修正ポイント**

まず `BughuntExecutedRouteMiddleware` のコンパイル不備と、middleware ordering の保証不足を直す必要があります。次に `build_executed.py` は fail-closed の主入力生成器なので、JSONL schema を厳密に検査し、壊れた成果物を残さない atomic write にしてください。そこまで入れば、この設計の方向性自体は既存の bug-hunt 方針と整合しています。