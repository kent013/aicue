## Round 1 の指摘への対応

# 対応マトリクス: impl-review Round 1

## [Warning] W2 で `result.status` を明示確認していない (scripts/claude-wrapper.test.ts)

- 判断: 対応する
- 根拠: 設計の W2 は「終了コードが 0 になるのは**テスト用の偽バイナリが 0 で終わるから**」と
  わざわざ但し書きしている。その但し書きが指す対象を検査していないのは設計との不一致である。
- 対応内容: `expect(result.status).toBe(0);` を追加し、
  「実機で別 platform のバイナリが動くという意味ではない」旨のコメントを同じ位置に置いた。

## [Suggestion] fallback の `ls -d` はディレクトリ以外にも一致する (scripts/claude)

- 判断: 見送る
- 根拠: 3 点。
  (1) 採用されるパスは後段の `[ -d ]` を通ったものだけなので、**ディレクトリでないものが
      起動対象になることはない** (指摘もそう認めている)。残るのは「より新しい名前の
      **ファイル**が混ざると、実在する古いディレクトリを見落として代替経路 / エラーへ落ちる」
      という縮退だけである。
  (2) この性質は**追従元 (laravel-claude-template) の探索と同じ形**から来ている。
      本タスクは追従元との乖離を減らす掃除であり、ここだけ `find -type d` 等へ変えると
      **新しい乖離を 1 つ作る**ことになる (設計の「やらないこと」に同じ判断が書いてある)。
  (3) 拡張の置き場に拡張名を模したファイルが現れる経路が現実に無い。
      今必要でないものを作らない (思考原則 2)。
- 対応内容: コードは変えない。判断の根拠を本マトリクスに残す。

## [Suggestion] W7 に改行入りの引数が無い (scripts/claude-wrapper.test.ts)

- 判断: 対応する
- 根拠: W7 の目的は「壊れやすい引数がそのまま転送されること」で、改行は
  `eval "set -- $new_args"` によるクォート再構築で最も壊れやすい入力の 1 つである。
  記録は NUL 区切りなので改行を含む引数もそのまま読み戻せる (テスト側の作り替えが不要)。
- 対応内容: W7 の入力へ `"1 行目\n2 行目"` を追加した (9 ケースのまま green)。


## 修正後の該当箇所 (scripts/claude-wrapper.test.ts の抜粋)

```ts
            platform: foreignPlatform(),
        });

        const result = runWrapper(scratch);

        // 拾い直した拡張のバイナリまで到達している (現行の即 exit 1 との違いはここ)。
        // status が 0 なのは偽バイナリが 0 で終わるからであって、
        // 実機で別 platform のバイナリが動くという意味ではない (冒頭の但し書き参照)。
        expect(result.status).toBe(0);
        expect(recordedInvocation(scratch).binary).toBe(fallback);
        expect(result.stderr).toContain(expectedPlatform());
        expect(result.stderr).toContain(fallback.replace("/resources/native-binary/claude", ""));
    });

    it("W3: 拡張が 1 つも無ければ platform 名つきのエラーで終了する", () => {
        const scratch = createScratch();

        const result = runWrapper(scratch);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain(expectedPlatform());
    });

    it("W4: ネイティブバイナリが実行可能でなければそのパスを示して終了する", () => {
        const scratch = createScratch();
        const binary = installExtension(scratch, {

...

269:        const args = ["", "a b", "it's", '{"a":1}', "--", "日本語 の 引数", "1 行目\n2 行目"];
```

## 再実行結果

- `pnpm exec vitest run scripts/claude-wrapper.test.ts`: 9 passed (0 failed)

(`scripts/claude` / `.gitignore` / `tests/Architecture/SkillsLockIgnoreCoverageTest.php` / `scripts/README.md` は Round 1 から変更していない。)

上記の対応で全体判定を再度お願いする。見送った Suggestion (fallback の `ls -d`) について、判断の根拠に穴があれば指摘してほしい。
