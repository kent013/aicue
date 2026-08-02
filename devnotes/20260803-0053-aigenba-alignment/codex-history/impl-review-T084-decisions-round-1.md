# 対応マトリクス: impl-review-T084 Round 1

対象: `todo/T084` (施策 11-14 / トラック T-c)。
Codex 返答: `../impl-review-T084-round-1.md` (gpt-5.3-codex / reasoning=high / verdict: CHANGES_REQUESTED)

---

## [Critical] `bughuntGateFirstEffectiveStatement()` が `local ...` を無条件で前置き扱いする

`tests/Architecture/BughuntOrchestratorGateInvariantTest.php:78`

- **判断**: **対応する**
- **根拠**: 指摘は正しい。bash の `local x="$(cmd)"` / `` local x=`cmd` `` / `local fd=<(cmd)` は
  **コマンドを実行する**。`/^local\s/` で一律 skip すると、`require_orchestrator` より前に
  任意コマンドを差し込んでも「最初の実効文は require_orchestrator」と判定され green になる。
  本 gate の存在理由 (AGENTS.md §bug-hunt「dev DB 防御 (非交渉)」= 副作用の前に die する)
  に対して **silent hole** であり、設計書 施策 11-2 が要求する「default-deny の 2 層 gate 崩れの検出」を
  実際には固定できていない。詳細設計書からの逸脱ではなく、**実装側の穴**なので反論の余地はない。
- **対応内容**:
  - `bughuntGateIsInertLocal(string $trimmed): bool` を新設し、`local` 行でも
    `$(` / backtick / `<(` / `>(` を含むものは **実効文として扱う** (= gate より前にあれば fail)。
  - `bughuntGateFirstEffectiveStatement()` はこの判定器を使う。
  - 負のコントロールを追加:
    「`local db="$(dropdb --if-exists app_dev)"` を gate の前に置いた fixture で、
    最初の実効文が `require_orchestrator` にならない」ことを固定。
    backtick / process substitution も `bughuntGateIsInertLocal()` が false を返すことを直接固定。
    正のコントロールとして、実スクリプトの現行形 (`local shard=$1 run_id=$2` 等 3 種) が
    true のままであることも固定した (誤爆で gate が使えなくなる方向の退行も防ぐ)。
  - 実 `scripts/bug-hunt-shard.sh` の `cmd_provision` / `cmd_provision_all` / `cmd_teardown` の
    gate 前 `local` は全て純粋な引数束縛 (`local shard=$1 run_id=$2` / `local n=$1 hold=${2:-}` /
    `local run_id=$1 drop_db=${2:-}`) であることを確認済み。よって本修正で実スクリプトは green のまま。

---

## [Warning] exit code 契約の実走テストが python3 不在で skip される

`tests/Architecture/BugHuntInventoryCheckInvariantTest.php:118`

- **判断**: **対応する**
- **根拠**: 施策 11-3 の核心は「exit code 規約 0=一致 / 3=ドリフト を **実走で** 満たすこと」であり、
  詳細設計書のテスト計画も「空振り gate を green として扱わない」を全 gate 共通で要求している。
  `markTestSkipped` は環境不備を green に変換する経路で、この要求と正面から矛盾する。
  加えて `scripts/bug-hunt-inventory-check.sh` 自体が `python3 -c` に依存しており、
  python3 不在の環境ではそもそもドリフト検知が動かない = **skip すべき状況ではなく報告すべき環境不備**。
- **対応内容**: `bhicRequirePython3()` を新設し、3 箇所の `markTestSkipped` を
  「python3 不在なら明示 fail (メッセージ付き)」に置換した。

---

## [Warning] `Inertia::render` / `inertia(` 検出が大文字小文字を厳密一致している

`tests/Architecture/InertiaRenderPageExistsInvariantTest.php`

- **判断**: **対応する**
- **根拠**: 指摘は正しい。PHP のクラス名・関数名は case-insensitive なので
  `inertia::render('X')` / `Inertia('X')` / `Inertia::RENDER('X')` は**実際に動く呼び出し**である。
  厳密一致だとこれらが literal にも dynamics にも載らず、**参照先ページ不在の白画面が gate をすり抜ける**。
  本ファイルは自ら「非 literal・`Route::inertia`・非正準 facade 参照は出現したら fail させる
  (deny-by-default)」と宣言しており、case 違いのすり抜けは**そのファイル自身の契約違反**にあたる。
