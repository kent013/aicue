# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 3 / Suggestion 3)。
APPROVED だが Warning 3 件はすべて設計の穴を突いているため、全件を概念設計へ反映した。

## [Warning] audit:gate の運用責任がまだ設計上やや弱い

- 判断: **対応する**
- 根拠: 指摘のとおり。「blocking にする」だけ決めて「赤くなったとき誰が何時間以内に何をするか」を
  決めないと、運用は結局「赤いまま放置」= soft-fail と同じ結末に収束する。
  gate の実効性は判定ロジックではなく初動の決めに依存する。
- 対応内容: 判断 B に「運用責任の明文化」表を追加した。
  - 一次対応 owner = リポジトリオーナー (nightly / PR 共通)
  - 初動 SLA = critical 当日 / high 2 営業日 / moderate は warn のみ (SLA なし)
  - accept-risk 承認者: 単独開発体制のため `approved_by` = owner と明記し、代替統制として
    `expiry` 上限 (high 30 日) + `tracking_issue` 必須で外部追跡可能にする
  - `tracking_issue` の機械強制は `audit-gate.ts` に既存 (追加実装不要。設計上の依存として明記)
  - Dependabot / Renovate は**本バッチでは導入しない**と明示的に決めた
    (思考原則 2。gate 単体で運用して「人手で回らない」ことが観測されてから検討する)
  - 反映先: `docs/supply-chain/review-checklist.md` への追記を施策 5 に含めた
- Codex が挙げた「第 4 の選択肢」(lockfile/manifest 変更時のみ blocking) は Codex 自身が
  「既存 advisory を抱えた main を許容しやすい」として不採用を支持しており、こちらも採らない。

## [Warning] browser lane の 2 レーン契約は CI 設定側の固定テストが必要

- 判断: **対応する**
- 根拠: 完全に正しい。`run-browser-test.sh` の契約テストは「スクリプトを壊す退行」しか止められず、
  workflow 側で `BROWSER_TEST_LANES: chromium` を env に足す退行はスクリプトを一切壊さずに通る。
  T082 の「実行時間を理由に WebKit を落とさない」は**スクリプトの契約ではなく運用の契約**なので、
  運用の記述場所 (workflow) 側に gate を置かないと守れない。
- 対応内容: 施策 10 と判断 H を新設した。
  `tests/js/architecture/ci-workflow-inventory.test.ts` が `.github/workflows/ci.yml` を
  `yaml` (既存 devDependency) で parse し、Codex の挙げた 6 項目を含む 9 項目を deny-by-default で固定する。
  特に **「全 job / 全 step に `continue-on-error` が無い」** を入れたことで、
  判断 B の「soft-fail を採らない」が意思表明ではなく機械強制になった。

## [Warning] vitest inventory gate は workflow 側との結合も固定した方がよい

- 判断: **対応する** (3 件の修正提案すべて)
- 根拠: 「gate はあるが CI がその project を走らせない」= gate の空洞化。指摘のとおり。
- 対応内容:
  1. `frontend` job が `pnpm test` と `pnpm test:packages` の両方を呼ぶこと → 施策 10 (判断 H) に含めた
  2. gate 自身が root project に列挙されることの明示 assert → 判断 F に追記
  3. 再帰防止 env を入れず `vitest list --json=<tmpfile>` のみに限定 → 判断 F に追記。
     理由も明記した (env フラグは「そのフラグが立つと gate が空振りする」新しい偽グリーン経路になる)

## [Suggestion] make-shard-phpunit.php 削除は妥当

- 判断: **反映不要** (既に設計どおり)
- 根拠: Codex は判断 D と `ScriptsReadmeInventoryTest` 新設をそのまま支持している。
  「将来必要になったら run-test.sh 側へ shard 引数を通す形で再設計」も設計に既記載。

## [Suggestion] PostgreSQL 18 採用は妥当だが、Actions runner 側の ready check を明示したい

- 判断: **対応する**
- 根拠: `ensure-test-db.php` は fail-closed なので偽グリーンにはならないが、
  起動待ちの揺らぎは flake (偽赤) を生む。偽赤も「CI を信じなくなる」経路なので潰す価値がある。
- 対応内容: 判断 G に
  `options: --health-cmd pg_isready --health-interval 5s --health-timeout 5s --health-retries 10`
  を `php` / `browser-tests` 両 job の service に明示すると追記した。

## [Suggestion] スコープは大きいが 1 バッチとしては許容範囲

- 判断: **一部対応** (先行 PR 分割の可能性を制約に明記済み)
- 根拠: Codex は「audit 解消だけは時間 drift の影響を受けるため、実装時に再実測し、
  必要なら supply-chain 部分だけ先行 PR に分ける判断はあり得る」としている。
  これは既に §6 制約 6 (「advisory 集合は時間で drift する。着手時に再実測」) と
  施策 4 → 5 の依存関係で表現済み。施策 4/5 は独立の TODO として切れる形になっている。
- 対応内容: 追加変更なし (詳細設計の「実装モード」でこの分割可能性を明示する)。
