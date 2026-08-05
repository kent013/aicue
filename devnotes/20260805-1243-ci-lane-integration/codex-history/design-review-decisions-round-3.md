# 対応マトリクス: design-review Round 3

Codex 全体判定: **CHANGES_REQUESTED** (Critical 1 / Warning 6)。
施策別: 1/2/3/4B/5/6/7/8/11 は APPROVE、4A/9/10 が REQUEST_CHANGES。
**全 7 件を対応した** (反論した指摘は無い)。

## [Critical] 施策 4A: `uv export` の非ゼロ終了を無視してはいけない

- 判断: **対応する**
- 根拠: 正しい。Round 2 で「`uv export` も `acquire` を通す」と直したが、
  **`acquire` は audit ツール向けに「非ゼロ exit を許容する」設計**だった。
  audit の非ゼロには「脆弱性を検出した」という正常系があるが、`uv export` の非ゼロは**常に失敗**である。
  同じハンドラを使ったせいで「`uv export` が部分的 / コメントだけの非空出力を残して失敗 →
  痩せた requirements で pip-audit が正常終了 → advisory 0 件で緑」という経路が残っていた。
  Round 2 の修正が別の穴を開けた形で、Codex の指摘が的確。
- 対応内容: 取得関数を**契約別に 2 本へ分割**した。
  - `acquire_audit(label, out, cmd…)`: 非空出力を要求、**非ゼロ exit を許容**
    (pnpm / composer / pip-audit)
  - `acquire_required(label, out, cmd…)`: 非空出力 **かつ exit 0** を要求 (`uv export`)
  実体は `_run_acquire` に集約し `require_zero` フラグで切り替える (ロジックの二重管理を作らない)。
  各関数の doc に「なぜ exit code の扱いが違うのか」を明記した。
  contract test に **A7b (`uv export` が非空出力 + exit 1)** を追加した
  — Codex の指摘どおり A7a (空出力) だけではこの経路を検出できない。
  あわせて A9 (`pip-audit` が有効 JSON + 非ゼロ = 正常系で止まらないこと) も追加し、
  `acquire_audit` 側が過剰に締まっていないことも固定した。

## [Warning] 施策 4A: top-level コンテナだけの検査では内部 schema 不整合が 0 件へ落ちる

- 判断: **対応する**
- 根拠: 正しい。`{"advisories":{"vendor/pkg":{"error":"unavailable"}}}` は top-level 検査を通るが、
  `normalizeComposerAudit` の `Array.isArray(advisoriesUnknown) ? … : []` で**黙って 0 件**になる。
  Round 2 で「top-level のみに絞るのが適切な結合度」と書いたが、それは
  「normalizer が走査に使う構造」を見誤っていた。normalizer が黙って握り潰す分岐がある限り、
  その分岐に落ちる入力は fail-closed の対象にしなければ意味がない。
- 対応内容: 検証の深さを「**normalizer が走査に使う最小構造**」まで広げた。
  - composer: 各 package 値が array であること
  - pnpm: 各 advisory entry が非 null の object であること (primitive entry を弾く)
  - pip: 各 dependency が object で `name` が string、`vulns` が array であること
  - **空コンテナ (`{}` / `[]` / 空 `vulns`) は正当な 0 件として通す** (過剰な締めつけを避ける)
  未知フィールドは引き続き許容する (normalizer の緩さは維持)。
  判定層テストに該当ケース 4 種 (composer の壊れた値 / pnpm の primitive entry ×2 /
  pip の `{}` dependency / pip の空 `vulns` 正常系) を追加した。

## [Warning] 施策 4A: `source` と `normalizer` を別引数にすると誤配線が型上可能

- 判断: **対応する**
- 根拠: 正しい。pnpm と composer はどちらも object 形式の `advisories` を持ちうるので、
  shape 検査だけでは normalizer 取り違えを**常には**検出できない。
  Round 2 で「source と normalizer の誤対応を検出するテスト」を計画に入れたが、
  それは「表現可能な誤りをテストで拾う」設計であり、
  **誤りを表現不能にする**方が上位の解 (思考原則: 仕組みで守る)。
- 対応内容: `loadAuditJson(path, source)` に変更し、`NORMALIZERS: Record<AuditSource, …>` で
  内部選択する形にした。誤配線が型として書けなくなったため、
  対応するテストを「誤対応の検出」から「`NORMALIZERS` が全 `AuditSource` を網羅すること」へ置き換えた。
  **波及**: 既存 `scripts/audit-gate.test.ts:341` の `loadAuditJson(tmp, normalizePnpmAudit)` を
  `loadAuditJson(tmp, "pnpm-audit")` へ追従させる必要がある。波及変更欄に明記した
  (既存テストの削除・上書きではなく呼び出し形の追従)。

