import { defineConfig } from "vitest/config";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { svelteTesting } from "@testing-library/svelte/vite";
import path from "path";
import { testProject } from "./scripts/test-inventory-config";

export default defineConfig({
    plugins: [
        svelte({
            hot: !process.env.VITEST,
            compilerOptions: {},
        }),
        svelteTesting(),
    ],
    test: {
        globals: true,
        environment: "jsdom",
        // CPU を食い尽くさないよう並列ワーカーをコア数の半分に抑える
        // (環境非依存: 10コア→5, 8コア→4 のように自動追従)
        maxWorkers: "50%",
        minWorkers: 1,
        setupFiles: ["./tests/js/setup.ts"],
        // include の正本は scripts/test-inventory-config.ts (2 project 分を 1 箇所で持つ)。
        // scripts/vitest-inventory-gate.test.ts が FS 走査と突き合わせて漏れを検出する。
        include: [...testProject("root").include],
        coverage: {
            provider: "v8",
            reporter: ["text", "json", "html"],
            exclude: [
                "node_modules/",
                "tests/",
                "**/*.d.ts",
                "**/*.config.*",
                "**/mockData",
            ],
        },
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "./resources/js"),
        },
    },
});
