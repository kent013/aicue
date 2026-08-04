# 対応マトリクス: impl-review Round 2

## [Critical] probe: 例外時の `visible:false` が新たな偽陽性を作る (feedback-probe.js:96)

- 判断: **対応する** (指摘は正しい。Round 1 の私の修正が不完全だった)
- 根拠: Round 1 で `catch` を足したことで `pending` の恒久残留は解消したが、
  結果として **「可視判定が例外で解決できなかった」応答が `visible:false` / `pending:0` /
  `present_new:[]` になり、判定表 2 行目の『本当に出なかった』と完全に一致**してしまう。
  つまり判定不能を**陰性証拠**として扱う経路を作っており、
  本設計が潰そうとしている誤検知 (F-1-02 と同型) を別の入口から作り直していた。
  Round 1 の Warning は「pending の対称性」だったが、観測契約としては未完という指摘は妥当。
- 対応内容 (probe / プロトコル / テストの 3 点セット):
  1. **probe**: 応答に `errors` を追加した。`seen` を drain した batch のうち
     `error` を持つ entry 数を数えて返す
     (`const drained = state.seen.splice(0); ... errors: drained.filter((e) => e.error !== undefined).length`)。
     `visible:false` に倒す点は変えない (証拠集合は `visible:true` のみという既存契約を壊さないため) が、
     **判定不能であることが応答から機械的に読める**ようにした。
  2. **SKILL.md**: 判定表に 1 行追加 —
     `errors > 0` は **陰性判断に使えない**。肯定証拠 (`visible:true` + 結果文言) があれば
     「フィードバックあり」でよいが、無ければ **未検証** (H7 finding にしない)。
     併せて 2 行目の条件を `pending:0` **かつ** `errors:0` に締め、
     H7 適用条件の本文も `installed_now:false` かつ `pending:0` かつ `errors:0` に更新した。
     finding 証跡欄の書式にも `errors=0` を足した。
  3. **テスト**: 下記 [Warning] の回帰テスト N を追加。
- 専用 `failed` カウンタではなく `errors` (件数) にしたのは、判定が件数ではなく
  「0 か否か」だけを見るためで、entry 側の `error` 文字列は triage 用の診断として残している。

## [Critical] SKILL.md: `seen[].error` の扱いが判定表に無い (SKILL.md:284)

- 判断: **対応する** (上記 Critical と同一原因。同じ修正で閉じる)
- 対応内容: 判定表の新規行と H7 適用条件の更新で、`errors > 0` が**陰性判断の出口を塞ぐ**ことを明文化した。
  「`visible:false` は『不可視だった』ではなく『判定不能』である」も同行に明記して、
  driver が `visible:false` を陰性証拠と読み違えないようにした。

## [Warning] 例外経路の回帰テストが無い (feedback-probe.test.ts:113)

- 判断: **対応する**
- 根拠: 例外経路は「壊れたときだけ通る」経路なので、テストが無いと
  次の変更で静かに壊れ、しかも**壊れ方が誤検知**という最悪の失敗モードになる。
- 対応内容: ケース **N** を追加した (K の直前。K は記録器を消すので最後に置く必要がある)。
  `Element.prototype.getClientRects` の stub に `rectsShouldThrow` フラグを足し、
  rAF が走る区間だけ throw させる (probe 本体の同期評価は正常系に戻してから叩く)。
  検証は 3 点: `pending === 0` (対称性) / entry に `error` があり `visible === false` /
  `errors === 1` (陰性判断に使えないことが機械的に読める)。
  これで jsdom のケース ID は 18 → **21** になった (N1/N2/N3 を追加。詳細設計の表は 18 のまま = 逸脱として記録する)。

## [Suggestion] `ProbeEntry` に `error?: string` を追加 (feedback-probe.test.ts:21)

- 判断: **対応する**
- 対応内容: `ProbeEntry.error?: string` と `ProbeResult.errors: number` を型に追加した。
  テスト側の型が probe の返却契約と一致し、`errors` の取り違えを `tsc --noEmit` が検出する。

## [Suggestion] test_spec_ledger.py: `file.ts:12:5` が依然として検査対象外 (test_spec_ledger.py:61)

- 判断: **対応する**
- 根拠: 位置記法を 1 セグメントしか許容していないと、複数セグメント記法で書かれた根拠が
  丸ごとすり抜ける。守りたい不変条件は「パスの実在」なので、**許容集合は広い方が強い**。
- 対応内容: サフィックスを `(?:[:#][\w.-]*)?` → `(?:[:#][\w.-]*)*` に変更 (0 回以上の繰り返し)。
  `a/b.ts:12:5` / `AGENTS.md#anchor` / `x.js#L10` / `x.php:230-232` がパス部だけ抽出され、
  `role="status"` / `A-001` / `toast-success` のような非パストークンは従来どおり素通りすることを実測確認した。

## [Suggestion] dedupe 見送り / 短待機見送りの妥当性確認

- 判断: **対応不要** (Codex が両方とも妥当と評価。Round 1 の判断を維持)

## APPROVED 済みファイル

- `.claude/skills/app-bug-hunt/spec-ledger.md` / `.claude/agents/bughunt-shard.md`: 変更なし。
