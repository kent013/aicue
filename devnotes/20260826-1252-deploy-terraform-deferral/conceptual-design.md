# 概念設計: インフラのコード化 (deploy-terraform feature) の取り込み見送りと記録

- 日付: 2026-08-26
- 種別: 判断の記録 (不採用の申告)。実装は行わない
- 関連: 家系の機能台帳 `lctl` の feature `deploy-terraform` / 本リポジトリの
  `deployer-pipeline` + `skill-deploy` 取り込み (同日)

## 1. 何が起きたか

家系正典 (`laravel-claude-template`) のデプロイ資産は 3 つの feature に分かれている。

| feature | 実体 | 本リポジトリの取り込み |
|---|---|---|
| `deployer-pipeline` | `deploy/**` / `scripts/deploy.sh` / 配線 gate | **取り込んだ** (同日) |
| `skill-deploy` | `.claude/skills/app-deploy/SKILL.md` / 契約 gate | **取り込んだ** (同日) |
| `deploy-terraform` | `infra/terraform/**` / `.env.production-template` / terraform 契約テスト / CI の terraform job | **取り込んでいない** ← 本書の対象 |

`deploy-terraform` を今回入れなかったのは、**staging サーバー (AWS Lightsail 1 台) を
既に手で作って稼働させてしまっている**ためである。Terraform を後から入れる場合、
新規作成ではなく既存リソースの取り込み (import) になり、作業の性質が「デプロイ基盤の移植」
から「稼働中インフラの状態合わせ」へ変わる。同じ変更単位に混ぜると、失敗したときに
「デプロイ定義の問題か、インフラ定義の問題か」が切り分けられない。

## 2. なぜ「見送った」ことを記録するのか

見送りを記録しないと、次の 2 つが区別できなくなる。

1. **検討して見送った** (前提が変わったら入れる)
2. **そもそも検討していない** (抜け落ちている)

家系の台帳 (`lctl`) は本リポジトリの `deploy-terraform` を `reviewing` として持っており、
リポジトリ側に対応する記録が無いと、次に台帳を巡回する人が (2) と読む。
またデプロイ基盤の gate (`tests/Architecture/DeployCoordinateHygieneTest.php`) は
走査根の目録を deny-by-default で持つため、**存在しない資産を目録から消すと
「その資産は検査対象外」という宣言になってしまう**。

## 3. 記録の置き場所 (3 つ。役割が違う)

| 置き場所 | 役割 |
|---|---|
| `docs/deployment-runbook.md` §11 | **運用者向け**。「インフラは手作りでコード化されていない」ことを未対応事項として明示する |
| `tests/Architecture/DeployCoordinateHygieneTest.php` の走査根 | **機械向け**。`infra` / `.env.production-template` を `required=false` + 反転条件付き reason で残す (目録から消さない) |
| `docs/TODO.md` の Conditional | **計画向け**。トリガー条件を満たしたら Open へ昇格させる |

## 4. 価値と非目標

- 価値: 「入れていない」ことが**3 つの独立した経路**から見えるので、忘れられない。
  かつ機械 gate 側は「不在の申告」の形なので、資産を追加した PR が反転を要求される。
- 非目標: 今回 Terraform 定義を書くこと。既存リソースの import 設計を行うこと。
  production 環境を作ること。これらは条件を満たしてから別の変更単位で行う。

## 5. 昇格 (Open 化) の条件

次のどちらかが起きたとき。

- **(A) production 環境を作ると決めたとき** — production は「手で作った 1 台」で運用してよい
  範囲を超える (再構築可能性と権限分離が要る)。このとき `.env.production-template` も同時に要る。
- **(B) 現 staging サーバーを作り直す / 増やす必要が出たとき** — 手順が人の記憶にしか無い状態で
  2 台目を作ると必ず食い違う。

逆に、**1 台の staging を触り続けるだけの間は昇格しない**。稼働中の 1 台を import する作業は
リスクだけがあって得るものが無い (再構築の需要が無いため)。
