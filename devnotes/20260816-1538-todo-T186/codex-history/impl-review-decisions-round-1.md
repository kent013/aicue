# 対応マトリクス: impl-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** ([Warning] 2 / [Suggestion] 1)

## [Warning] `tests/js/pages/CaptureShow.test.ts` — 保留中の MutationRecord を捨てている

- 判断: **対応する**
- 根拠: 指摘のとおり。`observer.takeRecords().forEach(() => undefined)` は
  「保留分を回収した」ように見えて**中身を検査せず捨てている**。
  `capture-recording-heading` の追加が保留側に残ったケースを素通しする =
  最悪の空振り (常に緑) になる。詳細設計が明示した「保留分を回収して子孫まで見る」
  契約からも外れている。
- 対応内容: MutationRecord の処理を `collect(records)` helper に切り出し、
  **MutationObserver の callback と `takeRecords()` の両方が同じ helper を通る**形にした。
  回収順は「`collect(takeRecords())` → microtask を 1 回進める → もう一度
  `collect(takeRecords())` → `disconnect()`」で取りこぼしを閉じた。
  空振り防止の `addedElements` も helper 内で数えるようにした。

## [Warning] `ShootingGuideOverlay.svelte` — `line-clamp-2` と `flex` の同居

- 判断: **対応する**
- 根拠: `line-clamp-*` は `display: -webkit-box` を敷くため `display: flex` と競合し、
  生成 CSS の順序次第でどちらか一方しか効かない。指摘のとおり潜在的な退行源であり、
  「意図した 2 つの表示指定が同じ要素で殴り合っている」状態を残す理由が無い。
- 対応内容: レイアウト (flex) は外側の `<p>`、行数制限はテキストの `<span>` へ分離した
  (`<span class="line-clamp-2 min-w-0">`)。
  さらに**構造として機械固定**するため `ShootingGuideOverlay.test.ts` に
  「flex を敷いた要素に line-clamp が無く、テキスト要素側にある」テストを追加した。
- 補足 (**指摘の一部に反論**): Codex は「現在の Browser テストは fixture 文言が短いため
  この退行を捕まえない」と書いたが、**文言を長くしても Browser の矩形テストは捕まえない**
  ことを実測で確認した (line-clamp を flex と同じ要素へ戻して Chromium レーンを走らせても
  緑のまま)。したがって Browser 側を「捕まえる検査」として扱うのは誤りであり、
  機械固定は上記の component テストが担う。
  Browser 側の fixture は別の理由 (1 行で収まる短文だと帯の高さが最小になり
  「交差しない」がほぼ自明に成立する) で長文へ変更し、
  **「行数制限の検査ではない」ことをコメントに明記**した (主張と保証を一致させる)。

## [Suggestion] `CaptureLandscapeFullscreenTest.php` — media query 文字列の複製

- 判断: **一部対応する** (複製の解消はしない)
- 根拠: PHP から TS の定数を読む経路が無く、複製そのものは避けられない。
  一方で「どちらが正本か」が曖昧なまま複製が 2 つあると drift の責任が消える。
- 対応内容: 複製の役割を docblock で明示した —
  **式の正しさ (3 条件が揃っていること) の機械固定は
  `tests/js/lib/capture/landscape-capture.test.ts` の完全一致 assertion が担い**、
  PHP 側の複製は「このハーネスの context で条件が成立しているか」という
  **前提の観測にしか使わず、式の正しさは主張しない**と書いた。
