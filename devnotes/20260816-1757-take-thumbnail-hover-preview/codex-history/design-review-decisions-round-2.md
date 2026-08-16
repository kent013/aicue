# 対応マトリクス: design-review Round 2

Codex 全体判定: **APPROVED** (施策 1 / 2 / 3 / 4 すべて APPROVE)

Round 1 の [Warning] 5 件はすべて解消と確認された。`onMount` の対で解除する代案、
`prefersReducedMotion` 移設の見送りも、いずれも妥当と判定された。
残った 2 件は [Suggestion] (テスト実装時の表現改善) で、差し戻し理由ではないと明記されている。
どちらも安いので**設計へ取り込んだ**。

## [Suggestion] listener 対称性のテストは内部関数を覗かず外部観測可能な契約で固定する

- 判断: **対応する**
- 根拠: `stopPreview` は component 内部の関数で、テストからの参照は実装詳細への侵入になる。
  同じことを外から観測できる形 (関数参照の同一性 / 例外と DOM 変化の不在) で書けるなら、
  そちらのほうがリファクタに強い。
- 対応内容: テスト計画を書き換え、`vi.spyOn(document, "addEventListener" / "removeEventListener")` で
  **同じ関数参照が渡されたこと**を突き合わせる形と、
  **unmount 後の `visibilitychange` で例外が出ず DOM も変化しないこと**の 2 点にした。

## [Suggestion] 「DOM に文字列が無い」は属性への直接問い合わせで書く

- 判断: **対応する**
- 根拠: `textContent` では属性値を検査できず、`innerHTML` の文字列一致は意図が読み取りにくい。
- 対応内容: `container.querySelector('img[src*="/thumbnail"]')` /
  `container.querySelector('video[src*="/playback"]')` が `null` であることを検査する形へ書き換えた。
  テスト名も「404 になる URL を 1 つも張らない」に改めた。

---

## 最終確認 (app-design スキル Phase 2-5)

### 全施策が使命 (AGENTS.md) に寄与するか

- 寄与する。「編集ゼロ」に対して、編集者がシナリオ (台本) と採用テイクの撮れ高を**同じ画面で**
  確認できるようにし、カットごとのページ遷移の往復を消す。
- 効果の記述は概念設計 Round 4 で誇張を削っており、「未採用テイクの比較まで完結する」とは
  主張していない。

### 禁止事項に違反していないか

| # | 禁止事項 | 本設計 |
|---|---|---|
| 1 | テストなしの実装完了報告 | 施策 4 に Feature 4 ケース + component 16 ケース + 組み込み 6 ケースを計画済み |
| 2 | PHPStan の widen / baseline | 型は狭める方向のみ (`array` shape にキー追加)。ignore も baseline も使わない |
| 3 | dev DB への破壊操作 | 該当なし (migration を 1 本も足さない) |
| 4 | `response()->json()` の直書き | 該当なし (Inertia props のみ。新規 endpoint 0 本) |
| 5 | LLM の Prism 直呼び | 該当なし |
| 6 | prompt 文字列のコード直書き | 該当なし |
| 7 | `redirect()->intended()` | 該当なし |
| 8 | 必須条件未充足で disabled にする UI | **守っている**。サムネイルが出ない場合でも「テイクを選択 / ファイルの選択」ボタンは常に押せる。消えるのは補助的なサムネイル表示だけ (テストで固定) |
| 9 | Artifact の使用 | 使っていない。成果物は `devnotes/` 配下のファイルのみ |

### コーディングルールが設計に反映されているか

- PHPStan level 10: `toArray()` の `@return` array shape と `takeSummaries()` の `@return` docblock を
  同じ変更で更新する (施策 1)
- テスト必須: 施策 4 に全施策分の計画あり。テストファースト (実装順序 2) も明記
- Factory 使用: `TakeFactory::withThumbnail()` を使う。`Model::create()` の手組みはしない
- `DatabaseTransactions` の個別使用なし (テスト計画に確認項目あり)
- DS token のみ / Lucide のみ / atomic 階層の単方向 import: 施策 2 の PHPStan 適合チェック欄で確認済み
