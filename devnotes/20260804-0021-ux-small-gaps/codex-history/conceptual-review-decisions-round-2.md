# 対応マトリクス: conceptual-review Round 2

**Codex の事実検証について**: Round 2 でも「シェル以外のファイル読取手段が無く、
コマンド実行禁止のため実ファイルを開けなかった」と回答。本環境では Codex による
一次ソース検証は成立しないため、**事実確認は Claude 側の実ファイル確認 (file:line 付き) に依拠する**。
この事実を概念設計にも明記する (黙って省略しない)。

## [Critical] 3-1 / 5-1: B-2 の「ログアウトハンドラで clear」ではセッション終了境界を網羅できない

- 判断: **対応する (指摘どおり。設計を変更)**
- 根拠: 決定的に正しい。**アカウント削除 (`settings.account.destroy`) は
  `AppLayout` のログアウトハンドラを通らない**。他にもセッション失効・middleware による
  ログイン画面送還など authenticated → guest の遷移は複数ある。
  「操作 (ログアウト) を境界にする」設計は列挙漏れを構造的に生む。
- 対応内容:
  - 境界を**着地側**に置き換える: **未認証 layout (`GuestLayout` / `AuthLayout`) は
    初期化時に既存 toast を破棄してから、その visit の flash を消費する**
    (clear → consume → 表示の順序を契約化)。着地側に置けば経路の列挙が不要になる。
  - この境界保護を **施策 A に含める (無条件)**。B-2 の条件付き実施には依存させない
    (Codex 6-1「A と guest 境界保護は不可分」に合致)。
  - 実装は「component 初期化時に 1 度だけ `clearToasts()`」+ 「`$effect` で `consumeFlash`」。
    `$effect` の再評価で client 側 toast を巻き込まないよう、clear は init 1 回に限定する。
  - B-2 (`onDestroy(clearToasts)` 撤去) は、この境界が明示化された後なら安全。
    逆に言えば、現行の「毎 unmount で clear」に PII 非持ち越しを暗黙依存させている状態こそ
    解消対象である。

## [Critical] 4-1: B-1 は H-a / H-b の唯一の判別器ではない / pass = artifact 確定ではない

- 判断: **対応する (結論の射程を狭める)**
- 根拠: 妥当。pass が言えるのは「自動テスト条件では未再現」まで。
- 対応内容: B-1 の判定表を書き直す。
  - fail → H-a (ライフサイクル依存) を支持 → B-2 を適用して green 化。
  - pass → 「自動テスト条件では未再現」。**H-b の確定ではない**。
    bug-hunt 観測が artifact だったかの確定には「遷移完了 → snapshot までの実測時間」が要るが、
    それは bug-hunt 側の計測課題であり本 TODO のスコープ外 (open question として残す)。
    この場合 B-2 は実施せず、B-1 のテストを回帰の番人として残す。

## [Warning] 2-1: B-1 の成功条件を具体化せよ (4 秒未満で表示 / 4 秒後に消える / 両レーン)

- 判断: **一部対応**
- 対応内容:
  - 「リダイレクト着地後に success toast が可視」「Chromium / WebKit 両レーン」は採用。
  - 「4 秒経過後に消えることを Browser テストで確認」は**採用しない**。
    auto-dismiss は `tests/js/lib/toast.test.ts:42-53` が fake timer で既に固定済みで、
    Browser レーンに 4 秒の実時間待機を 2 レーン分足すのは費用対効果が悪い (重複検証)。
  - 「flash → toast 変換時点の固定」は既存 `tests/js/lib/flash-to-toast.test.ts` が担当済み。

## [Warning] 5-2: `onDestroy` 撤去後のタイマー安全性 (timer Map にエントリが残らないか)

- 判断: **事実確認して反論 (テストは追加しない)**
- 根拠: `resources/js/lib/stores/toast.ts:41-64` を確認した。auto-dismiss の
  `setTimeout(() => dismissToast(id), ttl)` は `dismissToast` を呼び、
  `dismissToast` は `timers.get → clearTimeout → timers.delete(id)` を必ず行う。
  したがって auto-dismiss 後に管理エントリは残らない (手動 dismiss と同一経路)。
- 対応内容: `timers` は module 内 private であり、残存を直接 assert するには
  内部状態を export する必要がある = テストのために設計を緩める行為になるため追加しない。
  観測可能な振る舞い (自動消去・他 toast への非干渉) は
  `tests/js/lib/toast.test.ts:42-73` が既に固定している。この根拠を設計書に明記する。

## [Warning] 6-1: A 適用後 B-1 判定前に GuestLayout が既存 toast を描画するリスク / 実装順序

- 判断: **対応する**
- 対応内容: 実装順序を明記 (B-1 → C → A(+境界保護) → B-2 判断)。
  さらに上記 Critical 対応により A と境界保護を不可分の 1 施策としたため、
  「A だけ入って境界が無い」中間状態は生じない。

## [Warning] 7-1: `fetchJson<T>` の generic は型 assertion であり実行時保証が無い

- 判断: **対応する**
- 対応内容: secret / QR とも**使用前に文字列であることを絞り込む**
  (`typeof x === "string" && x !== ""` を満たさなければ取得失敗と同じ独立エラー経路へ)。
  型を緩めず、`unknown` からの narrowing で扱う。詳細設計に具体コードを書く。

## [Suggestion] 1-1 / 1-2 / 4-2 / 7-2

- 判断: **見送る (現状維持でよいという趣旨のため対応不要)**
