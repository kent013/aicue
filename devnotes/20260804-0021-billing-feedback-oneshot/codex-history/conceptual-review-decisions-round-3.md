# 対応マトリクス: conceptual-review Round 3 (APPROVED 後の反映)

Round 3 は **APPROVED**。Critical / Warning なし。Suggestion 1 件と、詳細設計着手時に
自分で発見した規約の内部矛盾 1 件を反映した。

## [Suggestion] 「着地 render は必ず 1 回発生する」の表現が強すぎる
- 判断: **対応する**
- 根拠: 通信中断・同一 session の並行リクエストまで保証する表現になっていた。
- 対応内容: 詳細設計では「通常の 303 追従フローでは直後の GET が flash を読む」と
  範囲を明示した表現に狭める (detailed-design.md 「唯一の経路」節)。

## [自己発見] flash 4 キー一律 keep が T1004 着地の明示判断と衝突する
- 判断: **概念設計を修正する (narrowing)**
- 根拠: `resolveAutoRechargeLanding()` には
  「`reflash()` はしない: 成功着地で直前の error flash まで延命すると
  『成功と失敗が同時に出る』着地を作るため」という明示のコメント = 設計判断がある。
  Round 1 で入れた「共有 4 キーを一律 keep」はこれと真っ向から衝突し、
  T1004 の成功着地で error トーストが同時に出うる。
  また `/billing?session_id=` / `?portal=1` へ**内部 redirect + flash** で到達する経路は
  コードベースに存在しない (`redirect()->route('billing.index', ...)` の全 3 箇所を確認) ため、
  `error` 以外の flash が hop に居合わせる現実的経路がない。
- 対応内容: 透過対象を **`error` のみ**に絞り、透過するのは
  「error があるので feedback を出さない」分岐だけとした
  (= 既存の成功偽装抑止ルールと同じ場所・同じ条件)。
  canonical redirect ヘルパは **flash に触れない純粋な URL 構築**にして、
  既存 2 着地 (setup / T1004) の flash 挙動を一切変えない。
  概念設計の該当規約を書き換え済み。
