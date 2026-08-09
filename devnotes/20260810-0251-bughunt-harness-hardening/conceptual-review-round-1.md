全体判定: **CHANGES_REQUESTED**

概念の方向性は妥当です。North Star への間接貢献も明確で、bug-hunt の探索予算を基盤復旧に奪われないようにする改善として筋が通っています。ただし H-1 と H-3 は、設計のままだと受入条件が実装で危うくなる余地があります。特に zombie 判定の procfs 実装詳細と、`optimize:clear` に対する `CACHE_STORE=array` だけで「DB に触らない」と言い切る点は修正が必要です。

**1. 使命との整合性**

[Suggestion] 基盤改善としての貢献は妥当です。  
bug-hunt は UX 破綻や IDOR を探索するための開発基盤なので、毎回同じ harness 不具合で止まる状態を直すことは、AI-CUE 本体の品質発見能力を上げる間接貢献になります。スコープも「アプリ本体を変えずに探索基盤を安定化」に閉じており、使命から逸れていません。

**2. 禁止事項違反**

[Warning] H-3 の「`CACHE_STORE=array` なら DB に一切触らない」という保証が強すぎます。  
`optimize:clear` は Laravel の複合コマンドで、`cache:clear` 以外にも bootstrap/cache 周辺やイベント・ビュー・ルート・設定キャッシュに触ります。DB 接続を発生させない目的なら、`CACHE_STORE=array` だけでなく、少なくとも ambient `DB_*` / `PG*` を遮断したうえで bughunt 用または無害な env を明示注入する設計にすべきです。

修正提案:  
H-3 は「ambient env では実行しない」を不変条件に入れてください。`env -i` を使い、`APP_ENV`、`APP_KEY`、`CACHE_STORE=array`、必要最小限の `PATH` 等だけを渡す。もし Laravel 起動に DB 設定が必要なら、dev DB ではなく bughunt 側または明示的なダミー値に固定する。受入条件にも「shell の `DB_*` / `PG*` が渡らない」を追加してください。

[Suggestion] H-4 の秘密コピーは既存 `.env` コピーと同等という整理で概ね妥当です。  
ただし `.env.bughunt.local` に本番相当の credential が混入しない運用前提は、詳細設計で確認したほうがよいです。

**3. 実現可能性**

[Critical] H-1 の `/proc/<pid>/stat` パースは bash で罠が多く、設計段階で仕様固定が不足しています。  
`/proc/<pid>/stat` は `comm` が括弧で囲まれ、プロセス名に空白や `)` が含まれる可能性があります。単純な `awk '{print $3}'` 的な実装だと状態フィールドを誤読します。また process group のメンバー列挙方法も未定義です。`kill -0 -- -pgid` から「pgid 内の PID 一覧」は取れないため、`/proc/[0-9]*/stat` を走査して pgrp を読む必要があります。

修正提案:  
H-1 は helper 関数を明確に分けて設計してください。

- `/proc/[0-9]*/stat` を走査する
- 最後の `") "` 以降を parse して `state` と `pgrp` を読む
- `pgrp == target_pgid && state != Z` が 1 件でもあれば残留
- `pgrp == target_pgid && state == Z` だけなら停止成功 + 警告
- `/proc` が読めない PID は race として無視するか、再読して判定する

受入条件にも「プロセス名に空白・括弧があっても state/pgrp を誤読しない」を入れるべきです。

[Warning] 「zombie は DB 接続を保持しない」は正しいが、同じ process group に別の非 zombie が残っていないことを procfs で確認できる実装が前提です。  
ここを誤ると、実行中 worker が残っているのに dropdb を許す後退になります。

修正提案:  
self-test は mock stat 文字列だけでなく、実 procfs 走査関数を差し替え可能にして、`Z` と `S/R/D` が混在するケースを固定してください。

**4. 期待効果の妥当性**

[Suggestion] 期待効果は概ね妥当です。  
H-2/H-4 は再発性の高い停止原因を潰せます。H-1 は orphan zombie 環境で teardown 完遂率を上げます。H-3 は dev DB 依存の provision 失敗を消す方向として正しいです。

[Warning] 「bug-hunt が最後まで通るようになる」はやや強い表現です。  
今回の既知 harness 不具合 4 件を除去する、が正確です。Playwright 解決や pcov などスコープ外要因は残ります。

修正提案:  
期待効果を「既知の harness 起因停止を除去し、次回 run が同じ 4 件で止まらない」に弱めてください。

**5. リスク**

[Critical] H-1 は dropdb の直前ガードなので、誤判定時の blast radius が大きいです。  
「zombie のみなら dropdb 許可」は妥当ですが、procfs パースミスや group 列挙漏れがあると dev DB 防御の実質的な緩和になります。

修正提案:  
dropdb 前に既存の DB 名 guard と role guard に加えて、「対象 DB 名が `SHARD_DB_RE` に一致すること」「pidfile の shard と DB 名が対応していること」を再確認する受入条件を明記してください。H-1 の変更で dropdb 条件が広がっていないことを self-test に入れるべきです。

[Warning] H-4 は秘密ファイルの複製範囲を広げます。  
同一ホスト worktree 内なので過剰リスクではありませんが、コピー先 permission を維持するか、少なくとも world-readable にしない確認があるとよいです。

修正提案:  
`.env.bughunt.local` コピー後の mode を親と同等にする、または `chmod 600` を明示する方針を詳細設計に入れてください。

**6. スコープの適切さ**

[Suggestion] スコープは適切です。  
`bug-hunt-shard.sh` と `setup-worktree.sh` に閉じ、アプリ本体・DB スキーマ・PWA 側へ広げない判断は妥当です。pcov、Playwright CLI、devcontainer PID 1 問題を切り分けているのもよいです。

[Warning] H-3 は「`optimize:clear` が本当に必要か」をもう一段確認したほうがよいです。  
機能名に立ち返るなら、必要なのは bootstrap cache の破棄です。`optimize:clear` 全体ではなく `config:clear` / `route:clear` / `view:clear` 等の必要最小コマンドへ分解できるなら、そのほうが副作用は小さくなります。

修正提案:  
詳細設計で「なぜ `optimize:clear` のままにするのか / 個別 clear に分解しないのか」を比較してください。仕組みが機能していない段階で値を弄るな、という原則にも合います。

**7. 受入条件の十分性**

[Warning] self-test だけでは H-1 の重要なすり抜けを固定しきれない可能性があります。  
特に procfs stat パース、process group メンバー列挙、mixed zombie/non-zombie の判定、PID 消滅 race が受入条件にありません。

修正提案:  
以下を追加してください。

- `stat` の `comm` に空白・括弧を含むケースでも state/pgrp を正しく読む
- zombie と non-zombie が混在する group は失敗扱い
- PID が走査中に消えた場合は安全に処理する
- all-zombie 成功時だけ pidfile を削除する
- non-zombie 残留時は pidfile を保持する

[Warning] H-3 の受入条件が「cache store を無効化」だけでは不足です。  
dev DB 防御の本質は ambient env 遮断なので、そこを固定しないと再発します。

修正提案:  
受入条件に「`optimize:clear` 呼び出しへ ambient `DB_*` / `PG*` が渡らない」「`CACHE_STORE=database` の親環境でも database cache store を使わない」を追加してください。

[Suggestion] H-4 の契約テストは setup-worktree 全体を実行するなら副作用が大きい可能性があります。  
実行時ファイルコピー部分を関数化、または dry-run/test fixture で検証できる形にすると受入条件を軽く固定できます。