---
name: app-deploy
description: staging / 本番へのアプリ配布規約 (scripts/deploy.sh 経由の単一入口・座標 fail-fast・rollback 判断)
user-invocable: true
argument-hint: "<host> [--check]  例: /app-deploy staging"
---

# アプリ配布 (app-deploy)

Deployer による**アプリ配布**の実行手順を規約化するスキル。

**サーバー本体 (Lightsail インスタンス / nginx / php-fpm / systemd unit / DNS / 証明書) の
作成・変更はこのスキルの対象外**である。クラウドとサーバーに触る操作は人間が
`docs/deployment-runbook.md` に従って実行する。

## 引数

| 引数 | 必須 | 説明 |
|------|------|------|
| host | Yes | `deploy/hosts.yml` で定義した host 名。**既定 host は無い** |
| --check | No | 前提チェックだけを回す dry-run (配布しない) |

## 禁止事項 (最初に読む)

**禁止**: `vendor/bin/dep deploy` の直叩き。配布の入口は `scripts/deploy.sh` だけである
(単一入口にするのは、本番ゲートと座標 fail-fast をどの経路でも必ず通すため)

**禁止**: `dep run` を skill の判断で実行すること。実ホストへ SSH する。ホスト側の実効設定を
見たい場合は runbook の「人手で行う運用確認」の章を提示して人間に委ねる

**禁止**: リモートホストへ入る操作 (対話ログイン / ファイル転送) の導線を作ること。
サーバー側の作業は人間の責務である

**禁止**: 稼働 URL へ HTTP を投げること (ヘルスチェックの確認も人間に依頼する。Phase 5)

**禁止**: host 名をこの SKILL.md に書くこと。host 一覧は必ず `deploy/hosts.yml` を読んで得る
(実座標が追跡下に焼き付くのを防ぐ。`tests/Architecture/DeploySkillContractTest.php` が検査する)

**禁止**: 開発機の絶対パスを書くこと。パスはリポジトリ相対で書く

agent が実行してよい `dep` は、ローカルで完結する read-only の 3 つだけである:

```
vendor/bin/dep -f deploy/deploy.php list
vendor/bin/dep -f deploy/deploy.php tree deploy
vendor/bin/dep -f deploy/deploy.php deploy --plan <host>
```

**このリポジトリの開発機には php が無い**ので、上記も `scripts/deploy.sh` も
devcontainer の中で実行する。コンテナ名と作業ディレクトリの正本は `docker-compose.yml` で、
起動形は `docs/deployment-runbook.md` に書いてある (SKILL.md には焼き付けない)。

## Phase 0: 座標の存在確認 (fail-fast)

座標が未設定なら**まずここで必ず止まる**。次の 6 点を確認する:

1. `scripts/deploy.sh` が存在する (無ければ以降を委譲できないので、ここで終了する)
2. `deploy/hosts.yml` が存在する
3. `deploy/hosts.yml` に placeholder (`<...>` の山括弧 / TEMPLATE-MARKER) が残っていない
4. `deploy/deploy.php` の `application` / `repository` が設定済みである
5. `vendor/bin/dep` が存在する
6. 引数の host が `deploy/hosts.yml` に定義済みである

1 は agent がファイルの存在で確認する。2 から 6 は自前で YAML を parse せず
`bash scripts/deploy.sh <host> --check` に任せる
(2 から 6 を同じ順で検査する単一実装があり、そこが唯一の SoT である)。

**host 引数が無いまま起動された場合はここで終了する**。既定 host は無いので推測してはならない。

1 つでも欠けたら、次の ERROR 文面をそのまま出力して**処理を終了する**:

```
ERROR

アプリ配布の前提が満たされていません。

scripts/deploy.sh が無い場合:
  配布の単一入口そのものが欠けています。テンプレートの取り込み漏れを疑ってください
  (このスキルは wrapper を経由しない配布手段を持ちません)
hosts.yml が無い場合:
  cp deploy/hosts.example.yml deploy/hosts.yml
  そのうえで山括弧の placeholder を実座標で埋める
deploy.php の placeholder が残っている場合:
  deploy/deploy.php の application / repository を設定する
vendor/bin/dep が無い場合:
  composer install を実行する

deploy/hosts.yml は .gitignore です。実座標を commit しないでください。
```

## Phase 1: 前提と dry-run (どちらも host 引数が必須)

