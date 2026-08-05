# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 2 / Suggestion 1)。
施策別: 1/2/3/6/7/8/9/11 は APPROVE、4/5/10 が REQUEST_CHANGES。
**Critical 2 件・Warning 2 件・Suggestion 1 件のすべてを対応した** (反論した指摘は無い)。

## [Critical] 施策 4/5: audit 取得失敗を空 JSON で通す既知穴は、このバッチで放置できない

- 判断: **対応する**
- 根拠: 完全に正しい。詳細設計 R0 では「既知の穴だが本バッチでは触らない」とリスク欄に書いていたが、
  これは論理的に破綻していた。**gate を blocking へ昇格させる施策そのものが、
  「取得失敗 = 緑」を初めて危険にする**。昇格前は誰も gate を信用していなかったので害が小さかったが、
  blocking にした瞬間「緑 = 安全」という意味づけが発生する。同一バッチで塞がなければ、
  施策 5 は「偽グリーンを公式化する」施策になってしまう。
  加えて実コードを読み直したところ、穴は 1 つではなく **2 段**あることが分かった:
  1. shell: 空出力を `{"advisories":{}}` に**捏造**して判定へ渡す
  2. 判定: `normalizePnpmAudit` / `normalizeComposerAudit` が `if (!obj.advisories) return []` で
     **valid だが schema 違いの JSON** (`{"error":{...}}` 等) を silent に 0 件へ落とす
  Codex の指摘は 1 を捉えていたが、2 も同じ性質の穴なので併せて塞ぐ。
- 対応内容: **施策 4A「`audit-gate.sh` の fail-closed 化」を新設**し、既存の advisory 解消を 4B へ改番。
  - shell: `acquire()` ヘルパで「出力が空なら fail-closed で exit 1」。空 JSON の捏造を削除。
    stderr を捨てず、失敗時のみログへ吐く。
    **exit code では止めない** — 「脆弱性検出の非ゼロ」と「取得失敗の非ゼロ」は
    exit code で区別できないため、判定基準を「有効な出力が得られたか」に置いた。
  - 判定: `assertAuditSourceShape(source, json)` を `audit-gate.ts` に追加し、
    `loadAuditJson` の `JSON.parse` 直後に呼ぶ。top-level コンテナ
    (`advisories` / pip は `dependencies`) の存在と型のみを検証する
    (中身の緩さは維持 = 未知フィールドで落とさない)。
  - contract test: `scripts/audit-gate.contract.test.ts` を新設。sandbox + PATH スタブ
    (`pnpm` / `composer`) で A1〜A6 の 6 シナリオを実プロセス検証する。
    スタブ `pnpm` は `pnpm exec tsx` も受け止めるので、「判定に到達したか / 手前で止まったか」を
    ファイルの有無で判定できる (判定ロジック自体は既存 unit テストの責務。責務を混ぜない)。
  - **負のコントロールとして A4/A5 (有効 JSON + 非ゼロ exit / 真の 0 件) が
    「止まらないこと」を確認する** — 過剰な fail-closed は運用不能になるため。
  - `assertAuditSourceShape` の unit テスト 6 本を `scripts/audit-gate.test.ts` へ追加 (既存は消さない)。
  - 実装順序を **4A → 4B → 5** と明記し「非交渉」とした。理由も明記:
    fail-closed 化前の `exit 0` は「本当に 0 件」と「取得できなかった」を区別できないため、
    4B の完了判定そのものが信用できない。

## [Critical] 施策 10 W9: YAML key 走査だけでは `BROWSER_TEST_LANES` の inline override を検出できない

- 判断: **対応する**
- 根拠: 正しい。`run: BROWSER_TEST_LANES=chromium composer test:browser` はキー走査を素通りする。
  これは T082 の WebKit レーンを CI で骨抜きにする、まさに本 gate が止めるべき経路だった。
  設計の穴を突かれた形で、反論の余地がない。
