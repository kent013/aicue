# 対応マトリクス: impl-review Round 8

Round 8 の判定は **APPROVED** (「main へマージしてよい実装です」)。
指摘は 0 件なので対応項目は無い。記録として合議の終わり方だけ残す。

## 合議の経過 (Round 1 → 8)

| Round | 判定 | 残った [Critical] |
|---|---|---|
| 1〜2 | CHANGES_REQUESTED | 取り込んだ自己検査 S9 / S10 の子がリポジトリの `.env` を読む ほか |
| 3 | CHANGES_REQUESTED | 同上 (「正典側を先に直せ」という裁定つき) |
| 4 | CHANGES_REQUESTED | 未正規化パスの配下判定 / 番兵の作り方と資格情報の digest / `idempotency-claim-probe.php` の裏取り不足 |
| 5 | CHANGES_REQUESTED | G-8 の主張と裏取りの不整合 / G-9 走査器の偽グリーン |
| 6 | CHANGES_REQUESTED | S11・P-10d の並列作成競合 / 最終形での 2 回連続 green 未取得 |
| 7 | CHANGES_REQUESTED | 共有祖先の**削除**による競合 (作成側の競合は解消済み / 2 回連続 green は解消済み) |
| **8** | **APPROVED** | **なし** |

> **ラウンド数について**: `app-implement` スキルの既定は最大 3 ラウンドだが、
> Round 3 の [Critical] が「正典 (テンプレート) 側を先に直せ」という
> **本セッションからは実行できない裁定**だったため、同じ不変条件を aicue 側で満たす形へ
> 切り替えて合議を続けた。以後のラウンドはいずれも**実在の欠陥**を 1 件ずつ潰しており
> (資格情報の露出 → 偽グリーン → 主張の誇張 → 実在の並列競合)、
> 打ち切ると赤いまま main へ入るものばかりだった。先例として T253 も Round 5 まで延長している。

## 見送った指摘 (Round 8 でも蒸し返されていない = マージ阻害ではない)

1. `BootProbeRunner` の docblock の「環境配列が唯一の統制点」という記述
   — 取り込み元 (laravel-claude-template) を直すべき事柄で、当該ファイルは
   取得時の sha256 と一致したまま保つ方が価値が高い。訂正は
   (a) 起動器が名指ししている自己検査の先頭 (呼び出し側の必須契約) と
   (b) `FakeWiringProbeRunner` の訂正表の 2 か所から辿れる。**上流への申し送り**とする。
2. `BootProbeResult` の PHPDoc (`timedOut === true && exitCode === 0` が可能なのに
   「強制終了なら 124」と断定している) — 同上。呼び出し側は `timedOut` を見ており誤記に依存しない。
3. `tests/Feature/Auth/EmailPromotionTest.php` の順序依存 flake — **main 側 (T253) の既存欠陥**。
   main の作業ツリーでも `vendor/bin/pest tests/Feature/Admin tests/Feature/Auth/EmailPromotionTest.php`
   で同一 2 件が再現する。**別 TODO 候補**として申し送る。
