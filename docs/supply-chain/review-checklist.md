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

## 6. CI での実行と運用責任

`pnpm run audit:gate` は GitHub Actions の `supply-chain-audit` job で実行される。

- **PR / push (main)**: blocking。`continue-on-error` は付けない
  (soft-fail は「赤いのに緑に見える」= baseline 化と同型のため採らない)。
- **定期実行 (schedule) は持たない**: CI の責務は push / pull_request の同期検査に限る、
  というオーナー裁定 (2026-08-05 / 再周知 2026-08-06)。
  **実装の巧拙の問題ではない** — 「workflow 起動と job 実行を分けて供給網監査だけを
  定期実行する」技術的に妥当な実装が一度入り、それでも巻き戻された経緯がある。
  「もっとうまく作れば残せる」道は無い。
  `.github/workflows/ci.yml` の `on:` は `push` / `pull_request` の 2 つで、
  `tests/js/architecture/ci-workflow-inventory.test.ts` の
  W12 (ci.yml のトリガー集合の完全一致) / W15 (job-level `if` の不在) /
  **W17 (`.github/workflows/` 配下の全 workflow に `schedule` が無いこと)** が
  再導入を機械的に止める。別 workflow を新設して定期実行を戻す経路も W17 が塞ぐ。

取得失敗 (network 不通・レジストリ障害) は **advisory 0 件として扱わない**。
`scripts/audit-gate.sh` が空出力・前処理失敗をそこで止め、`assertAuditSourceShape` が
「valid JSON だが期待 schema でない」出力を弾く (fail-closed)。一過性の赤は re-run で回復する。

### 定期実行を持たないことで失うもの (受容済み)

| 失うもの | 帰結 |
|---|---|
| 上流で新しい advisory が公開された事実の先行検知 | 依存を変えない限り、**次の push / PR まで検出されない**。検知の間隔は push / PR の頻度に依存する |
| `accepted-advisories.yaml` の expiry 切れの自動検出 | 同じく次の push / PR まで検出されない。期限を過ぎた entry が気付かれないまま残る期間が生じうる |

これは把握したうえでの受容であり、**埋め合わせに `continue-on-error` を足す /
gate を除外リスト化する / schedule を戻す、のいずれもしない**。
定期的な検知が必要になったときの枠組みは **CI の外**に用意する。
どういう形で用意するかはオーナーの裁量であり、**リポジトリ側で代替の定期実行を作らない**。

### 一次対応

| 項目 | 決め |
|---|---|
| 一次対応 owner | リポジトリオーナー (`ishitoya`)。push / PR いずれの赤化でも同一 |
| 初動 SLA | critical: 当日中に判断 / high: 2 営業日以内に判断 / moderate: warn のみ (SLA なし) |
| 「判断」の中身 | upgrade で解消する、または §3 の上限内で accept-risk を登録する、のいずれか |
| accept-risk の承認者 | 単独開発体制のため `approved_by` = owner。代替統制として `expiry` 上限 (high 30 日) と `tracking_issue` 必須で外部から追跡可能にする (`audit-gate.ts` が両方を機械強制) |
| 自動 upgrade PR (Dependabot / Renovate) | **現時点では導入しない**。gate 単体で運用し「upgrade 追従が人手で回らない」ことが観測されてから検討する |

### 上流由来で全 PR が赤くなったとき

新しい advisory の公開は無関係な PR も止める。これは gate の副作用ではなく**意図した挙動**
(未受容の high を抱えたまま main が進むことを許さない)。逃げ道は §3 の期限付き accept-risk のみで、
`continue-on-error` の追加や gate の除外リスト化はしない。

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
