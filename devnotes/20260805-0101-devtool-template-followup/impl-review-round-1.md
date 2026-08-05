**指摘**

[Warning] [packages/cli/src/oclif/commands/profile/delete.ts](/workspace/.claude/worktrees/tasks/T100/packages/cli/src/oclif/commands/profile/delete.ts:35)  
設計書の実装順序は「1. 名前検証 → 2. planProfileDeletion」ですが、実装は `resolveContext(flags)` の後に `assertProfileName(name)` を呼んでいます。`resolveContext` が config/credential 初期化や読み込みで失敗する状態だと、不正プロファイル名でも exit 13 ではなく別エラーになり得ます。CLI 契約を固定するなら、`const name = args.name; assertProfileName(name);` を `resolveContext` より前へ移すべきです。

[Warning] [packages/cli/src/profile/delete.ts](/workspace/.claude/worktrees/tasks/T100/packages/cli/src/profile/delete.ts:99)  
TOCTOU ガードの `plansMatch` が `credentials.kind === "unlocatable"` の詳細を比較していません。たとえば確認待ち中に `api_url` が未設定から `ftp://...` へ変わっても、どちらも `unlocatable` として一致し、設計書の「config が書き替わったら何も触らず exit 10」に反します。少なくとも `reason`、できれば計画時の `entry.api_url` そのものを plan に持たせて比較してください。

[Warning] [packages/cli/src/credential/store.ts](/workspace/.claude/worktrees/tasks/T100/packages/cli/src/credential/store.ts:337)  
`purgeProfile()` の `complete: this.keychain === null` は、実際の active backend ではなく keychain フィールドの有無で判定しています。`CredentialStore` が「keychain オブジェクトはあるが無効なので file backend を使う」形を取り得るなら、file backend の index 破損でも `complete: false` になり config を消せません。`primary()` と同じ判定を使うか、この constructor invariant をテストで固定してください。

[Warning] [packages/cli/tests/profile/delete.test.ts](/workspace/.claude/worktrees/tasks/T100/packages/cli/tests/profile/delete.test.ts:348)  
`(thrown as ProfileResolutionError)` / `(thrown as CredentialStoreError)` が複数あります。今回の TS 縛りは「ad-hoc な `as` cast を新規導入しない」なので、`if (!(thrown instanceof ProfileResolutionError)) throw ...` のように実際の narrowing へ寄せるのが筋です。`process.exit` mock の cast も同様に、型付き関数を先に定義すれば避けられます。

[Suggestion] [packages/cli/tests/commands/profile/delete.test.ts](/workspace/.claude/worktrees/tasks/T100/packages/cli/tests/commands/profile/delete.test.ts:108)  
CLI 契約 #4 は「config も credential も無傷」と設計されていますが、実テストは config のみを見ています。ロジック層で大半は押さえられていますが、CLI 層でも拒否時に credential が触られないことを 1 本見ると契約がより直接になります。

**ファイルごとの判定**

- `.claude/skills/app-codex-review/SKILL.md`: OK。設計どおり `gpt-5.5` へ一本化。
- `.claude/skills/app-codex-vscode/SKILL.md`: OK。旧モデル注記削除も設計判断と一致。
- `.claude/skills/app-design/SKILL.md`: OK。reasoning effort は保持されている。
- `.claude/skills/app-implement/SKILL.md`: OK。
- `tests/js/architecture/codex-model-consistency.test.ts`: OK。deny-by-default と inventory drift ガードは効いている。
- `package.json`: OK。`typecheck:packages` 追加は設計どおり。
- `.github/workflows/ci.yml`: OK。job 数を増やさず frontend lane に追加している。
- `AGENTS.md`: OK。検証コマンドの追記は設計どおり。
- `packages/cli/src/config/saver.ts`: OK。atomic replacement 化と JSDoc の限定も妥当。
- `packages/cli/src/util/atomic-write.ts`: OK。移動のみ。
- `packages/cli/tests/config/saver.test.ts`: OK。
- `packages/cli/src/credential/errors.ts`: OK。`kind` の追加は握り潰し防止として妥当。
- `packages/cli/src/credential/file-store.ts`: OK。
- `packages/cli/src/credential/keychain.ts`: OK。サービス名の正本参照化は妥当。
- `packages/cli/src/credential/store.ts`: Warning あり。active backend 判定を確認・補強したい。
- `packages/cli/src/profile/writer.ts`: OK。
- `packages/cli/src/profile/delete.ts`: Warning あり。TOCTOU 比較がやや粗い。
- `packages/cli/src/oclif/commands/profile/delete.ts`: Warning あり。名前検証順序が設計とずれている。
- `packages/cli/src/oclif/commands/profile/use.ts`: OK。
- `packages/cli/tests/profile/delete.test.ts`: Warning あり。cast は TS 規約に寄せて直したい。
- `packages/cli/tests/commands/profile/delete.test.ts`: Suggestion あり。stdout を `log` spy で見る逸脱自体は妥当。

**逸脱 4 点への判定**

(a) `api_url: "not a url"` 未実装は妥当です。schema で到達不能なら、`ftp://` と未設定で `locateCredentials` の実分岐は押さえられています。  
(b) `ProfileDelete.prototype.log` spy は妥当です。oclif の束縛事情があるなら、コマンド出力契約の観測として十分です。  
(c) `confirmPrompt` の `vi.mock` は妥当です。#2 で 0 回呼び出しまで見ている点が重要です。  
(d) devnotes の同一ディレクトリ集約は妥当です。今回のコード品質には影響しません。

`build:packages` の既存失敗は、本バッチでは別 TODO でよいです。main でも再現し、設計の CI 契約は `typecheck:packages` + `test:packages` なので、T100 のマージブロッカーにはしなくてよい。ただし CLI 配布前のブロッカーとして起票すべきです。

**全体判定: CHANGES_REQUESTED**