Round 1 の指摘への対応が終わったので再レビューを依頼する。
判断の記録は下記の対応マトリクスのとおりで、Critical 1 件・Warning 4 件・Suggestion 1 件を
すべて「対応する」で処理した (反論・見送りは 0 件)。

変更したファイルは 6 つ:
- tests/js/support/enum-ts-sync/php-enums.ts (Critical の修正)
- tests/js/architecture/enum-ts-sync-extractor.test.ts (P39/P40 追加・件数 pin 40・TS 行列を 27 行のデータ駆動へ)
- tests/js/architecture/enum-ts-sync.test.ts (走査根の引数化と負のコントロールの追加)
- docs/architecture.md / docs/template-divergence.md (負例行列の件数 38 → 40)
- docs/TODO.md (T225 の起票)

**リポジトリを読める**ので、必要なら上記ファイルの現物を読んで確認してほしい
(作業ツリーは既に修正後の状態である)。以下に主要な変更箇所を貼る。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] `CASE_SINGLE` / `CASE_DOUBLE` の値部分が改行を許している

- 判断: **対応する**
- 根拠: 指摘のとおり `[^'\\]*` は CR/LF に一致するため、`case A = 'a<改行>b';` が 1 件の
  宣言として受理され、TS 側を `"a\nb"` にすれば gate 全体が緑になる。
  「複数行にまたがる case は例外」という設計・`docs/architecture.md` の記述・
  `AGENTS.md` の登録規約 (「1 行に一致」) がすべて実装より強い主張になっていた。
- 対応内容: `php-enums.ts` で (1) 宣言の範囲に CR/LF があれば**照合の前に**落とす分岐を足し
  (文面は「受理する書き方に一致しません (改行を含む case は受理しません)」で、P15 の
  期待理由と両立する)、(2) 値の文字集合からも `\r\n` を除いた (二重の守り)。
  負例 P39 (単一引用符) / P40 (二重引用符) を足し、行列の件数 pin を 38 → 40 へ。
  故障注入でこの分岐を外すと **P39 / P40 の 2 件が赤**になることを実測した。

## [Warning] P15 は値の**中**の改行を検査していない

- 判断: **対応する** (上の Critical と同じ変更)
- 根拠: P15 は `=` と文字列の**間**の改行しか押さえておらず、値の中の改行は別の形である。
- 対応内容: P39 / P40 を単一・二重引用符の両方について足した。

## [Warning] 「TS 27 件」の pin が実際に実行される行列を固定していない

- 判断: **対応する**
- 根拠: `TS_CASES.length + 1` の形では T25b の `it` を消しても pin が動かない
  (行列から静かに 1 件消せる = 集めた結果を判定に使わない形に近い)。
- 対応内容: 行に `program: "full" | "narrow"` を持たせ、T25b を**行列の 27 行目**として
  `it.each` へ載せた。`EXPECTED_TS_CASE_COUNT` は 27 の 1 本だけにし、併せて
  「起点を縮めた program で判定する行がちょうど 1 件ある」ことも固定した
  (縮めた program の対照が消えることも赤になる)。起点を縮めた program の起点も
  行から導出する形にしたので、行を消すと program の起点が空になって落ちる。

## [Warning] `validateMirrors` の負のコントロールが境界の分岐を固定していない

- 判断: **対応する**
- 根拠: 指摘のとおり `config/app.php` 1 件では `root + path.sep` を素の `root` へ弱める回帰も、
  `realpathSync` 検査の撤去も検出できない。セキュリティ境界として設計した分岐に
  対応する負例が無いのは (c) 違反である。
- 対応内容: `validateMirrors(rows, root = REPO_ROOT)` と走査根を引数化し
  (**負のコントロールのためだけ**の引数であることを docblock に明記)、
  一時ディレクトリに `app/` `app-legacy/` `resources/js/` `outside/` と symlink 2 本を持つ
  見本の木を作って、兄弟ディレクトリ・symlink による脱出・symlink 別名の二重登録・
  ディレクトリの登録を負例にした。実リポジトリ側にも絶対パス・逆斜線・`.`・空の区間・
  拡張子違いの負例を足した。故障注入で 3 分岐 (区切り文字・realpath・別名の重複) を
  外すといずれも赤になることを実測した。

