# 対応マトリクス: design-review Round 4

Codex 全体判定: **APPROVED** (Critical 0 / Warning 0 / Suggestion 1)。
全 12 施策 (1/2/3/4A/4B/5/6/7/8/9/10/11) が APPROVE。

## [Suggestion] 施策 4A のリスク欄と本文に旧記述が残っている

- 判断: **対応する**
- 根拠: 設計書の内部矛盾は実装者がどちらを信じるかで結果が変わる。
  Round 2/3 で本体を直したときに、2 箇所の説明文が取り残されていた。
- 対応内容: 2 箇所を修正した。
  1. 本文「pip-audit と その前段の `uv export` も**同じ `acquire`** を通す」
     → 「取得ラッパを通す。ただし**契約別に別 wrapper を使う**
     (共通本体 `_run_acquire` + `acquire_audit` / `acquire_required`)。
     audit ツールの非ゼロは『検出した』でありうるが `uv export` の非ゼロは常に失敗だから」
  2. リスク欄「検証は **top-level コンテナのみ**に絞り、過剰結合を避ける」
     → 「検証は **normalizer が走査に使う最小構造まで**に絞り
     (未知フィールドは許容 / 空コンテナ・空 `vulns` は正当な 0 件として通す)、過剰結合と偽赤を避ける」

## Codex の確認結果 (同意)

- 施策 4A: `acquire_audit` / `acquire_required` の分離が適切で、A7b と A9 が
  「非ゼロを失敗と誤判定する」「非ゼロを正常と誤判定する」の**両方向**を検出できている
- shape 検証が未知フィールドを許容しつつ normalizer 必要構造だけを固定しており、
  空コンテナ・空 `vulns` を許可しているため過剰な偽赤にならない
- `loadAuditJson(path, source)` の内部 dispatch により normalizer 誤配線が表現不能
- 施策 9 の S1〜S4 が整合し、初期赤と exemption の形骸化を両方防げる
- 施策 10 の W14a/b/c が施策 2 の workflow と一致し、8 つの許可コマンド行に過不足が無い。
  local script / composite action / inline 環境変数 / `echo` 偽装 / 起動 step 削除を
  それぞれ拒否できる
- dev DB 保護・T099・CI secret 不在・PHPStan level 10 方針に後退なし
- DTO/JsonResource・Inertia Props・DESIGN.md・Atomic Design は該当なし

## 最終確認 (app-design Phase 2-5)

| 確認項目 | 結果 |
|---|---|
| 全施策が使命 (AGENTS.md North Star) に寄与するか | Yes。撮影 PWA (iOS Safari) の履歴復元 PII 漏れ・シナリオ整合の共有ロック規約・CLI の emit 経路という、使命の中核を支える不変条件が CI で実際に走るようになる |
| 禁止事項に違反していないか | 違反なし。特に **禁止事項 2 (baseline 化 / 型の widen)** は本バッチの中心的な設計判断として明示的に拒否している (audit soft-fail・`noUnusedLocals: false`・shape 検証の緩和のいずれも採らない)。**禁止事項 1 (テストなしの実装完了)** は全 12 施策に負のコントロール付きテストを割り当てて満たす。禁止事項 3 (dev DB 破壊) は `DB_DATABASE` を CI env に置かず bootstrap 単一点ガードへ寄せることで回避 |
| コーディングルールが設計に反映されているか | PHPStan level 10 (新規 PHP テストの `Assert::string()` / `instanceof DOMElement` narrow)、Pest、`DatabaseTransactions` 不使用、Factory 不要 (モデル追加なし)、DTO 規約は該当なし (HTTP 応答を追加しない) をすべて明記済み |
| T099 / T082 の既存契約を壊していないか | 壊していない。T099 は CI バイパスを作らず (1 job = 1 runner で無競合)、ロック機構 5 ファイルを一切変更しない。T082 は CI でレーンを絞らず、W9/W14 で骨抜き経路を機械的に塞ぐ |