- **対応内容**:
  - `inertiaIsIdentifier()` / `inertiaIsFacadeName()` を新設し、識別子比較を全て case 無視にした
    (`Inertia` / `render` / `inertia` helper / `Route` / `Facades\Route` / `Inertia\Inertia`)。
  - facade 検出ブロックが「`::` が続かない場合に `continue` する」構造だと、case 無視化により
    helper 形 `Inertia('X')` を**飲み込んでしまう**ため、`::` 非継続時は helper 検出へ落ちるよう
    分岐を組み替えた (単純に `strcasecmp` へ置換すると既存 helper 検出が壊れる = 退行になるため)。
  - 負のコントロール追加: `inertia::render` / `Inertia(` / `Inertia::RENDER` / `ROUTE::Inertia` /
    `\inertia\inertia::render` を全て検出することを固定。
  - 正のコントロール追加: `Inertia::share()` / `Inertia::version()` / `Inertia::class` を
    ページ参照として誤検出しないことを固定 (case 無視化で誤検出が増えていないことの担保)。

---

## [Suggestion] SKILL.md の継続規約 pin を「意味 pin」へ寄せる

`tests/Architecture/BugHuntSkillInvariantTest.php:57`

- **判断**: **見送る (反論)**
- **根拠**:
  1. 詳細設計書 施策 11-4 の固定対象は「『finding は停止信号ではない』規約の**喪失**」であり、
     SoT は **AI-CUE の SKILL.md の文言そのもの**と明記されている (「aigenba の文言・path を
     比較対象にしない」= AI-CUE 自身の文言が正本)。文言一致はこの設計の意図どおりである。
  2. 固定する事故は「継続規定が『続行できるなら続行』程度の**任意**に薄まる」こと。
     これは意味論的には近いまま強度だけ落ちる退行であり、「意味 pin」では原理的に検出できない。
     強度の差を機械的に見分けられるのは断定的な文言そのものの有無だけである。
  3. 誤爆コストは低い。違反メッセージは
     `SKILL.md から規約が失われている: {規約名}` の形で**日本語の規約名**を返すため、
     文書をリライトした開発者は「その規約を落とさず書き直す」か「pin を更新する」かを直ちに判断できる。
     これは規約文書を変えるときに規約テストを一緒に更新する、という既存 Architecture テストの作法と同じ。
  4. 一方で Suggestion の指摘する運用負荷は実在するため、**将来 SKILL.md の大改訂が必要になった時点で**
     pin の粒度を見直す余地は残す (今この時点で緩めると、移植したばかりの gate が最初から
     「薄まった状態」を通す方向へ倒れる)。
- **対応内容**: 実装変更なし。本文書に根拠を記録するに留める。

---

## 修正後の検証

| コマンド | 結果 |
|---|---|
| `composer test -- --testsuite=Architecture` | 132 tests / 1139 assertions passed (中間確認) |
| `composer test` | `{"result":"passed","tests":2035,"passed":2033,"assertions":8369,"skipped":2}` / exit 0。修正前 2032 → 2035 (負のコントロール 3 本追加)。skipped 2 は既存分 (本ラウンドで `markTestSkipped` を 3 箇所削除したため、skip は増えていない) |
| `composer phpstan` | 679 files → `[OK] No errors`。baseline 追加・型 widen なし (禁止事項 #2 遵守) |
| `vendor/bin/pint --test` | `{"result":"passed"}` |
| `pnpm exec vitest run tests/js/architecture/pages-path-case-invariant.test.ts` | 4 tests passed |

JS 側 (`pnpm lint` / `typecheck` / `test` / `build`) は本ラウンドで JS の変更が無いため
全量再実行はせず、施策 12 の対象ファイルの再走のみとした (T084 実装時に全量 green を確認済み)。

なお `phpstan.neon` の `paths` は `app` / `config` / `database` / `routes` のみで **`tests/` は
解析対象外** (既存リポジトリ規約)。したがって本ラウンドで追加した PHP コードは PHPStan では
守られていないため、`@var` / `@return` と実値の整合は目視で確認した
(`bughuntGateIsInertLocal(): bool` / `inertiaIsIdentifier(): bool` / `inertiaIsFacadeName(): bool` /
`bhicRequirePython3(): void` はいずれも単純な述語・手続きで、注釈と実装が一致している)。