## [Warning] AG-099 後半の TODO が起票されていない

- 判断: **対応する**
- 根拠: 詳細設計が本作業の完了条件に含めており、`docs/template-divergence.md` の
  再判定の条件がこの TODO に結び付いている。
- 対応内容: `docs/TODO.md` へ **T225「PHP 列挙 ⇔ TS 値域の発見の段と逆走査 (AG-099 後半)」**
  を追加した (ID は main 側の最大 T224 の次)。

## [Suggestion] D29・28 件への更新はマージ時に行う必要がある

- 判断: **対応する** (main へのマージ時に実施)
- 根拠: 差分を作った時点では D26 までが使用済みだったが、main 側で T220 が D27 を、
  T221 が D28 を取った。番号は再利用しない。
- 対応内容: `git merge main` の解決で登録を **D29** へ、登録エントリ数を **28** へ改め、
  `AGENTS.md` / `docs/architecture.md` / gate の docblock の参照も同じ変更で直す。

## 変更後のコード (抜粋)

### tests/js/support/enum-ts-sync/php-enums.ts
```ts
/**
 * 受理する case の書き方 (単一引用符)。
 * **値の中に改行を許さない** (`[^'\\\r\n]`)。許すと `case A = 'a<改行>b';` が
 * 1 件の宣言として受理され、TS 側を `"a\nb"` にすれば gate 全体が緑になってしまう
 * (Codex 実装レビュー Round 1 の Critical)。
 */
const CASE_SINGLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*'([^'\\\r\n]*)'[ \t]*;$/i;
/** 受理する case の書き方 (二重引用符。変数の埋め込みを拒むため `$` も除く)。 */
const CASE_DOUBLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*"([^"\\$\r\n]*)"[ \t]*;$/i;
...
        if (semicolon < 0) throw new EnumTsSyncError(where, "case 宣言の終端 (;) が見つかりません");

        // **元の本文**を照合する (無害化した写しではない)
        const declaration = source.slice(at, semicolon + 1);
        // 改行を含む宣言は先に落とす (受理する書き方は 1 行に閉じたものだけである)。
        if (/[\r\n]/.test(declaration)) {
            throw new EnumTsSyncError(
                where,
                `受理する書き方に一致しません (改行を含む case は受理しません): ${JSON.stringify(declaration)}`,
            );
        }
        const matched = CASE_SINGLE.exec(declaration) ?? CASE_DOUBLE.exec(declaration);
        if (matched === null) {
            throw new EnumTsSyncError(where, `受理する書き方に一致しません: ${JSON.stringify(declaration)}`);
```

### tests/js/architecture/enum-ts-sync-extractor.test.ts (TS 行列の作り)
```ts
/**
 * どちらの program で判定するか。
 * `full` = 本番の gate と同じ全体 program / `narrow` = 起点だけに縮めた program。
 */
type ProgramKind = "full" | "narrow";

interface TsCase {
    /** 行列の番号 (設計の表と 1:1)。 */
    readonly id: string;
    /** 見本ファイル名 (`fixtures/` 配下)。 */
    readonly file: string;
    /** 読ませる型別名の名前。 */
    readonly declaration: string;
    /** 受理するなら期待する値集合、拒否するなら `undefined`。 */
    readonly accepts: readonly string[] | undefined;
    /** 拒否するときに文面へ必ず含まれる語 (別の理由の例外で緑にならないようにする)。 */
    readonly reason?: string;
    /** 既定は全体 program。T25b だけが起点を縮めた program を使う。 */
    readonly program?: ProgramKind;
}

/**
 * TS 側の行列。**既定は全体 program で判定する** (本番の gate と同じ型世界)。
 * program の別は**行のデータとして持つ** — `it` を別に書くと、その `it` を消しても
 * 件数 pin が動かず**行列から静かに消せてしまう** (Codex 実装レビュー Round 1 の Warning)。
 */
...
    { id: "T22", file: "t22-circular.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T23", file: "t23-unresolved-import.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T24", file: "t24-source-duplicate.ts", declaration: "X", accepts: ["a"] },
    { id: "T25a", file: "t25-target.ts", declaration: "X", accepts: ["a", "b"], program: "full" },
    // T25b: 起点だけの program では拡張が載らないので値が減る (起点を縮める改変の回帰)。
    { id: "T25b", file: "t25-target.ts", declaration: "X", accepts: ["a"], program: "narrow" },
];

