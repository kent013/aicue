## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【本件の特殊事情 — 必読】
本件は「CI の定期実行トリガ (on.schedule) を除去せよ」というリポジトリオーナーの確定裁定の実装設計である。
過去 4 回同じ裁定が再発行されており、その理由は「実装担当エージェントが『セキュリティが低下する』
という評価を理由に実装を拒否し続けたから」である。オーナーは損失 (上流 advisory の先行検知が消える /
受理台帳の expiry 自動検出が消える) を把握したうえで受容済みであり、代替の定期実行枠組みは CI の外に
オーナー自身が用意する。
**したがって「schedule を残すべき」「別形で定期実行を復活させるべき」という方向の指摘は
本レビューでは無効である**。裁定の是非は蒸し返さないこと。
レビューすべきは「除去の完全性」「死んだ条件・死んだ記述の回収漏れ」「再導入を防ぐ機械ゲートの設計」
「スコープの妥当性 (膨らんでいないか / 落としていないか)」である。

【レビュー観点】
1. 除去の完全性: schedule に依存した条件・コメント・ドキュメント・機械ゲートの回収漏れはないか
2. 禁止事項違反: 上記禁止事項に抵触していないか (特に 1 = テストなしの完了、既存テストの扱い)
3. 機械ゲートの設計: 反転後の W12 / W15 は再導入を実際に止められるか。偽グリーン経路はないか。
   逆に過剰に厳しく将来を不当にブロックしないか
4. リスク: 重大な副作用・後退の可能性はないか
5. スコープの適切さ: 過大または過小になっていないか (小さいタスクである。膨らませてはならない)
6. 検証方法の妥当性: 「実装したが実は効いていない」を検出できる検証になっているか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: CI から nightly schedule を除去する (ci-schedule-removal)

- c2c feature: `supply-chain-audit-gate`
- 裁定: AG-030 (2026-08-05 12:59) / AG-030b (同 23:40) / AG-030c (同 23:45) /
  AG-030c 再周知 (2026-08-06 14:33)
- 想定 priority: Medium / theme: infrastructure

---

## 0. 裁定の位置づけ (蒸し返さない)

**これはオーナー裁定であり、本設計は「aicue でどう実装するか」だけを決める。**

裁定文が明示していること (そのまま与件として受ける):

- CI の定期実行トリガ (`on.schedule`) を**例外なく除去**する。
- 失うもの =「依存を変えていないのに上流で新しい advisory が公開された」ケースの先行検知と、
  `accepted-advisories.yaml` の保留期限 (expiry) の自動検出。
  **この損失はオーナーが把握したうえで受容済み**であり、**これを理由に実装を拒否してはならない**。
- 定期実行の枠組みは**オーナーが CI の外に別途用意する**。CI 側に残すことは代替にならない
  (「定期実行を CI に置くこと自体が責務の置き違い」という判断であって、実装の巧拙の問題ではない。
  aicue が入れた nightly は技術的には妥当だったが、それでも巻き戻すという裁定)。
- 除去理由を `ci.yml` のコメントに残し、次のセッションが再導入しないようにする。
- `workflow_dispatch` (手動起動の口) は**残してよい** (= 任意。必須ではない)。

したがって本設計は「schedule を残す/形を変えて残す」代替案を検討しない。
検討するのは**除去の完全性**(死んだ条件・死んだ記述を残さないこと)と
**再導入防止の機械化**だけである。

---

## 1. 仮説

> `on.schedule` を消すだけでは不十分で、**schedule の存在を前提にした条件・コメント・
> ドキュメント・機械ゲートが「死んだまま残る」**。特に
> `ci-workflow-inventory.test.ts` の W12 / W15 は schedule の存在を
> **deny-by-default で強制している**ため、schedule を消すと **CI が赤くなる**。
> 「schedule を消す」タスクの実体は「**schedule を強制している側を反転させる**」ことである。

成功条件:

1. `.github/workflows/ci.yml` に `schedule` / `github.event_name` に依存する条件が 1 つも残らない。
2. `pnpm test` が green (= inventory gate が新しい契約側に張り替わっている)。
3. リポジトリ内に「nightly で回る」と書いた記述が 1 つも残らない
   (過去の作業記録 = `docs/TODO-closed.md` を除く)。
