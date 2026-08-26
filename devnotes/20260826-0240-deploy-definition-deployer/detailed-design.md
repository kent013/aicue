# デプロイ定義 (Deployer) の導入 — 詳細設計

対象: 開発/staging サーバー 1 台 (Lightsail / `APP_ENV=staging` / `deploy_path=/var/www/aicue`)
成果物: `deploy.php` / `docs/deployment-runbook.md` / 既存ドキュメントの実態同期

---

## 1. 何を決めたか

### D1. デプロイツールは Deployer (deployphp/deployer 8.x) を dev 依存で入れる

- 実行は**開発機 (devcontainer) から** `vendor/bin/dep deploy`。CI からの自動デプロイは入れない。
- Laravel 同梱 recipe (`recipe/laravel.php`) を `require` して `artisan()` ヘルパと
  shared/writable の既定を使う。

### D2. デプロイ定義は**リポジトリルートの `deploy.php` 1 枚**に閉じる

理由は 2 つあり、2 つめが本アプリ固有である。

1. デプロイ手順の置き場を 1 か所にする (散らすと実行される手順が読めなくなる)。
2. `tests/Architecture/RouteCacheExemptionPremiseTest.php` の**検査 A** を、
   「**この 1 枚以外**のデプロイ基盤が増えたら赤くする」早期警報として生かし続ける。
   検査 A の判定条件は `deploy/` `ansible/` `k8s/` 等のディレクトリ・`Procfile` 等の名前・
   `*.tf`・名前に deploy/release/cd を含む GitHub Actions workflow という
   「既知のデプロイ基盤の形」を拾う粗い網であり、`deploy.php` は一致しない。
   網を広げて赤くするのではなく、**定義を網の外の 1 点に集約する**ことで、
   将来 `deploy/` や CI デプロイ job が増えたときに D19 の読み直しが強制される状態を保つ。

### D3. 経路キャッシュ (routing の cache) は**生成しない**契約をデプロイ定義に固定する

- 焼くのは **config / event / view の 3 つだけ**。
- 根拠: `RouteThrottleBinder` / `RouteMiddlewareBinder` が `Application::booted()` で
  vendor route へ middleware を後付けしており、経路キャッシュを焼いた起動では
  throttle / recent-auth / ensure-login-method / no-store が 1 本も効かない。
  「焼いて毎デプロイ再生成する」運用は、再生成に失敗した瞬間に**無音で保護が外れる**。
- 実装上の注意: 同梱 recipe の既定 `deploy` タスクは経路キャッシュを含む複合タスクを呼ぶため、
  **自前の `deploy` タスクで必要なタスクだけを列挙する**。既定タスクを `remove()` で削る形は
  削る対象のタスク名を文字列として書く必要があり、それ自体が検査 B の検出対象になるため採らない。
- 理由の説明は**すべて PHP のコメント**に置く (検査 B は走査前にコメントを落とすが、
  文字列リテラルは残すため `desc()` や heredoc には needle を書けない)。

### D4. タスク順序

```
info → setup → lock → release → update_code → shared → check_env → writable
→ vendors (composer install --no-dev) → frontend (corepack pnpm install + build)
→ storage:link → app_caches (config/event/view) → production:preflight → migrate
→ symlink → reload_services (php-fpm reload → worker 4 本 restart)
→ unlock → cleanup → success
```

順序の根拠:

- **cache 生成は composer install の後**。`composer install` の `post-autoload-dump` が
  `filament:upgrade` を走らせ、その中で config / view の cache が clear されるため、
  前に置くと消える (実測)。
- **`production:preflight` に `--strict` を付けない**。`--strict` は `APP_ENV` が
  `production` でないと fail する。現サーバーは `staging` なので必ず落ちる。
  production 環境を作るときは `--strict` 付きの別 host 定義にする。
- **`deploy:check_env` を自前で持つ**。recipe の `failIfNoEnv` は使えない —
  common recipe が `dotenv` を `false` に set しているため、`artisan()` の判定式が
  `[ -s ]` (引数 1 個の文字列テスト) になり常に真を返す (実測)。
  `shared/.env` を直接見る自前タスクの方が確実である。
- **`deploy:env` は入れない**。`.env.example` から `.env` を自動生成させてはならない
  (雛形の値で起動するのを許すことになる)。
- **worker は reload ではなく restart**。reload では新しいコードを読まない。
  4 本を 1 コマンドでまとめるのは「1 本だけ古いコードで残る」状態を作らないため。
- **scheduler timer は触らない**。起動ごとに新プロセスなので次回起動から新コードになる。
- `after('rollback', 'deploy:reload_services')` を張る。張らないと
  「コードは戻ったがワーカーは新コードのまま」になる。

### D5. worker の `--timeout` はデプロイ定義に**持たせない**