D1 / D2 は**どちらもリモートへ接続しない**。両方が exit 0 になるまで Phase 2 へ進まない。

```
bash scripts/deploy.sh <host> --check
vendor/bin/dep -f deploy/deploy.php deploy --plan <host>
```

- D1 は Phase 0 の 5 点だけを回す (配布しない)
- D2 は Deployer が解決した実行計画を出す。**全セルが `-` の行**があれば
  `labels.roles` の綴りが合っておらず、そのタスクは 1 ホストも実行されない

## Phase 2: 人間の確認ゲート (LLM は代行しない)

本番ホストへの配布は `--production` の明示が必要で、**対話端末 (TTY) からのみ**通る。

- **LLM が算術チャレンジに答えてはならない**。これは人間の意思表示を取るためのゲートであり、
  agent が突破できてしまうと存在意義が消える
- 非 TTY 実行は `scripts/deploy.sh` と `deploy:confirm-stage` の**両層**で拒否される。
  「通らないから工夫する」対象ではない
- したがって本番ホストのときは、次のコマンドを**人間が自分の端末で実行する**よう提示して止まる

```
bash scripts/deploy.sh <host> --production
```

- 現在の `deploy/hosts.yml` に `stage: production` の host があるかどうかは**必ず実ファイルで確認する**
  (「無いはず」で進めない)。stage は `production:preflight` の `--strict` の有無も決める

## Phase 3: 実行 (非本番ホストのみ agent が起動してよい)

正典の起動形は 1 つだけである (`docs/deployment-runbook.md` と同一表記):

```
bash scripts/deploy.sh <host> [--check] [--allow-dirty] [--production]
```

- **agent が付けてよいオプションは `--check` と `--allow-dirty` だけである**。
  `--production` は Phase 2 のとおり**人間が自分の端末で付ける**。agent が付けても
  非 TTY 判定で必ず落ちるが、「試して落ちる」ではなく**最初から提示に留める**
- `--allow-dirty` は「配布物と無関係な untracked がある」ときだけ使う。Deployer は
  リモートリポジトリから clone するため、未 commit の変更はそもそも配られない
- `scripts/deploy.sh` は配布の前に `git push` する (取り消せない副作用)。ブランチが main でない
  場合や origin が先行している場合は push より前に落ちる

## Phase 4: 成功判定 (出力の読み方)

- **成功**: 末尾付近に `task deploy:success` と `info successfully deployed!` が出ている
- **失敗**: `task deploy:failed` が出ている。このとき「成功した」と書いてはならない
- `WARN` 単独では失敗と判定しない

## Phase 5: post-deploy 検査 (agent が判定してよい範囲)

agent が自動判定するのは **配布出力だけ**である:

- `deploy:verify` (`production:preflight`) が通ったこと
- `deploy:restart` が php-fpm の reload と queue worker の restart を**両方**実行したこと
  (`queue_worker_restart_enabled=false` の skip 表示が出ていたら、それは配布が緑でも
  **worker が旧コードのまま動き続ける**状態である)
- `task deploy:success` / `info successfully deployed!` が出ていること

次の 2 点は **agent が確認しない**。報告テンプレに「未確認」として載せ、人間に依頼する
(稼働 URL へ HTTP を投げる導線を既定にしない。`dep run` を持たせないのと同じ理由である):

- ヘルスチェックが 200 を返すこと
- TLS 導入後は応答に `Strict-Transport-Security` ヘッダが付いていること

常駐プロセス (queue worker / scheduler timer) の確認は人間が行う。手順は
`docs/deployment-runbook.md` の常駐プロセスの章にある。**配布が緑でも常駐が欠けていれば
シナリオ生成もレンダも定期処理も走らない** (出力からは判定できない静かな障害)。

## Phase 6: 失敗時の一次対処と rollback

**反射的に rollback を打つのが最悪手**である。まず**どこで止まったか**を見る。