4. schedule を再導入すると**機械的に落ちる** (負のコントロールで実測確認する)。

---

## 2. 現状 (実コードで裏を取った結果)

`rg -n "event_name != 'schedule'|on\.schedule|nightly|05:00 JST" -i` の全数実査。

### 2-1. `.github/workflows/ci.yml`

| 行 | 内容 | 扱い |
|---|---|---|
| L7-L8 | schedule の存在理由コメント (「無関係な PR のクリティカルパス外で先に検知」「PR job の代替ではなく追加」) | 除去 |
| L9-L10 | `schedule: - cron: "0 20 * * *"   # 05:00 JST` | **除去** |
| L16-L20 | `php` job の除外コメント 4 行 + `if: github.event_name != 'schedule'` | 除去 |
| L107-L111 | `browser-tests` job の同上 | 除去 |
| L179-L183 | `frontend` job の同上 | 除去 |
| L222- | `supply-chain-audit` job (`if` を持たない) | **維持** (裁定が明示) |

`on:` は `push.branches: [main]` と `pull_request` の 2 つになる。
現状 `workflow_dispatch` は**無い** (aigenba は元から持っていた点が違う)。

### 2-2. 機械ゲート — ここが本丸

`tests/js/architecture/ci-workflow-inventory.test.ts` が **schedule の存在を強制している**:

- **W12** (L222-224): `expect(workflow.on?.schedule).toBeDefined()`
  → schedule を消すと**この時点で fail する**。
- **W15** (L226-237): `php` / `frontend` / `browser-tests` の `if` が
  `"github.event_name != 'schedule'"` と**完全一致**すること + `supply-chain-audit` の `if` が
  `undefined` であること。
  → `if` を消すと**fail する**。

> **重要**: ブリーフは ci.yml と docs しか列挙していないが、実査するとこの 2 本が
> **schedule 除去を機械的にブロックしている**。ここを反転させないタスクは完了しない。

`scripts/vitest-inventory-gate.test.ts` は「書いたのに走っていないテスト」の検出であり、
ci.yml の内容には関与しない (テストファイルを増減させないので影響なし)。

### 2-3. ドキュメント

| ファイル | 行 | 内容 |
|---|---|---|
| `AGENTS.md` | L148 | 「加えて nightly (05:00 JST) でも回る」 |
| `docs/supply-chain/review-checklist.md` | L59-L64 | §6 の nightly 節まるごと (W15 への参照を含む) |
| `docs/supply-chain/review-checklist.md` | L74 | 一次対応表「nightly / PR いずれの赤化でも同一」 |
| `tests/js/architecture/verification-commands-doc-sync.test.ts` | L41 | `EXEMPT["audit:gate"]` の理由文字列「CI/nightly の blocking 実行が正本」 |
| `docs/TODO-closed.md` | L121 (T104) | 過去の作業記録。**触らない** (§6) |

`docs/testing-browser.md` は ci.yml に言及するが schedule には触れていない (影響なし)。
`.github/workflows/secret-scan.yml` は `pull_request` のみで schedule を持たない (対象外)。

### 2-4. 台帳 (c2c) との突き合わせ

`get_feature(supply-chain-audit-gate)` の記述と実コードの照合:

- 「aicue の ci.yml に schedule 節が残存」= **一致**。
- 「aicue のジョブは php / browser-tests / frontend / supply-chain-audit の 4 本」= **一致**。
- 「5 リポジトリ全数が監査 gate 自身の自己テストを保有」= aicue も
  `scripts/audit-gate.test.ts` を保有していて**一致**。
- history.background の「aicue は…ci.yml に配線しておらずローカル手動運用」は
  **AG-030 追従前の記述で現在は偽** (現に `supply-chain-audit` job が同梱されている)。
  ただしこれは history (経緯) 節であり、projects の note は正しく更新済み。
  → 実害のあるドリフトではないが、報告の `ledger_discrepancies` に記録する。
