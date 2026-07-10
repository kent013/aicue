import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import { svelte } from "@sveltejs/vite-plugin-svelte";

// Tailwind CSS v4 は PostCSS 経路 (postcss.config.js の @tailwindcss/postcss) で処理する。
export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.ts"],
            refresh: [
                "resources/views/**/*.blade.php",
                "routes/**/*.php",
                "app/Http/Controllers/**/*.php",
            ],
        }),
        svelte(),
    ],
    server: {
        // container / VM 越しのアクセスも受けられるよう全 interface で listen する
        host: "0.0.0.0",
        port: 5173,
        hmr: {
            host: "localhost",
        },
        watch: {
            // Docker Desktop (macOS) の bind mount 等、inotify がホストから届かない
            // 環境ではポーリングで変更検知する (必要な環境でのみコメントを外す):
            // usePolling: true,
            // interval: 300,
            ignored: [
                "**/node_modules/**",
                "**/vendor/**",
                "**/storage/**",
                "**/bootstrap/cache/**",
            ],
        },
    },
    optimizeDeps: {
        include: ["svelte", "@inertiajs/svelte"],
    },
    resolve: {
        alias: {
            "@": "/resources/js",
        },
    },
});
