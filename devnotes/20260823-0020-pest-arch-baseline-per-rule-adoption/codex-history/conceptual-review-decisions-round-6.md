# 対応マトリクス: conceptual-review Round 6

## Round 6 の判定

- 全体判定: **APPROVED** ✅
- **[Critical] 0 件 / [Warning] 0 件** / [Suggestion] 9 件 (うち文言修正 2 件)
- Codex の総括: 「概念設計として必要な契約は確定しています。Round 5 の 2 件は十分に解消され、
  `dynamicMemberSites()` の絞り込みも共通規約 (b) に反しません。
  具体的な token 処理と判別 union の array shape は詳細設計へ送って問題ありません」

Round 5 の [Warning] 3 (callable 4 関数の完全修飾名・別名取り込み) と
[Warning] 7 (未解決を判別できる型で返す) は、いずれも「解消されています」と明言された。

自己訂正 1 (`(` を伴わない `::$var` を動的メンバから外す) についても
「保護対象操作の動的な名前解決を見逃す方向への縮小ではなく、
異なる概念である静的プロパティ参照を母集団から除いたもの」として妥当と判定された。

## 収束の推移

| Round | 判定 | Critical | Warning |
|---|---|---|---|
| 1 | CHANGES_REQUESTED | 1 (サーフェス検査の欠落) | 5 |
| 2 | CHANGES_REQUESTED | 1 (`ignoring` 引数の出所) | 4 |
| 3 | CHANGES_REQUESTED | 1 (可変メンバ構文) | 3 |
| 4 | CHANGES_REQUESTED | 1 (callable / 反射経由) | 4 |
| 5 | CHANGES_REQUESTED | **0** | 2 |
| 6 | **APPROVED** | **0** | **0** |

---

## [Suggestion] 形式一覧が (i)〜(v) の 5 形なのに「次の 4 形」と書いている

- 判断: **対応する** (文言の誤り)
- 対応内容: 概念設計「塞ぐ (1)」の枠内を「次の 5 形を動的とする」へ訂正した。

## [Suggestion] 制約節に旧表現「値オブジェクトの `list<>`」が残っている

- 判断: **対応する** (Round 5 までの改訂で値オブジェクトを採用しない形に落ち着いたのに、
  制約節の文言だけが取り残されていた)
- 対応内容: 「`list<string>` / **型付き array shape の `list<>`**。
  値オブジェクトのファイルは新設しない」へ統一した。

## [Suggestion] 名前空間内の素の `call_user_func()` は安全側へ倒す

- 判断: **対応する** (ただし**詳細設計で扱う**)
- 根拠: Codex の助言どおり。名前空間の中で `call_user_func(...)` と書いた場合、
  PHP の関数解決は「現在の名前空間 → グローバル」の順で fallback するため、
  同名の名前空間関数が実在するかどうかで実際の呼び先が変わる。
  だが本 gate は**その語彙が 0 件であること**を固定するものなので、
  拾いすぎても 0 件 gate としては安全側 (fail-closed) に倒れる。
- 対応内容: 詳細設計の走査器仕様へ
  「名前空間内の非修飾 `call_user_func(...)` は、同名の名前空間関数の実在を調べずに
  候補として数える (拾いすぎる方向 = 安全)」を明記する。

## [Suggestion] 未解決結果の判別可能な array shape の具体案

- 判断: **対応する** (詳細設計で確定させる)
- 対応内容: 詳細設計で
  `array{status: 'resolved', name: string, line: int}` /
  `array{status: 'unresolved', reason: string, line: int}` の判別 union として確定させる。

## その他の [Suggestion] (使命整合 / 禁止事項 / 保証範囲 / スコープ / 型安全性)

いずれも**現行の設計を肯定する内容**であり、変更を要する指摘ではない。
「D40 や件数 pin を実装着手時に再読する方針も適切」との評価を得ている。

---

## 次フェーズへの申し送り (詳細設計 Phase 2 で確定させること)

1. 3 走査器の**公開戻り値の array shape** (特に `resolvedFunctionCallSites()` の判別 union)
2. `use function` / group use / 別名取り込みの**解決アルゴリズム**と、未解決と判定する条件の列挙
3. 名前空間内の非修飾呼び出しの扱い (安全側へ倒す)
4. `dynamicMemberSites()` の `::` 分岐 (`(` の有無) の実装と、その両方向の負例・正例
5. S4 のチェーン完全一致照合の**期待トークン列**の正本の置き場
6. `docs/template-divergence.md` **D40** の登録メタ表 9 行の実文面
