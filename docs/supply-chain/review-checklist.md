# Supply-chain 脆弱性レビュー チェックリスト

`scripts/audit-gate.sh` (= `pnpm run audit:gate`) が composer / pnpm の audit
(pyproject.toml があるリポジトリでは pip-audit も) を実行し、
`scripts/audit-gate.ts` が severity 判定と `docs/supply-chain/accepted-advisories.yaml` の運用ルールを機械強制する。
本書は人手レビュー時の判断基準と、gate が強制するポリシーの根拠を定める。

## 1. gate の合否ルール (audit-gate.ts が強制)

| 事象 | 結果 |
|---|---|
| 未受容の **high / critical** advisory | **fail** |
| 未受容の **moderate** advisory | warn (Summary 列挙、合否非影響) |
| `accepted-advisories.yaml` の entry が **expiry 切れ** (today > expiry, JST) | **fail** |
| accepted entry が現 advisory と **突合しない** (解消済み) | **fail** (cleanup 必須) |
| accepted severity < advisory severity (迂回受容) | **fail** |
| accept-risk window が運用上限超過 (§3) | **fail** |
| `approved_at` が未来 / `expiry < approved_at` | **fail** |

突合キーは `ecosystem + package + id` (trim + lowercase)。severity unknown は **high 扱い** で fail-safe
(pip-audit は severity を返さないため PyPI advisory は常に high 扱い)。

## 2. advisory を見つけたときの初動

1. **まず upgrade を試す** — 修正版があるなら `pnpm update` / `composer update <pkg>` で解消が原則。accept-risk は最終手段。
2. upgrade 不能 (上流未修正 / breaking) の場合のみ accept-risk を検討。
3. accept-risk する場合は §3 の期限内で `accepted-advisories.yaml` に登録し、tracking を残す。

## 3. accept-risk の運用上限 (機械強制)

`approved_at` から `expiry` までの日数上限。期限内に upgrade で解消することを前提とする。

| severity | 上限 |
|---|---|
| low / moderate | 90 日 |
| high | 30 日 |
| critical | 14 日 |

high / critical は追加で `approved_by` (承認者) / `compensating_controls` (緩和策) /
`tracking_issue` (追跡 TODO) が必須。

## 4. accepted entry の cleanup

advisory が解消 (upgrade 等) されたら、対応する accepted entry を **必ず削除**する。
解消済み entry を残すと gate が cleanup 必須として fail する (死蔵 entry による迂回防止)。

## 5. 0day 緊急時

上流未修正の重大 0day を一時受容する場合でも、accept window は **7 日以内**に絞り、
`compensating_controls` に具体的な緩和 (WAF rule / 機能無効化 / network egress 制限 等) を明記する。
7 日以内に恒久対応 (upgrade / patch / 機能撤去) を完了させる。

## 付録: 新規 npm 依存の審査観点

新規 npm 依存を追加する、もしくは既存依存を major version 更新する際の人手レビュー観点。
脆弱性 gate (§1) とは別に、依存を「入れる前」に審査する。

1. **Socket.dev レポート**
   - <https://socket.dev/npm/package/{name}> を開く
   - 観点: `maintainer churn` / `install scripts` / `network access` / `supply chain risk`
   - `error` 以外でも high-risk とマークされる project は追加確認

2. **npm package provenance の有無**

   ```
   npm view {name} dist.attestations
   ```

   - Sigstore attestation が無い package は原則採用しない
   - 代替がない場合は reason を PR に明記

3. **GitHub 活性度**
   - 最終 commit 日付 (6ヶ月以内が望ましい)
   - Open issue 数 / 未解決の security advisory
   - maintainer 数 (bus factor 1 の package は要注意)

4. **ライセンス適合性**
   - MIT / Apache-2.0 / ISC / BSD-3-Clause → OK
   - LGPL / MPL → 要レビュー (リンク形式・再配布ライセンスを確認)
   - GPL / AGPL / Unknown → 原則 NG (自プロダクトの配布形態と照合して判断)

5. **代替検討**
   - Node 標準ライブラリで代替できないか
   - 既に採用済みの package で代替できないか
   - 自前で小さく実装する選択肢はないか (数十行で済むなら依存追加しない)

6. **インストール挙動**
   - `postinstall` / `preinstall` スクリプトの有無 (原則拒否)
   - バイナリ依存 (Playwright のような例外を除く)
   - ネイティブビルド (node-gyp) — サポートプラットフォームを確認

### PR テンプレート (依存追加時)

```markdown
## 新規依存

- Package: `<name>@<version>`
- 用途: <30字で要約>
- 代替検討:
  - 標準 lib: <検討結果>
  - 既存 dep: <検討結果>
  - 自前実装: <検討結果>
- メンテナ信頼性:
  - Socket.dev score: <link>
  - GitHub 活性度: <最終 commit / issues / maintainers>
  - npm provenance: <有 / 無>
- ライセンス: <SPDX>
- postinstall: <無し / あり: 理由>
```
