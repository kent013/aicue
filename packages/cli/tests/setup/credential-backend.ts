// vitest グローバルセットアップ: 資格情報バックエンドをホスト非依存に固定する。
//
// `KeychainStore.isAvailable()` は OS keyring を probe する。devcontainer では
// 応答し keychain が選ばれる一方、headless CI では @napi-rs/keyring の fallback
// が read-probe に応答するのに実 write で QuotaExceeded になる等、同じテストが
// ホストごとに別バックエンドを叩き非 hermetic になる。
//
// `<PREFIX>_DISABLE_KEYCHAIN=1` を既定投入し、未注入の `new KeychainStore()` の
// `isAvailable()` を常に false にして file-store を必ず選ばせる。keychain 固有
// テストは in-memory の Fake を注入し、自スコープでこのフラグを解除する。
//
// 暗号化 file-store は master key を必要とするため、`<PREFIX>_CREDENTIAL_KEY`
// はここでは投入しない (unwired な暗号化経路へ誘導して exit 32 になるのを防ぐ)。

import { ENV } from "../../src/branding.js";

// 外部で明示された値は尊重し、未設定時のみ既定投入する。
if (process.env[ENV.DISABLE_KEYCHAIN] === undefined) {
    process.env[ENV.DISABLE_KEYCHAIN] = "1";
}

// CI 検出テストのため ambient な CI を除去してホスト非依存にする。
delete process.env["CI"];
