## Round 2: Round 1 指摘への対応

以下が Round 1 の [Warning] 2 件 / [Suggestion] 1 件に対する対応です。
対応マトリクスと、変更後の該当コードを示します。

## 対応マトリクス

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


---

## 変更後のコード

### 1. `resources/js/components/features/capture/ShootingGuideOverlay.svelte` (markup 部)

```svelte
<!--
      line-clamp-* は display: -webkit-box を敷くため **flex と同じ要素には置けない**
      (どちらか一方しか効かず、長い撮影ガイドが帯からはみ出して字幕帯と交差しうる)。
      レイアウトは外側の <p> が flex で持ち、行数制限はテキストの <span> 側に置く。
    -->
    <p
        class="flex max-w-full items-start gap-1 rounded-sm bg-text/70 px-3 py-1 text-caption text-surface"
    >
        <Lightbulb class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span class="line-clamp-2 min-w-0">{text}</span>
    </p>
</div>

```

### 2. `tests/js/components/features/capture/ShootingGuideOverlay.test.ts` (追加したテスト)

```ts
    /*
     * line-clamp-* は display: -webkit-box を敷くため flex と同居できない。
     * 同じ要素に両方付けると生成 CSS の順序次第でどちらかが効かなくなり、
     * 長い撮影ガイドが帯からはみ出して字幕帯と交差しうる (jsdom では見た目を測れないので
     * 「どの要素に付いているか」を構造として固定する)。
     */
    it("行数制限はテキスト要素側にあり、flex を敷いた要素には無い", () => {
        render(ShootingGuideOverlay, { props: { text: "手元を寄りで撮る" } });

        const overlay = screen.getByTestId("shooting-guide-overlay");
        const panel = overlay.querySelector("p");
        const textEl = overlay.querySelector("span");

        expect(panel?.className).toContain("flex");
        expect(panel?.className).not.toContain("line-clamp");
        expect(textEl?.className).toContain("line-clamp-2");
        expect(textEl?.className).not.toContain("flex");
    });


```

### 3. `tests/js/pages/CaptureShow.test.ts` (ちらつき検出テスト。全文)

```ts
    it("inline レイアウト固有の見出しが一度も DOM に現れない (ちらつきの直接検出)", async () => {
        stubCameraSupported(false);
        const seen: string[] = [];
        let addedElements = 0;
        // callback と takeRecords() の**両方が同じ処理を通る**ようにする。
        // 保留分を捨てると (forEach(() => undefined) 等)、追加が保留側に残ったケースを
        // 検査せずに通してしまう = 空振りになる。
        const collect = (records: MutationRecord[]): void => {
            for (const record of records) {
                for (const node of record.addedNodes) {
                    if (!(node instanceof Element)) continue;
                    addedElements += 1;
                    if (
                        node.matches('[data-testid="capture-recording-heading"]') ||
                        node.querySelector('[data-testid="capture-recording-heading"]') !== null
                    ) {
                        seen.push("capture-recording-heading");
                    }
                }
            }
        };
        const observer = new MutationObserver(collect);
        observer.observe(document.body, { childList: true, subtree: true });

        render(CaptureShow, { props: landscapeProps() });
        await tick();

        // callback は microtask 通知なので、保留分を回収 → microtask を 1 回進める →
        // もう一度回収、の順で取りこぼしを無くしてから切る
        collect(observer.takeRecords());
        await Promise.resolve();
        collect(observer.takeRecords());
        observer.disconnect();

        // 空振り防止: 観測そのものが動いていること (0 件なら「何も見ていないから緑」になる)
        expect(addedElements).toBeGreaterThan(0);
        expect(seen).toEqual([]);
    });


```

### 4. `tests/Browser/CaptureLandscapeFullscreenTest.php` (media query の docblock と fixture)

```php
/**
 * LANDSCAPE_CAPTURE_MEDIA_QUERY と同一文字列。
 *
 * **式そのものの正本は `resources/js/lib/capture/landscape-capture.ts` で、
 * 3 条件が揃っていることを機械固定するのは `tests/js/lib/capture/landscape-capture.test.ts`
 * (完全一致の assertion) である**。ここの複製は「このハーネスの context で条件が
 * 成立しているか」という**前提の観測**にしか使わず、式の正しさは主張しない
 * (PHP から TS の定数を読む経路が無いため複製は避けられないが、
 * 役割を分けて二重の正本を作らない)。
 */
function landscapeCaptureMediaQuery(): string
{
    return '(orientation: landscape) and (max-height: 540px) and (pointer: coarse)';
}


```

```php
    foreach (range(1, 3) as $index) {
        Cut::factory()->forManual($manual)->create([
            'sort_order' => $index,
            // ★ 撮影ガイドは**わざと長くする**。1 行に収まる短文だと帯の高さが最小になり、
            //   「交差しない」がほぼ自明に成立してしまう (レーンの分離を実質検査しない)。
            //   **ただしこれは行数制限 (line-clamp) の実装位置を検査するものではない** —
            //   そちらは ShootingGuideOverlay.test.ts が構造として固定する
            //   (実測: line-clamp を flex と同じ要素へ戻しても本テストは緑のままだった)。
            'shooting_point' => "工程 {$index} は手元を寄りで撮る。".str_repeat(
                'カメラを被写体の正面に据えて手の動きが切れないように構図を取り、',
                6
            ),
            'subtitle_primary' => "工程 {$index}",
            'subtitle_secondary' => "工程 {$index} の説明字幕",
        ]);
    }


```

---

## 再検証結果

- `pnpm test` (該当 2 ファイル) … 45 tests passed
- `vendor/bin/pint --test` … passed
- `pnpm lint` … 0 problems
- `pnpm typecheck` … 0 errors
- `pnpm build` … OK
- `composer test:browser -- --filter=CaptureLandscapeFullscreen` … chromium 7 passed / webkit 6 passed + 1 skipped
- **負のコントロールの実測**: `line-clamp-2` を flex と同じ要素へ戻して Chromium レーンを
  走らせたところ Browser の矩形テストは**緑のままだった**。したがって
  「Browser テストが行数制限の退行を捕まえる」という主張はせず、
  component テスト側を機械固定とし、Browser 側にはその旨をコメントで明記した。

---

上記を踏まえて再レビューしてください。最後に全体判定を `APPROVED` または
`CHANGES_REQUESTED` の 1 語で明示してください。