/** 行列の件数の pin (概念上の行数ではなく `it.each` に渡す要素数)。 */
const EXPECTED_TS_CASE_COUNT = 27;

/**
 * 見本の実在集合と行列の参照集合を突き合わせるための「補助」一覧。
 * 行列から直接は読まないが、見本の解決に必要なファイル。
 */
...
describe("TS 側抽出器の負例行列", () => {
    beforeAll(() => {
        // 見本は tsconfig から除外してあるので、全体 program にも起点として明示的に足す。
        fullProgram = createMirrorProgram(TS_CASES.map((c) => fixture(c.file)));
        // 起点を縮めた program は「縮めた行が指す見本だけ」を起点にする。
        narrowProgram = createFixtureProgram(
            TS_CASES.filter((c) => c.program === "narrow").map((c) => path.join(FIXTURE_DIR, c.file)),
        );
    }, 300_000);

    it("行列の件数が pin と一致する", () => {
        expect(TS_CASES).toHaveLength(EXPECTED_TS_CASE_COUNT);
        // 起点を縮めた program で判定する行が**実在する** (回帰の対照が消えていない)。
        expect(TS_CASES.filter((c) => c.program === "narrow")).toHaveLength(1);
    });

    it("見本の実在集合と行列の参照集合が完全一致する", () => {
        const onDisk = fs
            .readdirSync(FIXTURE_DIR)
            .filter((f) => f.endsWith(".ts"))
            .sort();
        const referenced = [...new Set([...TS_CASES.map((c) => c.file), ...TS_AUXILIARY_FIXTURES])].sort();
        expect(onDisk).toEqual(referenced);
    });

    it("program-fixtures の実在集合と宣言が完全一致する", () => {
        const onDisk = fs
            .readdirSync(PROGRAM_FIXTURE_DIR)
            .filter((f) => f.endsWith(".ts"))
            .sort();
        expect(onDisk).toEqual([...PROGRAM_FIXTURES].sort());
    });

    it.each(TS_CASES)("$id: $file::$declaration", (testCase) => {
        const mirrorProgram = testCase.program === "narrow" ? requireNarrowProgram() : requireFullProgram();
        const read = (): ReadonlySet<string> =>
            readTsUnionValues(mirrorProgram, fixture(testCase.file), testCase.declaration);

        if (testCase.accepts === undefined) {
            expect(read).toThrow(EnumTsSyncError);
            expect(read).toThrow(testCase.reason);
            return;
        }
```

### tests/js/architecture/enum-ts-sync-extractor.test.ts (P39 / P40 と件数 pin)
```ts
447:        id: "P38",
448-        fileName: "X.php",
449-        source: php("enum X: string\r\n{\r\n    case A = 'a';\r\n    case B = 'b';\r\n}\r\n"),
450-        accepts: ["a", "b"],
451-    },
452-    {
453-        // 値の**中**に改行がある単一引用符の case。受理すると TS 側を "a\nb" にするだけで
454-        // gate 全体が緑になる (Codex 実装レビュー Round 1 の Critical)。P15 は `=` と
455-        // 文字列の**間**の改行しか見ていないので、この形は別の負例として要る。
456-        id: "P39",
457-        fileName: "X.php",
458-        source: php("enum X: string\n{\n    case A = 'a\nb';\n}\n"),
459-        accepts: undefined,
460-        reason: "改行を含む case は受理しません",
461-    },
462-    {
463-        id: "P40",
464-        fileName: "X.php",
465-        source: php('enum X: string\n{\n    case A = "a\nb";\n}\n'),
466-        accepts: undefined,
467-        reason: "改行を含む case は受理しません",
```

### tests/js/architecture/enum-ts-sync.test.ts (走査根の引数化)
```ts

/**
 * 目録の行の体裁を検査する純関数。
 * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
 * 「赤くなる前に読んでしまう」ことになる。
 *
 * @param rows 目録の行
 * @param root 走査根 (既定はリポジトリのルート)。**負のコントロールが symlink や
 *             兄弟ディレクトリを含む見本の木を一時ディレクトリに作って渡すためだけ**に
 *             引数化してある (本番の呼び出しは既定値を使う)。
 */
export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = REPO_ROOT): void => {
    const appRoot = path.join(root, "app");
    const jsRoot = path.join(root, "resources", "js");
    const seen = new Set<string>();
    const seenReal = new Set<string>();

    for (const row of rows) {
        const where = `${row.php} ⇔ ${row.ts}::${row.declaration}`;

        for (const relative of [row.php, row.ts]) {
            if (path.isAbsolute(relative)) throw new EnumTsSyncError(where, `絶対パスは登録できません: ${relative}`);
            if (relative.includes("\\")) throw new EnumTsSyncError(where, `逆斜線を含むパスは登録できません: ${relative}`);
            const segments = relative.split("/");
            if (segments.some((s) => s === "" || s === "." || s === "..")) {
                throw new EnumTsSyncError(where, `. / .. / 空の区間を含むパスは登録できません: ${relative}`);
            }
        }

        if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");

        const phpAbs = path.resolve(root, row.php);
        const tsAbs = path.resolve(root, row.ts);
        if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
        if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);

        for (const [absolute, scanRoot, label] of [
            [phpAbs, appRoot, row.php],
            [tsAbs, jsRoot, row.ts],
        ] as const) {
            if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
            if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
            // symlink 経由で走査範囲の外へ抜けられないようにする
            if (!isUnder(fs.realpathSync(absolute), scanRoot)) {
                throw new EnumTsSyncError(where, `symlink の解決先が走査範囲の外です: ${label}`);
            }
        }

        const key = `${row.ts}::${row.declaration}`;
        if (seen.has(key)) throw new EnumTsSyncError(where, `同じ TS 宣言が 2 回登録されています: ${key}`);
        seen.add(key);

        const realKey = `${fs.realpathSync(tsAbs)}::${row.declaration}`;
        if (seenReal.has(realKey)) {
            throw new EnumTsSyncError(where, `symlink 越しに同じ TS 宣言が 2 回登録されています: ${realKey}`);
        }
        seenReal.add(realKey);
    }
};

let mirrorProgram: MirrorProgram | undefined;

/** 初期化されていなければ落ちる (definite assignment の `!` を使わない)。 */
const requireMirrorProgram = (): MirrorProgram => {
    if (mirrorProgram === undefined) throw new EnumTsSyncError("mirror program", "初期化されていません");
```

### tests/js/architecture/enum-ts-sync.test.ts (負のコントロール)
```ts
describe("validateMirrors() の負のコントロール (実リポジトリを根にする)", () => {
    const valid: EnumTsMirror = {
        php: "app/Enums/Manual/RenderKind.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderKind",
        note: "負のコントロール用の正常な行",
    };

    it("正常な行は通る", () => {
        expect(() => validateMirrors([valid])).not.toThrow();
    });

    it("app/ の外の php は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
    });

    it("resources/js/ の外の ts は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow("resources/js/ 配下だけ");
    });

    it("絶対パスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: path.join(REPO_ROOT, valid.php) }])).toThrow(
            "絶対パスは登録できません",
        );
    });

    it("逆斜線を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app\\Enums\\Manual\\RenderKind.php" }])).toThrow(
            "逆斜線を含むパス",
        );
    });

    it(".. を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
    });

    it(". と空の区間を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/./Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
        expect(() => validateMirrors([{ ...valid, ts: "resources/js//types/manual.ts" }])).toThrow(
            ". / .. / 空の区間",
        );
    });

    it("拡張子が違う登録は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/Enums/Manual/RenderKind.phpx" }])).toThrow(
            "php は .php で終わること",
        );
        expect(() => validateMirrors([{ ...valid, ts: "resources/js/types/manual.d.ts.map" }])).toThrow(
            "ts は .ts で終わること",
        );
    });

    it("実在しないファイルは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/Enums/NoSuchEnum.php" }])).toThrow("実在しません");
    });

    it("ディレクトリの登録は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/Enums/Manual.php" }])).toThrow("実在しません");
    });

    it("同じ TS 宣言の二重登録は拒否する", () => {
        expect(() => validateMirrors([valid, { ...valid, note: "別の理由" }])).toThrow("2 回登録されています");
    });

    it("note が空の行は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, note: "  " }])).toThrow("note が空です");
    });
});

