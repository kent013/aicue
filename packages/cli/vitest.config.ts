import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: ["tests/**/*.test.ts"],
        environment: "node",
        // 資格情報バックエンドをホスト非依存に固定する (setup の解説参照)。
        setupFiles: ["tests/setup/credential-backend.ts"],
        testTimeout: 15000,
    },
});
