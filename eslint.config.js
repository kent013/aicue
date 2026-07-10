import betterTailwind from "eslint-plugin-better-tailwindcss";
import svelte from "eslint-plugin-svelte";
import svelteParser from "svelte-eslint-parser";
import tsParser from "@typescript-eslint/parser";

// Tailwind v4 は CSS-first config。entryPoint に @import "tailwindcss" を宣言した
// app.css を指す。callees は clsx/cva 系を導入したときに lint 対象にするための宣言。
const betterTailwindSettings = {
    "better-tailwindcss": {
        entryPoint: "resources/css/app.css",
        callees: ["classnames", "clsx", "ctl", "cn", "cva", "tw"],
    },
};

export default [
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
        rules: {
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
];