- 台帳・ブリーフとも **`ci-workflow-inventory.test.ts` の W12 / W15 が schedule の存在を
  強制していること**に触れていない。これは実査で初めて出た最重要事実である。

---

## 3. 課題

1. **死んだ条件の残置**: `if: github.event_name != 'schedule'` は schedule が無くなれば
   常に true。動作は変わらないが、「schedule があるはず」という誤った前提を読み手に与え、
   次のセッションが「じゃあ schedule を戻そう」と考える呼び水になる (実際 AG-030 → 再導入 →
   AG-030b という履歴がある)。**後方互換の並走を残さない** (思考原則 3) に真っ向から反する。
2. **機械ゲートの向きが逆**: W12 / W15 は「schedule がある」ことを守る gate である。
   放置すれば実装が進まない (CI 赤)。反転させれば**再導入を止める gate** に転用できる。
3. **文書が実体と食い違う**: nightly が無いのに「nightly でも回る」と書いてあると、
   supply-chain の運用責任 (誰がいつ気付くか) の記述が嘘になる。
4. **失う機能を黙って落とさない**: 先行検知と expiry 自動検出が消えることは
   受容済みだが、**受容したと文書に書いていない状態**にしてはいけない。

---

## 4. 方針

### 4-1. ci.yml — 除去 + 再導入防止コメント

`on:` を `push` / `pull_request` の 2 つに縮め、3 job の `if` とその説明コメントを削る。
`on:` の直上に**除去理由のコメント**を置く (裁定の明示要求):

```yaml
# 定期実行 (on.schedule) は持たない。CI の責務は push / pull_request の同期検査に限る
# というオーナー裁定 (2026-08-05 / 再周知 2026-08-06) による。
# 帰結として「依存を変えていないのに上流で新しい advisory が公開された」ケースの先行検知と
# accepted-advisories.yaml の expiry 切れの自動検出は次の push まで起きないが、
# これは受容済みで、定期実行の枠組みは CI の外に用意する (CI に戻さない)。
# 再導入は tests/js/architecture/ci-workflow-inventory.test.ts W12 が機械的に止める。
```

コメントは「何を消したか」ではなく「**なぜ戻してはいけないか**」を書く。
過去 1 回、技術的に妥当な形で再導入されて巻き戻された経緯があるため、
「妥当な実装なら残せる」という道が無いことまで書く。

### 4-2. inventory gate — 存在強制から不在強制へ反転

| ID | 変更前 | 変更後 |
|---|---|---|
| W12 | `on.schedule` が存在すること | **`on` のキー集合が `["pull_request", "push"]` と完全一致すること** |
| W15 | 3 job の `if` が schedule 除外と完全一致すること | **どの job も `if` を持たないこと** |

- W12 を「`schedule` が無いこと」ではなく**キー集合の完全一致**にするのは、
  `schedule` 以外の定期起動 (例: `repository_dispatch` を cron 代わりに使う外部叩き) を
  黙って足せないようにするため。トリガーを増やすなら**この行に登録させる** (W1 と同じ作法)。
  裁定が許した `workflow_dispatch` を将来足す場合も、ここへの登録が必須になる。
- W15 を「schedule 除外条件が無いこと」ではなく「**job-level `if` が 1 つも無いこと**」に
  するのは、`if: github.event_name != 'schedule'` を `if: github.event_name == 'push' || ...`
  のように言い換えた等価物まで一網打尽にするため。job に条件を足す必要が出たら
  この gate に登録させる (deny-by-default)。
- 既存ファイルの流儀に従い、**負のコントロール**を追加して「検出器が空振りしていない」
  ことを fixture で示す (schedule を戻した fixture / `if` を戻した fixture)。

### 4-3. ドキュメント — 失うものを明記する

- `AGENTS.md` §依存脆弱性: 「加えて nightly でも回る」を落とし、
  「**定期実行は持たない**」と裁定への参照に置き換える。
- `docs/supply-chain/review-checklist.md` §6: nightly 節を**受容の記録**に書き換える。
  - 検知の**間隔は push / PR の頻度に依存する**こと。
  - expiry 切れも次の push まで検出されないこと。
  - **埋め合わせに `continue-on-error` を足したり schedule を戻したりしない**こと。
  - 定期実行の枠組みは CI の外 (オーナー責務) であること。
  - 一次対応表の「nightly / PR いずれの赤化でも」→「push / PR の赤化」。