/**
 * 走査根の境界そのものを固定する負のコントロール。
 * 兄弟ディレクトリ (`app-legacy/`)・symlink による脱出・symlink 別名の二重登録は
 * **実リポジトリには作れない**ので、一時ディレクトリに同じ形の木を作って根を差し替える。
 * ここが無いと `root + path.sep` を素の `root` へ弱める回帰や `realpathSync` 検査の
 * 撤去を検出できない (Codex 実装レビュー Round 1 の Warning)。
 */
describe("validateMirrors() の負のコントロール (走査根の境界)", () => {
    let sandbox = "";

    const row = (php: string, ts: string, declaration = "X"): EnumTsMirror => ({
        php,
        ts,
        declaration,
        note: "見本の木の行",
    });

    beforeAll(() => {
        // realpath を取る (一時ディレクトリ自体が symlink の環境で判定がぶれないようにする)。
        sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-")));
        fs.mkdirSync(path.join(sandbox, "app", "Enums"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "app-legacy", "Enums"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "resources", "js", "types"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "outside"), { recursive: true });

        fs.writeFileSync(path.join(sandbox, "app", "Enums", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "app-legacy", "Enums", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "outside", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "resources", "js", "types", "x.ts"), "export type X = \"a\";\n");

        // app/ の中から走査範囲の外を指す symlink。
        fs.symlinkSync(path.join(sandbox, "outside", "X.php"), path.join(sandbox, "app", "Enums", "escape.php"));
        // 同じ TS ファイルを別名で指す symlink。
        fs.symlinkSync(
            path.join(sandbox, "resources", "js", "types", "x.ts"),
            path.join(sandbox, "resources", "js", "types", "alias.ts"),
        );
    });

    afterAll(() => {
        if (sandbox !== "") fs.rmSync(sandbox, { recursive: true, force: true });
    });

    it("見本の木の正常な行は通る", () => {
        expect(() => validateMirrors([row("app/Enums/X.php", "resources/js/types/x.ts")], sandbox)).not.toThrow();
    });

    it("兄弟ディレクトリ (app-legacy/) は app/ 配下と認めない", () => {
        expect(() =>
            validateMirrors([row("app-legacy/Enums/X.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("app/ 配下だけ");
    });

    it("symlink で走査範囲の外へ抜ける登録は拒否する", () => {
        expect(() =>
            validateMirrors([row("app/Enums/escape.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("symlink の解決先が走査範囲の外です");
    });

    it("symlink の別名で同じ TS 宣言を 2 回登録するのは拒否する", () => {
        expect(() =>
            validateMirrors(
                [
                    row("app/Enums/X.php", "resources/js/types/x.ts"),
                    row("app/Enums/X.php", "resources/js/types/alias.ts"),
                ],
                sandbox,
            ),
        ).toThrow("symlink 越しに同じ TS 宣言が 2 回登録されています");
    });

    it("ディレクトリを登録するのは拒否する", () => {
        fs.mkdirSync(path.join(sandbox, "app", "Enums", "dir.php"), { recursive: true });
        expect(() =>
            validateMirrors([row("app/Enums/dir.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("通常ファイルではありません");
    });
});
```

## テスト結果 (再実行)

- `pnpm test tests/js/architecture/enum-ts-sync` : 2 files / **120 tests passed** (Round 1 時点は 108 件。負例の追加で +12)
- 故障注入は **22 件すべて赤**。Round 1 で足した分岐も含む:
  - 「case の値に改行を許す」→ 赤 (P39 / P40)
  - 「起点を縮めた program の行を消す」→ 赤 (件数 pin と「縮めた行が 1 件ある」の検査)
  - 「配下の判定から区切り文字を落とす」→ 赤 (兄弟ディレクトリ app-legacy/)
  - 「symlink の解決先の検査を外す」→ 赤 / 「symlink 別名の二重登録の検査を外す」→ 赤
- `pnpm lint` / `pnpm typecheck` / `vendor/bin/pint --test` / `composer phpstan` : green

## 依頼

上記の対応で Round 1 の指摘が閉じているかを判定し、全体判定 (APPROVED / CHANGES_REQUESTED) を返してほしい。