正本は `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表。
`deploy.php` は systemd unit を restart するだけにする。値を 2 か所に置くと必ず食い違い、
リース切れによるジョブの二重実行を生む。

### D6. `writable_mode` は `chmod`

Deployer 既定は `acl` (setfacl + `ps` からの http ユーザー推測) で、
推測に失敗すると例外で落ちる。本サーバーは php-fpm pool を deploy ユーザーと同じユーザーで
動かす単一ユーザー運用なので `chmod` (0775) で足りる。
プロビジョニング完了後に pool の実ユーザーを確認し、違っていれば `chgrp` へ切り替える
(その旨を `deploy.php` のコメントに残した)。

### D7. SSH 鍵は host 定義で明示する

devcontainer には `~/.ssh/config` が無いので、hostname / remote_user / identity_file を
すべて `deploy.php` に書く。鍵はホストの `~/.ssh/github` を
`/home/vscode/.ssh/aicue_deploy` へ**読み取り専用**でマウントする
(`docker-compose.yml`)。`.pub` も渡す — 無いと ssh が公開鍵を提示するためだけに
秘密鍵の復号を要求する。

---

## 2. D19 (経路キャッシュ起動での後付け) の再判定

D19 の再判定条件に「リポジトリにデプロイ定義の実体が入ったとき」があり、本施策で発火した。

**再判定の結論: 逸脱を維持する。**

- 前提 (「経路キャッシュを打たない」) は崩れていない。むしろ D3 によって
  **人手の約束から出荷経路の性質へ格上げされた**。
- 毎デプロイ再生成の機械強制は「解く相手が消えた」ので採らない。再生成の強制が守るのは
  「焼いた cache が stale であること」だが、焼かない出荷経路に stale な cache は存在しない。
  経路キャッシュの鮮度を見る preflight を足すと検査対象の無い機構を抱えることになる (思考原則 2)。
- 正典の形 (専用の実行点クラスへ集約) へも移行しない。移行して得られるのは
  「経路キャッシュを焼いても後付けが効く」ことだけで、焼かないと決めた構成では利益が無い。

台帳側は表の各行 (対象パスへ `deploy.php` を追加 / 説明 / 不変条件 / 再判定条件) と本文を
実態へ書き換え、再判定の記録を残した。**番号の renumber は行わない。**

---

## 3. TRUSTED_PROXIES 運用要件 (T108) の充足

`AGENTS.md` は「デプロイ基盤を作る PR は route:cache 運用要件と TRUSTED_PROXIES 運用要件の
2 つを実装するまで完了にできない」と定める。後者は
`docs/trusted-proxies-runbook.md` §3 の運用者記入欄を実態で埋めることで満たす。

実地確認の結果:

- nginx と php-fpm は同一ホストで UNIX socket 接続 → **TCP の hop が増えない**。
- CDN / LB / 別ホストの前段 nginx はいずれも無い。
- したがって信頼すべき hop は 0 個で、正しい宣言は **`TRUSTED_PROXIES=none`**。

併せて「`APP_ENV=staging` では `ProductionEnvGuard` の起動時 fail-fast が効かないので
書き忘れても起動してしまう」という**この環境固有の穴**を §3.0 に明記した。

---

## 4. `docker-compose.yml` の分類 (採用時債務 → 意図的逸脱)

D7 の鍵マウントを足すため `docker-compose.yml` を変更した。同ファイルは D34 の
採用時債務一覧に採用時ハッシュ付きで凍結されていたため、`TemplateDivergenceFingerprintTest`
の F10 (`mutatedDebtPaths`) が落ちる。

D34 が定める「一覧が縮む契機」は 2 つだけである — (1) 内容をテンプレートへ戻す /
(2) 意図的逸脱として登録簿へ書く。本ファイルはテンプレートへ戻せない
(アプリ固有の dev 環境定義そのものである) ため (2) を採り、**D58** として登録して
債務一覧から 1 行外した。件数 pin (`DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT`) は
同じ変更で直す。前例は D56 / D57 (どちらも債務凍結されたファイルを変更する際に
同じ変更で登録へ移している)。

---

## 5. やらなかったこと (意図的)

| 項目 | 理由 |
|---|---|
| `dep deploy` の実行 | サーバー側プロビジョニングが完了していない |
| CI へのデプロイ job 追加 | D2 の理由。入れる PR で運用要件 2 つを再確認する |
| `deploy.php` を PHPStan の走査対象に追加 | `phpstan.neon` の paths は app/config/database/routes。Deployer の recipe DSL (namespace 関数 + 動的 config) は level 10 の対象として噛み合わず、走査域を広げる判断は別途 |
| 経路キャッシュ鮮度の preflight | 焼かないので検査対象が無い (思考原則 2) |
| メンテナンスモードの自動化 | ローリング非互換な migration のときだけ人手で `dep artisan:down` / `artisan:up` を挟む |
| worker の cgroup メモリ制限 | 未対応事項として `docs/deployment-runbook.md` §11 に列挙 |

---

## 6. 検証

- `composer test` (Pest 全件) / `composer phpstan` / `vendor/bin/pint --test`
- `tests/Architecture/RouteCacheExemptionPremiseTest.php` — 検査 A / 検査 B の両方が緑
  (`deploy.php` を追跡下に置いた状態で実測)
- `tests/Architecture/TrustedProxiesRunbookTest.php` — placeholder 残存なし・必須節あり
- `vendor/bin/dep tree deploy` — 経路キャッシュ系タスクを含まないことを出力で確認
- `vendor/bin/dep tree rollback` — `deploy:reload_services` が after として付くことを確認