- `verification-commands-doc-sync.test.ts` の `EXEMPT["audit:gate"]` 理由文字列から
  `nightly` を落とす (機械判定には効かないが、嘘の記述を残さない)。

---

## 5. 代替案と却下理由

| 案 | 却下理由 |
|---|---|
| A. `schedule` を残し `supply-chain-audit` だけを回す現行形を維持 | **裁定で明示的に巻き戻された形**。AG-030b が「技術的には妥当だったが、定期実行を CI に置くこと自体が責務の置き違い」と記録している。「もっとうまく作れば残せる」道は無い |
| B. `schedule` を独立 workflow ファイルに切り出す | 裁定が「独立 workflow は作らない」と明示。かつ「CI の外」の意味は別ファイルではなく **CI そのものの外** |
| C. `if` だけ残す (schedule を消して条件は放置) | 死んだ条件の残置。思考原則 3 (後方互換の並走を残さない) 違反。かつ W15 が完全一致を要求しているので結局触ることになる |
| D. W12 / W15 を**削除**して gate を持たない | 再導入を止める手段が消える。過去に 1 回再導入された実績があり、「コメントだけ」では止まらないことが実測されている。既存テストの削除は禁止事項でもある (置換であって削除ではない形にする) |
| E. schedule 除去と同時に `workflow_dispatch` を追加 | 裁定は「残してよい」= 任意。aicue には元から無い。オーナーの CI 外枠組みの叩き方が未定の段階で口だけ開けるのはオーバーエンジニアリング (思考原則 2)。**後続 TODO 候補**として残す |
| F. expiry 切れ検知を artisan コマンド + Laravel scheduler に移す | 「定期実行の枠組みはオーナーが別途用意する」= リポジトリ側の宿題ではない。今作ると裁定の趣旨 (CI/アプリに定期実行を抱えさせない) を別の場所で再現するだけ |

---

## 6. スコープ境界

### やる

1. `.github/workflows/ci.yml`: `on.schedule` 除去 / 3 job の `if` と説明コメント除去 /
   再導入防止コメントの追加。
2. `tests/js/architecture/ci-workflow-inventory.test.ts`: W12 / W15 の反転 + 負のコントロール追加。
3. `AGENTS.md` §依存脆弱性 (supply-chain) の運用: nightly 記述の除去。
4. `docs/supply-chain/review-checklist.md` §6: nightly 節を受容の記録へ書き換え + 一次対応表の訂正。
5. `tests/js/architecture/verification-commands-doc-sync.test.ts`: `EXEMPT` 理由文字列の訂正。

### やらない (理由つき)

| 対象 | 理由 |
|---|---|
| `scripts/audit-gate.sh` / `.ts` / `.test.ts` / `.contract.test.ts` | 判定ロジック (accept-risk / expiry / severity 上限 / fail-closed) は本裁定の対象外。トリガーの話であって判定の話ではない |
| `docs/supply-chain/accepted-advisories.yaml` | 同上。中身は触らない |
| `supply-chain-audit` job 本体 (steps / `continue-on-error` 不在 / fail-closed) | **維持が裁定の明示要求**。消す話ではない |
| 独立 workflow ファイルの新設 | 裁定が明示的に禁止 |
| `workflow_dispatch` の追加 | 裁定は「残してよい」= 任意。現在無い口を今開ける必要が無い (思考原則 2)。後続 TODO 候補 |
| CI 外の定期検知の受け皿 (アラート基盤 / 外部 cron) | 「オーナーが別途用意する」と裁定が明記。リポジトリ側の実装課題ではない。**機能が消えることは docs に明記する**ので黙って落とすことにはならない |
| `docs/TODO-closed.md` の T104 行 | **過去の作業記録**。当時 nightly を入れたことは事実であり、事実の記録を書き換えると監査証跡が壊れる |
| `.github/workflows/secret-scan.yml` | schedule を持たない (`pull_request` のみ)。対象外 |
| `docs/testing-browser.md` | ci.yml に言及するが schedule には触れていない。影響なし |

