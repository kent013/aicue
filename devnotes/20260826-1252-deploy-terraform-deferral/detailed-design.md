# 詳細設計: deploy-terraform 未取り込みの記録と、昇格時にやること

- 概念設計: `conceptual-design.md` (同ディレクトリ)
- 実装済みの範囲: **記録の 3 点だけ**。Terraform 定義は 1 行も書かない

## 1. 今回実装した記録 (3 点)

### 1-1. 機械 gate 側の「不在の申告」

`tests/Architecture/DeployCoordinateHygieneTest.php` の `deployScanRoots()` に
次の 2 root を **`required => false` + 反転条件付き reason** で残す。

| root | required | reason の要点 |
|---|---|---|
| `infra` | false | `deploy-terraform` 未取り込み。`infra/terraform` を追加する PR が `required=true` へ反転させる |
| `.env.production-template` | false | 同。production 環境を作る PR が反転させる |

- 同ファイルの「`required=false` の root は reason 非空である」ケースがこの reason の存在を強制する。
- 目録から**削除しない**理由: 削除すると「その資産は座標検査の対象外」という別の宣言になる。
  `required=false` は「まだ無い」であって「見なくてよい」ではない。
- `required=true` の root は 1 ファイル以上を持つことを別ケースが強制するので、
  資産を追加して反転させ忘れると (逆に反転させて資産が無いと) 赤くなる。

### 1-2. 運用者向けの記録

`docs/deployment-runbook.md` §11 「既知の未対応事項」の先頭行:

- 何が無いか (`infra/terraform` / `.env.production-template` / terraform 契約テスト / CI job)
- なぜ入れなかったか (Lightsail を手で作った = 後から入れるなら import 作業になる)
- どこに起票したか (`docs/TODO.md` T267)

### 1-3. 計画側の記録

`docs/TODO.md` の Conditional に T267 を起票する。トリガー条件は概念設計 §5 の (A) / (B)。

## 2. 昇格したときにやること (作業の見取り図。今回は実施しない)

1. **現状のインベントリを取る** — Lightsail インスタンス / static IP / firewall (Lightsail の
   ネットワーク設定) / S3 バケット / IAM ユーザーかロール / Route53 (ドメイン取得後) を列挙する。
2. **家系正典の `infra/terraform` を移植する** — module 構成と tfvars 注入の形をそのまま採り、
   `<APP>` 系の placeholder を埋める。state のバックエンドを先に決める
   (ローカル state は 1 人運用でも事故る)。
3. **既存リソースを import する** — `terraform import` (または `import` ブロック) で
   稼働中リソースを state へ取り込む。**この段は「plan の差分がゼロになる」ことがゴール**で、
   リソースを作り直してはならない (staging を落とす)。
4. **`.env.production-template` を入れる** — production の env baseline は
   `App\Support\ProductionEnvGuard` が正本なので、template はそれと突き合わせる形にする。
5. **契約テストと CI job を入れる** — 正典の terraform 契約テストと `fmt -check` /
   `init -backend=false` + `validate` の job。
6. **座標 hygiene gate の 2 root を `required=true` へ反転する** (1-1 の逆操作)。
   反転を忘れると走査根が空のまま緑になるので、反転はこの作業の**完了条件**である。
7. **runbook §11 の該当行を消す**。

## 3. やらないこと (昇格時も含む)

- **`.claude/skills/app-deploy` に terraform を叩く導線を作らない**。
  `tests/Architecture/DeploySkillContractTest.php` の S7 が禁じている
  (インフラ操作は人間の責務であり、agent が代行してよい範囲に入れない)。
- **デプロイパイプライン (`deploy/**`) に terraform を呼ぶ task を足さない**。
  配布とインフラ変更は別の意思決定であり、同じコマンドで走らせると
  「配布したらインフラが変わった」という事故が起きる。

## 4. 検証

本変更 (記録だけ) の検証は次で足りる。

- `tests/Architecture/DeployCoordinateHygieneTest.php` が緑 (走査根の目録の健全性ケース 3 本を含む)
- `tests/Architecture/DeployPipelineWiringTest.php` W25 が緑 (`deploy` / `scripts/deploy.sh` の
  `required=true` を pin するので、目録の書き換えで壊していないことの裏)
