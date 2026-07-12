ありがとうございます。提示された詳細設計を、指定の観点でレビューしました。  
（※ご指示どおり、コマンド実行・書き込みは行わず、提供テキストのみで評価）

## 施策1: worker 共通ヘルパ
**判定: REQUEST_CHANGES**

- [Critical] `start_shard_workers` で `guard_bughunt_runtime "${db}" bughunt` を通している点は良いですが、`DB_USERNAME=bughunt` を固定注入する設計は、既存の `.env` 側認証情報と乖離した場合に「guard は通るが接続失敗→worker 即死」を招きます。  
  修正案: `DB_USERNAME` も `env_file_required DB_USERNAME`（または同等の既存 getter）で取得し、`bughunt` 固定は避ける。少なくとも self-test に「env 側ユーザー名を使っている」構造検査を追加。
- [Warning] `worker_alive` の判定が `grep -q -- "queue:listen ${conn} "` のみだと、将来オプション順序や表記差分（例: `artisan` の前後引数変化）で偽陰性化しやすい。  
  修正案: `artisan` と `queue:listen` と `${conn}` をそれぞれ独立で確認（複数 grep）し、必要なら `--env=bughunt.local` も含める。
- [Suggestion] `BUGHUNT_WORKER_CONNECTIONS` は drift check 前提で妥当。コメントに「順序は不問（self-test で sort 比較）」を明記すると保守者の誤解を防げます。

## 施策2: provision への起動配線
**判定: APPROVE**

- [Warning] `manifest_update` への `worker_pid_${conn}=...` は key に `-` を含み得るため、manifest の消費側が shell eval 前提だと壊れます（JSON なら問題なし）。  
  修正案: manifest 形式が shell ソースされる可能性を排除できないなら、key は `worker_pid_database_analysis` 形式に正規化。
- [Suggestion] `start_shard_workers` 失敗時に、当該 shard で既に立ち上がった他 connection worker をその場で回収する軽いロールバックを入れると、teardown 依存を減らせます。

## 施策3: teardown への停止配線
**判定: REQUEST_CHANGES**

- [Critical] `--drop-db` 時の待機が `pgrep -f "queue:listen .* --env=bughunt.local"` だと**全 shard 横断**で判定され、別 shard の正常 worker に引きずられて無駄待機・誤判定します。  
  修正案: shard+connection の pidfile ベースで個別確認（`worker_alive "${shard}" "${conn}"`）し、当該 shard のみ待機する。
- [Warning] `kill -TERM -- "-${wpid}"` の後に wait/再確認がなく、直後 dropdb で race する可能性があります。  
  修正案: 各 worker ごとに短いリトライ待機（`worker_alive` が false になるまで）を入れ、タイムアウト時のみ warning ログ。
- [Suggestion] `continue` 除去による構造是正は妥当で、既存不整合修正として価値があります。

## 施策4: keepdb-check への生存確認
**判定: REQUEST_CHANGES**

- [Critical] `BUGHUNT_SKIP_WORKER_CHECK=1` を本体コードに入れる案は、たとえ self-test 用でも運用経路で誤設定されると F-01 再発を見逃します。  
  修正案: 本体に seam を持ち込まず、self-test 側で `worker_alive` 関数を一時スタブ化して `cmd_keepdb_check` を検証（サブシェル内で関数上書き）する方式に変更。
- [Warning] keepdb-check は reuse 可否判断として重要なので、エラーメッセージに「どの pidfile が不一致か」を含めると復旧が速い。  
  修正案: `$(worker_pidfile "${shard}" "${conn}")` を message に埋め込む。

## 施策5: self-test 拡張
**判定: APPROVE（条件付き）**

- [Warning] drift check が `vendor/autoload.php` 依存で fail-closed なのは思想として正しいが、self-test の主目的（guard/資源導出）と独立に落ちるとノイズになります。  
  修正案: エラー文言を「依存未導入による検査不能」と明確化し、原因切り分けを容易にする（fail 自体は維持でOK）。
- [Suggestion] `cmd_teardown` の「worker kill が serve kill より前」検査は良いです。加えて `[[ -f "${pidfile}" ]] || continue` が復活していないことも検査すると回帰耐性が上がります。

## 施策6: コメント/ドキュメント整合
**判定: APPROVE**

- [Suggestion] `.env.bughunt.local` と `.example` 同期方針を README/運用ドキュメントにも一行追記すると、将来の片側更新漏れを防げます。

## 観点別総評（要点）
- セキュリティ/安全性: `env -i`・guard 維持・cmdline照合は概ね良好。
- 非交渉要件: dev DB 防御の方向性は満たしているが、施策4の seam 案は逸脱リスク。
- プロセス管理: `setsid` + PG kill 方針は妥当。ただし teardown の待機条件は shard 非限定で要修正。
- スコープ: 製品コード不変契約は守れている。

## 全体判定
**CHANGES_REQUESTED**

主な修正必須点は3つです。  
1) `teardown --drop-db` の待機判定を shard ローカル化（Critical）  
2) `BUGHUNT_SKIP_WORKER_CHECK` seam を本体に入れない（Critical）  
3) worker 接続認証値（特に `DB_USERNAME`）の固定注入見直し（Critical）  

この3点が解消されれば、全体として APPROVED 相当まで到達可能です。