---

## 7. 検証方法

1. **静的実査**: `rg -n "schedule|nightly|event_name" .github/workflows/ci.yml` が 0 hit
   (再導入防止コメントの本文で `schedule` に言及するため、正確には
   `rg -n "^\s*schedule:|github\.event_name"` が 0 hit)。
   `rg -n -i "nightly" AGENTS.md docs/ tests/ scripts/ --glob '!TODO-closed.md'` が 0 hit。
2. **YAML 構造**: `pnpm vitest run tests/js/architecture/ci-workflow-inventory.test.ts` が green。
3. **負のコントロール (実測必須)**: ci.yml に `schedule` を一時的に戻して W12 が
   **fail する**ことを実測 → revert。同様に 1 job に `if:` を戻して W15 が fail することを実測 → revert。
   fixture だけでなく**実ファイルで**確認する (T113 の作法)。
4. **回帰**: `pnpm test` / `pnpm lint` / `pnpm typecheck` が green。
   PHP 側は差分ゼロなので `composer test` は不要 (グローバルロックを無駄に占有しない)。
5. **gate 自体の生存**: `pnpm run audit:gate` がローカルで従来どおり通ること
   (判定ロジックを触らないので結果は不変のはず = ベースラインとの一致を確認)。

## 8. リスク

| リスク | 手当て |
|---|---|
| W12 のキー集合完全一致が将来のトリガー追加で偽赤になる | それが狙い (登録させる)。テスト側コメントに「増やすならここに登録」と明記する |
| W15 の「job-level `if` 全面禁止」が正当な条件付き job を将来ブロックする | 同上。deny-by-default であり、必要になったら理由とともに allowlist 化する (W14a/W14b と同じ形) |
| `on:` が YAML 1.1 の boolean `true` として parse される | 現行 W12 が `workflow.on?.schedule` で green である以上、`yaml` パッケージは文字列キー `on` として解釈している (実測済み)。挙動は変わらない |
| docs の書き換えで「安全性が下がった」と読まれる | 「受容済み」「代替は CI の外」「埋め合わせに continue-on-error を足さない」を明記することで、次のセッションが独自判断で戻すことを防ぐ |

---

## 参考: 現行 `.github/workflows/ci.yml` の該当部分 (抜粋)

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
  # 上流で新しい advisory が公開された事実を、無関係な PR のクリティカルパス外で先に検知する。
  # nightly は PR job の **代替ではなく追加** (PR job を降格させない)。
  schedule:
    - cron: "0 20 * * *"   # 05:00 JST

jobs:
  php:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
    # 本 job は schedule では走らせない (impl-review R1 [Warning]: ...)。
    if: github.event_name != 'schedule'
    ...
  browser-tests:   # 同じ 4 行コメント + if
  frontend:        # 同じ 4 行コメント + if
  supply-chain-audit:   # if は無い (nightly で走る唯一の job だった)
```

## 参考: 現行 `tests/js/architecture/ci-workflow-inventory.test.ts` の該当部分

```ts
    it("W12: on.schedule (nightly) が存在すること", () => {
        expect(workflow.on?.schedule).toBeDefined();
    });

    it("W15: nightly (schedule) では supply-chain-audit だけが走ること", () => {
        for (const name of ["php", "frontend", "browser-tests"]) {
            expect(job(workflow, name).if, `${name} が schedule から除外されていない`).toBe(
                "github.event_name != 'schedule'",
            );
        }
        expect(job(workflow, "supply-chain-audit").if).toBeUndefined();
    });

    it("W1: job 集合が完全一致すること (job を増やしたらここに登録させる)", () => {
        expect(Object.keys(workflow.jobs ?? {}).sort()).toEqual(
            ["browser-tests", "frontend", "php", "supply-chain-audit"].sort(),
        );
    });

    it("W13: continue-on-error が workflow のどこにも現れないこと (soft-fail 禁止)", () => {
        expect(findKeyPaths(workflow, "continue-on-error")).toEqual([]);
    });
```