- 対応内容: 判断を W9 と W13 で分けた。
  - W13 (`continue-on-error`): **キー名のみ** 走査。GitHub Actions の予約キーであり
    キーとしてしか意味を持たないため、Codex の評価どおり key 走査で十分。
  - W9 (`BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES`): **キー名 + 全 scalar 値の中身**。
    `findScalarValuePathsContaining(node, needle)` を追加し、両走査の**和**が空であることを要求する。
  - 負のコントロールに `run:` 文字列版 2 種 (単一行 / 複数行 scalar) を追加し、
    **「キー走査は 0 件のまま、値走査が 1 件返す」ことを明示 assert** する
    (= 値走査が本当に必要であることの証明であり、追加した検査が空振りしていないことの証明)。
  - コメントは YAML parse 後に落ちるため、workflow 内で `BROWSER_TEST_LANES` を
    **説明する**運用とは衝突しない (施策 2 の workflow にその説明コメントがある) 旨を明記。

## [Warning] 施策 6: 負のコントロール記述と検査方式が一部ずれている

- 判断: **対応する**
- 根拠: 指摘のとおりの記述ミス。C2 (失敗レーンがあっても後続を走らせる) は層 1 (sandbox 実走) の
  責務なのに、負のコントロールを「静的検査が違反を返す」と書いてしまっていた。
  静的検査項目に C2 の検出器は無いので、この負のコントロールは**永久に空振りする記述**だった。
  負のコントロールが空振りするのは、gate が空振りするのと同じくらい悪い。
- 対応内容: 負のコントロールを**検出器と同じ層に置く**形へ書き直した。
  - 層 1 (実走) の負のコントロール 3 本: sandbox 内の**改変コピー**を走らせ、
    (a) `break` を入れると 1 行しか記録されない → C2 検査が空振りしていない証明、
    (b) 最後に `exit 0` すると exit code が 0 になる → C3 の証明、
    (c) 既定を `:-2` にすると `--parallel` が現れる → C4 の証明。
    **実ファイルは一切書き換えない** (mkdtemp 内の文字列置換で改変コピーを作る) 旨を明記。
  - 層 2 (静的) の負のコントロール 4 本 + 正のコントロール 1 本 (現行実ソースで違反 0 件)。
  - C5 (pgrep 掃除ブロック削除) の負のコントロールも追加した (元設計では抜けていた)。

## [Warning] 施策 8: 再帰防止条件の表現を実装に合わせるべき

- 判断: **対応する**
- 根拠: 指摘のとおり設計文が揺れていた。「`it()` の内側」と書きながら後段で `beforeAll` を許容していた。
  正しい条件は「収集フェーズ (import + `describe` 評価) で実行される場所に spawn を置かない」であり、
  `it` か `beforeAll` かは本質ではない。実装者が条件を誤読すると再帰か性能劣化のどちらかを踏む。
- 対応内容: 制約 1 の文言を
  「**module top-level と `describe` callback の中では絶対に spawn しない。
  許されるのは通常実行時にだけ走る `it` / `beforeAll` / `beforeEach` の内側**」
  へ書き換え、理由 (`vitest list` は import して `describe` を評価するが、
  `it`/hook の callback は登録されるだけで実行されない) を明記した。
  「helper 関数も呼ばれたときに spawn する形にし、module 初期化時に spawn しない」も追加。
  後段の `beforeAll` の注釈も同じ根拠に揃えた。

## [Suggestion] 施策 9: README inventory は将来の nested scripts も拾うとより堅い

- 判断: **対応する**
- 根拠: 「deny-by-default を名乗るなら recursive scan + 明示除外の方が筋が通る」は正しい。
  2 階層固定は「今は漏れないが、漏れるようになったときに黙って漏れる」設計であり、
  ドリフト検出を目的とする gate としては自己矛盾する。コストもほぼ変わらない。
- 対応内容: 走査を `scripts/` 配下の**再帰走査**に変更し、除外は
  `SCRIPTS_README_EXEMPT` 定数 (**初期値は空**) として明示保持する形にした。
  「理由を書けないものをここに足さないこと」というコメント規約も設計に含めた。

## Codex が「該当なし」と述べた観点について

DTO/JsonResource・Inertia Props・DESIGN.md・Atomic Design は本バッチで該当なし
(モデル / Controller / UI を一切追加しない) という Codex の評価に同意する。
dev DB 保護についても「`DB_DATABASE` 不在 + bootstrap 単一点ガードに寄せており壊していない」
という評価を得た (施策 1 の設計意図と一致)。
