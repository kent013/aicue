# 対応マトリクス: conceptual-review Round 5 (最終)

Codex 判定: **APPROVED** ([Critical] 0 / [Warning] 0 / [Suggestion] 7)。
Suggestion はすべて「問題ありません / 適切です」という肯定的評価であり、対応不要。

## 合議の要約 (Round 1 → 5)

| Round | 判定 | 主な指摘 | こちらの動き |
|---|---|---|---|
| 1 | CHANGES_REQUESTED (W8) | 分類表の具体不足 / 効果の過大主張 / 誤参照 / PHPStan API 未定 | 7 件対応 + AGENTS.md 追記は反論 (受理された) |
| 2 | CHANGES_REQUESTED (C1/W5) | `UnknownApiErrorException → unknown` と運用契約の矛盾 / `QueryException` の分類 | vendor 実装を読み直し、**5xx が全部そこへ来る**ことを発見 → status 2 分岐へ。`unknown` を写像の不在専用に |
| 3 | CHANGES_REQUESTED (C2/W3) | `map()` では条件付き規則を表現できない / fixture 契約の自己矛盾 | API を `directMap()` + `conditionalClasses()` に分割 / parity を業務 4 case に限定 |
| 4 | CHANGES_REQUESTED (W2) | 非 vendor 集合を一般化しないと運用契約と衝突 / 旧「有界」表現の残存 | `nonVendorExplicitClasses` へ一般化 + exact fit cap / 表現修正 |
| 5 | **APPROVED** | — | — |

## 合議で設計が実際に変わった点 (レビューが効いた箇所)

1. **`UnknownApiErrorException` の扱い** — 当初は `unknown` へ写像する予定だった。
   Codex の Critical を受けて vendor の `_specificV1APIError()` を読み直した結果、
   これは HTTP status `switch` の `default:` 分岐であり **Stripe の 5xx がすべてここに来る**
   ことが分かった。決済 gateway で最も重要な失敗モード (待てば直る) を
   分類できない設計だったのを、status による 2 分岐へ改めた。
2. **`unknown` の意味の一意化** — 「写像表に載っているのに unknown」という状態を
   gate で禁止し、`unknown` = 写像の不在 に 1:1 対応させた。
3. **API の分割** — 条件付き規則を単一写像に押し込むとダミー値で「正本」が嘘をつくため、
   `directMap()` / `conditionalClasses()` に分けて集合契約を gate で固定する形にした。
4. **parity の対象範囲** — `unknown` を fake/real 一致契約から外した
   (実ライブラリ例外を未分類のまま fixture に使うと vendor 全件分類の gate と衝突する)。
5. **`local_infrastructure` → `local_failure`** — `QueryException` が接続障害以外も包むため、
   「インフラ障害」と名乗る誤誘導をやめた。
