import { defineConfig } from "vitest/config";
import { testProject } from "../../scripts/test-inventory-config";

export default defineConfig({
    test: {
        // include の正本は repo root の scripts/test-inventory-config.ts。
        // 本パッケージが monorepo root を参照するのはこの devtool 設定のみで、
        // package.json#files は dist/bin/README.md に限定されているため公開成果物には入らない。
        include: [...testProject("packages/cli").include],
        environment: "node",
        // 資格情報バックエンドをホスト非依存に固定する (setup の解説参照)。
        setupFiles: ["tests/setup/credential-backend.ts"],
        testTimeout: 15000,
    },
});