| 止まった位置 | current の状態 | 一次対処 |
|---|---|---|
| `deploy:symlink` より前 | 旧リリースのまま (サービスは無事) | rollback は**不要**。失敗した `releases/N` を見て原因を直す |
| `deploy:verify` | 旧リリース + **DB 無変更** | `shared/.env` を直して再配布するのが正道 |
| `deploy:restart` の失敗 | 新リリース公開済み | コードは出ている。php-fpm / worker の再起動だけを人間が確認する |
<!-- TEMPLATE-MARKER: アプリ固有の失敗パターンをこの表に 1 行ずつ追記する -->
| `build:frontend` の `install` | 旧リリースのまま | ホスト側 corepack が `packageManager` の pnpm を取得できていない。runbook のホスト側 pnpm の節へ |
| `deploy:verify` で ffmpeg 関連の違反 | 旧リリース + DB 無変更 | 静的ビルドの ffmpeg / ffprobe のパス env が `shared/.env` で解決できていない。runbook の該当節へ |
| `artisan:migrate` | 新スキーマ + current は旧コード | **rollback しない**。migration は forward-only なので、失敗した migration を直して再配布する |

汎用の一次対処 3 件:

- `ERR_PNPM_IGNORED_BUILDS` などの `build:frontend` 失敗: ホスト側 pnpm の版が
  `package.json` の `packageManager` と食い違っている。runbook のホスト側 pnpm の節に従って揃える
- `production:preflight` の env 違反: **DB は無変更**。`shared/.env` を直して再配布する
  (rollback しない)
- 502 / TLS エラー: php-fpm が上がっているか / 証明書の期限を人間が確認する

rollback が本当に必要なときの正典:

```
vendor/bin/dep -f deploy/deploy.php rollback <host>
```

- **コードは戻るが DB migration は戻らない**。migration は forward-only 規約 (`AGENTS.md`) なので、
  migrate 後の rollback は「新スキーマに旧コード」を作る。機械 gate を書けないため人間が判断する
- rollback には `deploy:restart` だけを配線している。`after('rollback', ...)` へ他のタスクを
  安易に足さないこと (リリース非依存の副作用が巻き戻る)
- release が 1 本しか無い状態では rollback できない (戻り先が無い)

## 報告テンプレ

```
## 配布結果

- host: (引数で受けた host 名。SKILL.md には書かない)
- 起動形: bash scripts/deploy.sh <host>
- 結果: 成功 / 失敗 (根拠: 出力の task deploy:success / task deploy:failed)
- deploy:verify: 通過 / 未到達
- deploy:restart: php-fpm reload と worker restart の実行 / skip 表示の有無
- 未確認 (人間に依頼): ヘルスチェックの応答 / TLS ヘッダ / queue worker と scheduler timer の常駐
- 失敗した場合: 停止位置と Phase 6 の一次対処
```

## 既知ノイズ

<!-- TEMPLATE-MARKER: 無視してよい警告 (アプリ固有) をここに追記する -->

- `production:preflight` が「production 以外の環境のため production 専用の検査を skip しました」と
  warning を出すのは **staging では正常**である (stage が production の host では `--strict` が付き、
  同じ状況が fail になる)。
- `artisan:reload` (recipe 既定) の `queue:restart` / `schedule:interrupt` は、`deploy:restart` の
  systemd restart と役割が重なる。両方走るのは想定どおりで、失敗でなければ無視してよい。

## アプリ固有 post-deploy 手順

<!-- TEMPLATE-MARKER: seeder 実行やキャッシュ再構築などアプリ固有の手順をここに追記する -->

- **初回配布のときだけ**、管理者の発行と CLI OAuth client の発行を人間が行う
  (どちらも配布パイプラインには載せていない。手順は `docs/deployment-runbook.md`)。
- `shared/.env` を編集したときは、焼いた設定キャッシュに反映されないので**再配布する**
  (単発でキャッシュだけ焼き直す運用は取らない。release ごとに焼き直す形に揃えている)。

**アプリ固有の手順はこの TEMPLATE-MARKER の 3 欄以外に書かない**。本文へ混ぜるとテンプレート更新の
取り込みができなくなる。

## 参照

- `docs/deployment-runbook.md` — サーバー構成・座標の初期設定・常駐プロセス・
  人手で行う運用確認 (**人間向け**の正本)
- `docs/architecture.md` — queue のリース期間とワーカー制限時間の値表 (worker の `--timeout` の正本)
- `docs/ses-mail-runbook.md` — メール送信とバウンス / 苦情抑止の運用
- `tests/Architecture/DeployPipelineWiringTest.php` — 配布パイプラインの band 台帳 (配線の SoT)
- `tests/Architecture/DeploySkillContractTest.php` — 本 SKILL.md の契約 gate
