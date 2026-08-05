# impl-review Round 1 対応マトリクス

Codex 判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 4 / [Suggestion] 1)

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|------|------|------|---------|
| 1 | Warning | `oclif/commands/profile/delete.ts`: `assertProfileName` が `resolveContext` の後ろにある。設計書の実装順序 (1. 名前検証 → 2. 計画) とずれており、`resolveContext` が失敗しうる状態で不正名が exit 13 にならない | **対応する** | `const name = args.name; assertProfileName(name);` を `resolveContext` の**前**へ移動。理由を JSDoc/コメントに明記 |
| 2 | Warning | `profile/delete.ts`: `plansMatch` が `unlocatable` の詳細を比較しない。確認待ち中に `api_url` 未設定 → `ftp://x` と変わっても一致扱いになり「何も触らず exit 10」に反する | **対応する** | `unlocatable` 同士は `reason` まで比較する分岐を追加 (`reason` は `api_url` から決定的に導かれるので書き替えを検出できる)。回帰テスト 5c-f を追加し、比較を外すと赤くなることを実測 |
| 3 | Warning | `credential/store.ts`: `purgeProfile` の `complete: this.keychain === null` が active backend ではなくフィールドの有無で判定している | **対応する** | `complete: this.primary() === this.fileStore` へ変更 (`primary()` と**同じ式**から導くので将来ずれない)。加えて「keychain 候補はあるが `isAvailable()` が false」のケースで file backend として完遂することを固定するテストを追加 |
| 4 | Warning | `tests/profile/delete.test.ts`: `(thrown as ProfileResolutionError)` 等の ad-hoc cast が TS 規約に反する。`process.exit` mock の cast も同様 | **対応する** | `expectProfileResolutionError` / `expectCredentialStoreError` / `captureThrow` ヘルパを導入し、`instanceof` で実 narrowing する形へ全 4 箇所を書き換え。CLI 契約テストの `process.exit` mock も `fakeExit` を型付き関数として先に定義し cast を撤去。`as` cast は両テストファイルから 0 件になった |
| 5 | Suggestion | CLI 契約 #4 は「config も credential も無傷」の設計だが config しか見ていない | **対応する** | 一時 HOME 配下に plaintext credential を実際に書き、確認拒否後もファイルが残っていることを検証 |

## 補足（テストで discriminate できない事項の明示）

- **#1 の再発防止**: `resolveContext` は本コマンドの `resolveMode: "if-needed"` 経路では
  実質的に失敗しない (`new FileProfileWriter()` / `new CredentialStore()` はいずれも
  構築時に config を読まない) ため、順序を戻しても既存の CLI 契約テスト #3 は緑のままである。
  すなわちこの修正は**テストで固定できない堅牢化**であり、その旨をコード内コメントで残した。
- **#3 の不変条件**: `CredentialStore` の constructor は
  `this.keychain = candidate !== null && candidate.isAvailable() ? candidate : null` なので、
  現時点では `this.keychain === null` と `primary() === this.fileStore` は同値である。
  追加したテストはこの constructor 不変条件そのものを固定するもので、
  式の書き換え単体を discriminate するものではない (指摘の「どちらか」ではなく両方行った)。

## 逆確認 (追加分)

| # | 改悪 | 実測結果 |
|---|------|---------|
| M7 | `plansMatch` から `unlocatable` の `reason` 比較を削除 | 1 failed: `f. unlocatable 同士でも理由が変われば競合終了する` |

## 見送った指摘

なし (全 5 件対応)。

## 検証結果 (対応後)

```
pnpm typecheck:packages : OK
pnpm test:packages      : 10 files / 104 passed / 0 failed  (Round 1 時点は 102)
pnpm -F "./packages/*" lint : OK
pnpm lint / pnpm typecheck  : OK
```