## [Warning] 施策 4A: A7/A8 には `bin/uv` スタブが必要だが sandbox 構成に記載が無い

- 判断: **対応する**
- 根拠: 記述漏れ。スタブが無ければ A7/A8 は実行できない。
- 対応内容: sandbox 構成に `bin/uv` を追加し、`uv export ...` と
  `uv tool run --from pip-audit …` の 2 分岐を持つことを明記した。
  あわせて `pyproject.toml` を「A7/A8 のシナリオでのみ配置する」ことも明記した
  (pip 経路のオプトイン条件を実際に踏ませるため)。

## [Warning] 施策 9: 設計文に矛盾が残っている

- 判断: **対応する** (3 点すべて)
- 根拠: Round 2 の修正が本文にだけ入り、S1 の表と「初期値ゼロ」の記述が取り残されていた。
  設計文の内部矛盾は実装者がどちらを信じるかで結果が変わるので潰す。
- 対応内容:
  1. S1 を「**`scripts/` 配下の全ファイル (明示 exemption を除く)**」へ変更
  2. 「明示 exemption は初期値ゼロ」→「**初期状態では `README.md` の 1 件のみ**」へ変更
  3. exemption の検査を **S4** として独立させ、「key が実在ファイルを指す」に加えて
     Codex の提案どおり「**値 (理由) が非空文字列である**」も検査対象にした
     (理由なし除外を許すと exemption が形骸化するため)。負のコントロールも 4 本へ増やした。

## [Warning] 施策 10: W14 は local/composite action を塞ぐが通常の `run` 経由を塞いでいない

- 判断: **対応する** (Codex の 2 案のうち **前者 = `run` の構造的 inventory** を採用)
- 根拠: 正しい。`run: bash scripts/prepare-browser-ci.sh` が `$GITHUB_ENV` へ書けば
  W9 の射程外であり、Round 2 で書いた「browser-tests では ci.yml の範囲が job 全体と一致する」
  という主張は**成立していなかった**。deny-by-default を名乗る以上、後者 (保証外と明記) は逃げ。
- 対応内容: W14 を W14a/W14b/W14c の 3 本へ分解した。
  - **W14a**: `steps[*].uses` を既知の信頼済み setup action 4 種に限定 (Round 2 の内容)
  - **W14b (新規)**: `steps[*].run` の**全実行行**が `BROWSER_JOB_ALLOWED_RUN_LINES` に
    完全一致すること。空行とコメント行を除いた行単位で判定する。
    allowlist 定数のコメントに「追加時は BROWSER_TEST_* を設定しうるか確認せよ」と明記
  - 負のコントロールに `run: bash scripts/prepare-browser-ci.sh` を追加
  - 保証範囲の記述も正確に書き直した (他 job の `run` は保証範囲外だが、
    それらは `composer test:browser` を実行しないので実害が無い、と明示)

## [Warning] 施策 10: `runScript(job).includes("composer test:browser")` では直接実行を保証できない

- 判断: **対応する**
- 根拠: 正しい。`run: echo "composer test:browser"` が素通りする。
  「レーンが走っている」ことを守る gate が「文字列が書いてある」ことしか見ていなかった。
- 対応内容: **W14c** を追加し、`run.trim()` が `composer test:browser` と**完全一致**する step が
  ちょうど 1 つ存在することを要求する。これで `echo` / シェル演算子 /
  環境変数付与 (`BROWSER_TEST_LANES=... composer test:browser`) をすべて拒否できる。
  W14b との重複に見えるが責務が違う (W14b = 許されない行の**不在**、W14c = 起動行の**存在**) ので
  両方置くことを設計に明記した。
  実装方針の項に「`browser-tests` の `composer test:browser` だけは `includes` で判定しない」旨も追記した。
  負のコントロールに `run: echo "composer test:browser"` を追加した。

## Codex の確認事項について

- 施策 6 の `mutate()` が負のコントロールの空振りを防げているという評価に同意する (変更なし)。
- 施策 1/2/3/4B/5/7/8/11 に新たな後退が無く、dev DB 保護 / T099 / CI secret 不在が
  維持されているという評価に同意する。
- W14a の allowlist 4 action は「action 内部までは保証しない」旨を
  Codex の指摘どおり「既知の信頼済み setup action」という境界として設計に明記した。
