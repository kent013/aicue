# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 5)。
施策別: 1/2/3/4B/5/7/8/11 は APPROVE、4A/6/9/10 が REQUEST_CHANGES。
**全 7 件を対応した** (反論した指摘は無い)。

## [Critical] 施策 4A: `assertAuditSourceShape()` が Composer の `advisories: []` を受理する

- 判断: **対応する**
- 根拠: 完全に正しく、かつ致命的。設計コードは pnpm と composer を同じ条件
  (`typeof advisories === "object"`) で検査していたが、JS の `typeof [] === "object"` なので
  composer に `{"advisories":[]}` が来ると通過する。そして `normalizeComposerAudit` の
  `typeof obj.advisories !== "object"` も配列を弾かないため、`Object.entries([])` = `[]` で
  **advisory 0 件 = 緑**に落ちる。**塞いだつもりの穴を、塞いだコード自体が再び開けていた**。
  fail-closed 化の意味が失われるので最優先で修正。
- 対応内容: `switch (source)` で source ごとに期待コンテナ型を分けた。
  - `pnpm-audit`: object **または** array (normalizePnpmAudit が両対応しているため)
  - `composer-audit`: **array でない** object 固定 (`Array.isArray(c)` を明示的に弾く)
  - `pip-audit`: `dependencies` が array
  なぜ共通条件にしてはいけないかをコメントに残した (再発防止)。
  判定層テストに **`{"advisories":[]}` を composer として読むと throw する**ケースを追加し、
  同じ入力を pnpm として読むと throw しないことも併記した (source ごとの差が効いている証明)。

## [Critical] 施策 9: 再帰走査すると `scripts/README.md` 自身が未登録ファイルになる

- 判断: **対応する**
- 根拠: 正しい。Round 1 の Suggestion (再帰走査化) を採り入れた結果、
  **走査対象に台帳自身が入ってしまい、exemption を空にしたため初期状態から必ず赤になる**設計だった。
  Round 1 の対応が新しいバグを作った典型で、Codex が拾ってくれなければ実装初日に踏んでいた。
- 対応内容: Codex が「最も素直」とした案を採用し、`SCRIPTS_README_EXEMPT` に
  `'README.md' => '台帳ファイル自身 (表の正本であって、表に載る対象ではない)'` を登録した。
  拡張子で絞る案は採らない — 拡張子ホワイトリストは「新しい種類のスクリプトが黙って漏れる」
  という、まさに本 gate が防ぎたい失敗モードを持ち込むため。
  あわせてテスト計画に
  「実装直後に現状で緑になることを確認する」「exemption 定数が実在ファイルだけを指すことを検査する」
  (死んだ exemption の残置で除外が形骸化するのを防ぐ) を追加した。

## [Warning] 施策 4A: `STDERR_LOG` の生成と cleanup が設計コードに無い

- 判断: **対応する**
- 根拠: そのとおりで、`set -u` 配下なので最初の `acquire` で即死する。設計コードの記述漏れ。
- 対応内容: `STDERR_LOG="$(mktemp)"` と、既存 `trap ... EXIT` への `$STDERR_LOG` 追加を明記した。
  あわせて Codex の指摘どおり**取得ごとに truncate** する形 (`2>` であって `2>>` ではない) にし、
  「composer 失敗時に pnpm の古い stderr が混ざって原因が読めなくなる」ことを防いだ。
  取得成功時も stderr を診断用に流す 1 行を足した (警告を握り潰さない)。

## [Warning] 施策 4A: shape 検証の「関数単体」はテストされるが `loadAuditJson()` への配線が保証されない

- 判断: **対応する**
- 根拠: 鋭い。A3 (不正 JSON) はスタブ `pnpm exec tsx` が受け止めるため判定は実行されず、
  実装者が `assertAuditSourceShape` を **export しただけで呼び忘れても**全テストが緑になる。
  「関数はあるが配線されていない」= gate が存在するのに効いていない、という本バッチが
  一貫して潰そうとしている失敗モードそのもの。
- 対応内容: 判定層テストを**関数単体ではなく `loadAuditJson` 経由**に変更し、
  一時 JSON ファイルを書いて読ませる形にした。ケースは Codex の提案 4 種を含む 9 種:
  不正 JSON / `{"error":...}` / composer `{"advisories":[]}` / pnpm `{"advisories":[]}` /
  正常な空コンテナ (pnpm・composer) / top-level 配列 / pip 正常・異常 / source と normalizer の誤対応。

## [Warning] 施策 4A: pip 取得経路が contract test の対象外

- 判断: **対応する**
- 根拠: 「pip-audit も同じ `acquire` を通す」と新しい契約を宣言しながらテストしないのは
  契約として不完全。さらに Codex の指摘した「先行する `uv export` の空出力/失敗」は
  **より危険**で、requirements が空なら pip-audit は正常終了して「依存 0 件 = advisory 0 件」を返す
  = 有効な JSON なので shape 検証も通る。ここは shell 側で止めるしかない。
- 対応内容: `uv export` **も** `acquire` を通す形に変更後コードを書き直した。
  contract test に A7 (`uv export` 空出力) / A8 (`pip-audit` 空出力) / A9 (`pyproject.toml` なしで
  pip 経路を実行せず判定へ到達 = オプトイン条件を壊していないことの確認) を追加した。

## [Warning] 施策 6: 負のコントロールの文字列置換が成功した保証を追加すべき + 件数表記の不一致

- 判断: **対応する** (両方)
- 根拠: 「置換対象が将来変わると、改変されていないコピーを broken fixture として実行する」
  = 負のコントロールが黙って空振りする。負のコントロールの空振りは gate の空振りと同じ害がある
  (むしろ「守られている」という誤った安心を与える分だけ悪い)。件数不一致も設計文の欠陥。
- 対応内容: `mutate(source, from, to)` ヘルパを設計に追加し、
  (1) 置換対象がちょうど 1 箇所であること、(2) 置換後にソースが変化すること、
  (3) 置換後に期待トークンを含むこと、の 3 点を throw で強制する形にした。
  件数表記を「負のコントロール 7 本 (層 1 実走 3 + 層 2 静的 4) + 正のコントロール 1 本」に修正した。

## [Warning] 施策 10: W9 の保証範囲を明示する必要がある

- 判断: **対応する** (Codex が提示した 2 案のうち、**前者 (allowlist で経路自体を塞ぐ)** を採用)
- 根拠: Codex 自身が「前者の方が deny-by-default という設計方針と整合する」としており同意する。
  保証範囲を書くだけの案は「将来 composite action を挟むだけで W9 が空洞化する」ことを許す。
  静的検査の射程外を認めた上で**射程外の経路が生えること自体を止める**方が、
  本バッチが一貫して採っている deny-by-default に沿う。
- 対応内容: **W14 を追加**した。`browser-tests` job の `steps[*].uses` を
  setup action allowlist (`actions/checkout` / `shivammathur/setup-php` / `pnpm/action-setup` /
  `actions/setup-node`) に限定し、`composer test:browser` が `run` で直接実行されることを要求する。
  - allowlist の比較は `@version` を除いた action 名で行う (version 上げで偽赤にしない)
  - W14 は `browser-tests` job にのみ課す (骨抜きの標的は browser lane。全 job への過剰な制約は避ける)
  - W9 の保証範囲 (「`ci.yml` に直接記述された範囲」) と、W14 によって
    `browser-tests` ではその範囲が job 全体と一致することを設計に明記した
  - 負のコントロールに「allowlist 外の local composite action を混ぜる」
    「`composer test:browser` を composite action へ移す」の 2 種を追加 (計 7 本)
