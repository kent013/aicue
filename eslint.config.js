import betterTailwind from "eslint-plugin-better-tailwindcss";
import svelte from "eslint-plugin-svelte";
import svelteParser from "svelte-eslint-parser";
import tsParser from "@typescript-eslint/parser";
import globals from "globals";

// Tailwind v4 は CSS-first config。entryPoint に @import "tailwindcss" を宣言した
// app.css を指す。callees は clsx/cva 系を導入したときに lint 対象にするための宣言。
const betterTailwindSettings = {
    "better-tailwindcss": {
        entryPoint: "resources/css/app.css",
        callees: ["classnames", "clsx", "ctl", "cn", "cva", "tw"],
    },
};

/*
 * .svelte に載せる実行時グローバル。
 *
 * **ここに載せてよいのは「実行時に存在するグローバル」だけ**。
 * 型専用名 (WebIDL dictionary = MediaTrackConstraints / RequestInit 等) を足すことは
 * 禁止する。足すと lint は緑になるが、同名を実行時の値として誤用したときにも
 * no-undef が黙る = gate を入れる変更で gate に穴を開けることになる
 * (PHPStan エラーを widen して黙らせるのと同じ悪手。AGENTS.md 禁止事項 2)。
 *
 * .svelte の型注釈に型専用名が必要になったら .ts 側へ逃がす:
 *   1. ロジックごと .ts に移す (第一選択。.ts は tsc の検査対象になるので純増)
 *      — 実例: lib/capture/camera.ts の videoConstraints()
 *   2. 移せない (component props の型等) なら .ts で
 *      `export type X = MediaTrackConstraints;` と別名 export し、
 *      .svelte からは `import type` で参照する (module 参照は no-undef の対象外)
 *
 * アプリ固有の実行時グローバル (window に生やす等) が将来必要になったら、
 * 下の APP_RUNTIME_GLOBALS に理由コメント付きで登録する。
 * svelte-no-undef-gate が「globals.browser + APP_RUNTIME_GLOBALS と完全一致」を
 * deny-by-default で検査するので、無登録の差分は CI で落ちる。
 */
const APP_RUNTIME_GLOBALS = {
    // 現時点で登録なし。追加時は「なぜ実行時グローバルなのか」を必ず添えること。
};

const svelteGlobals = { ...globals.browser, ...APP_RUNTIME_GLOBALS };

export default [
    /*
     * lint 対象 (`pnpm lint` = `eslint resources/js`) の全ファイルで、
     * inline の eslint-disable / eslint-enable を一切許可しない。
     * ルールを黙らせたいときの唯一の手段は **本ファイルの file-scoped override**。
     * override を認めるのは次の 3 条件をすべて満たすときだけ:
     *   (a) 抑制対象が具体的な 1 ファイル (または明示列挙されたファイル群) に閉じている
     *   (b) なぜ安全かがコード側の日本語コメントで説明されている
     *   (c) ここに理由と再検討条件 (いつ外せるか) を書く
     * config に集約すれば diff に必ず現れ、レビュー可能かつ数えられる。
     *
     * **lint 対象を広げるとき** (`pnpm lint` の引数を増やす等) は、
     * tests/js/architecture/svelte-no-undef-gate.test.ts の走査範囲も同時に広げること
     * (宣言と検査の範囲が乖離すると gate が守っているつもりの穴ができる)。
     */
    { linterOptions: { noInlineConfig: true } },
    {
        ignores: [
            "tmp/**",
            "node_modules/**",
            "dist/**",
            "build/**",
            ".git/**",
            "vendor/**",
            "public/build/**",
            "storage/**",
        ],
    },
    {
        files: ["**/*.svelte"],
        languageOptions: {
            parser: svelteParser,
            parserOptions: {
                parser: tsParser,
            },
        },
    },
    {
        files: ["**/*.{js,mjs,cjs,ts,jsx,tsx}"],
        languageOptions: {
            parser: tsParser,
        },
    },
    {
        files: ["**/*.{js,mjs,cjs,ts,jsx,tsx,svelte}"],
        plugins: {
            "better-tailwindcss": betterTailwind,
        },
        settings: betterTailwindSettings,
        rules: {
            "better-tailwindcss/no-conflicting-classes": "error",
            "better-tailwindcss/no-duplicate-classes": "error",
            "better-tailwindcss/no-unnecessary-whitespace": "error",
            "better-tailwindcss/enforce-consistent-class-order": "error",
            "better-tailwindcss/no-unknown-classes": "warn",
        },
    },
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            /*
             * 生の HTML を DOM へ差し込む構文 ({@html}) の全面禁止。
             *
             * 値の出どころが 1 か所でも汚れていれば script がそのまま実行される。
             * 撮影 PWA は同一オリジン・セッション認証なので、XSS の成立は
             * 撮影導線の資格情報にそのまま届く。
             *
             * **許可一覧 (allowlist / exemption inventory) の口は持たない**。
             * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
             * **別のセキュリティ設計**としてレビューを通すこと
             * (file-scoped override をここに書き足して済ませない)。
             *
             * サーバ生成の SVG (2 要素認証の QR) を描く用途には
             * components/atoms/QrCodeImage.svelte を使う (data URI の <img>)。
             *
             * 実効性の裏取りは tests/js/architecture/svelte-raw-html-gate.test.ts
             * (実際に lint を走らせ、無効化コメントが効かないことまで固定する)。
             */
            "svelte/no-at-html-tags": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
];
