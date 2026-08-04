# 対応マトリクス: impl-review Round 3

## [Critical] SKILL.md: 複数 probe 応答を統合するときの `errors` が未定義 (SKILL.md:286)

- 判断: **対応する** (指摘は正しい。私の Round 2 の修正に穴が残っていた)
- 根拠: `errors` は **drain した batch 単位の件数**なので、1 回目 `errors:1, pending:1` →
  再 probe `errors:0, pending:0` という並びが実際に起こりうる。
  Round 2 時点の規約は統合対象を `seen` / `present_new` の和集合としか書いておらず、
  **2 回目の `errors:0` を見て H7 陰性に倒す解釈が成立してしまう**。
  これは Round 2 [Critical] (判定不能を陰性証拠にしない) が再 probe 経路で破れることを意味し、
  「1 箇所塞いだつもりが別の入口が空いていた」という同じ失敗の繰り返しである。
- 対応内容: 判定表の `pending > 0` 行を「1 回目と 2 回目の**応答を統合**して判定する (統合規則は下記)」に改め、
  本文に **複数応答の統合規則**を独立項として明記した:
  - `seen` / `present_new` は**和集合** (1 回目の `present_new` は基線更新で 2 回目には
    `present_preexisting` に落ちるため、2 回目だけでは証拠を失う)。
  - `installed_now` / `errors` は **「いずれかの応答で真 / 非 0 なら操作全体でそう」** と扱う。
  - **陰性 (H7 起票) を主張してよいのは、統合後に `installed_now` が全て false、
    `errors` の合計が 0、最終応答の `pending` が 0 のときだけ。**
  これで「肯定証拠は和集合で拾い、陰性主張は全応答が揃って安全なときだけ許す」という
  非対称 (安全側に倒す) が文面から一意に読める。

## [Suggestion] `errors` が次回 drain で 0 に戻るテストを追加 (feedback-probe.test.ts:15)

- 判断: **対応する**
- 根拠: `errors` が batch 単位であることは上記統合規則の**前提**であり、
  前提がテストで固定されていないと規約だけが宙に浮く。
- 対応内容: ケース N に assertion **N4** を追加した (`expect(probe().errors).toBe(0)`)。
  コメントで「だから SKILL.md の統合規則は errors を『いずれかで非 0 なら未検証』と定めている」と
  規約への紐づけを残し、テストと規約が同じ事実を指していることを明示した。
  jsdom のケース ID は 21 → **22** (N4 を追加。詳細設計の表は 18。逸脱として記録する)。

## APPROVED 済み (Round 3 で変更なし)

- `.claude/skills/app-bug-hunt/probes/feedback-probe.js` — `errors` の batch 一致・`pending` の対称性・
  同期評価側の例外が probe 自体を失敗させる (= 陰性 JSON に偽装されない) 点を Codex が確認。
- `tests/js/bughunt/feedback-probe.test.ts` のケース N 本体 — false green の穴なしと評価。
- `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` — 繰り返し化に過剰マッチなしと評価。
- `.claude/skills/app-bug-hunt/spec-ledger.md` / `.claude/agents/bughunt-shard.md` — Round 2 で APPROVED。

## ラウンド上限の扱い

`app-implement` SKILL.md の合議終了条件は「APPROVED になるまで。最大 3 ラウンド」。
Round 3 の残指摘は **SKILL.md 本文 2 箇所 + テスト 1 assertion** の局所修正で、
実装ロジック (probe 本体) には触れていない。この修正が意図どおりかの確認だけを目的に
**Round 4 (確認のみ)** を 1 回行う。上限を 1 超過している事実はここに記録する。
