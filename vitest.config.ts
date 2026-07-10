import { defineConfig } from "vitest/config";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { svelteTesting } from "@testing-library/svelte/vite";
import path from "path";

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
        setupFiles: ["./tests/js/setup.ts"],
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
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
