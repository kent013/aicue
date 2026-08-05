**全体判定: CHANGES_REQUESTED**

レビュー仮説: この設計の主リスクは「CI で走っているように見えるが実際は契約が抜ける偽グリーン」と「pgsql 化で dev DB 保護を迂回すること」。dev DB 保護の方向性は概ね正しいですが、audit gate と workflow inventory に偽グリーン経路が残っています。

| 施策 | 判定 | コメント |
|---|---|---|
| 1 php job pgsql 化 | APPROVE | `DB_DATABASE` を job env に置かず、`tests/bootstrap.php` の単一点ガードに寄せる判断は正しい。`DB_HOST/PORT/USERNAME/PASSWORD` の job env は接続先情報なので、dev DB 名の保護とは衝突しない。 |
| 2 browser-tests job | APPROVE | 2 レーン既定を workflow 側で上書きしない方針は妥当。`pnpm build` と `playwright install --with-deps chromium webkit` も必要条件として正しい。 |
| 3 packages build 配線 | APPROVE | `typecheck:packages` と `build:packages` の役割差分が明確。dead import 削除も挙動変更ではない。 |
| 4 advisory 解消 | REQUEST_CHANGES | 下記 Critical。 |
| 5 supply-chain-audit job | REQUEST_CHANGES | 下記 Critical。 |
| 6 browser script contract test | APPROVE | sandbox 実走で C1-C4/C8 は検証できる。`GLOBAL_TEST_LOCK_DIR` をテスト harness から渡すのは、lane script 自身の自己バイパスではないため T099 違反ではない。 |
| 7 phpunit browser parity | APPROVE | `bootstrap` 共有、`<server>` 完全一致、差分 `memory_limit` のみ、testsuite 分離を固定する設計は妥当。 |
| 8 vitest inventory gate | APPROVE | FS 走査と `vitest list` を独立に突き合わせる方針は良い。`devnotes` 除外も設計記録をテスト対象にしない目的なら妥当。 |
| 9 make-shard 削除 | APPROVE | 未配線 script の削除と README 台帳 gate は過剰ではない。今回観測されたドリフトへの直接対策になっている。 |
| 10 CI workflow inventory | REQUEST_CHANGES | 下記 Critical。 |
| 11 docs | APPROVE | 実体を施策 6/10 で守る前提なら追従ドキュメントとして妥当。 |

**[Critical] 施策 4/5: audit 取得失敗を空 JSON で通す既知穴は、このバッチで放置できない**

`pnpm run audit:gate` を CI blocking に昇格する設計なのに、`audit-gate.sh` がネットワーク失敗や tool failure を空 advisory として通すなら、「blocking job だが取得失敗時は緑」という偽グリーンになります。これは supply-chain gate の目的に反します。

修正案:
- `audit-gate.sh` は audit コマンドの出力が空・不正 JSON・期待 schema 不在の場合に fail する。
- audit コマンドの非ゼロは「脆弱性検出」と「取得失敗」を分ける。非ゼロでも有効 JSON が取れていれば judge へ進める、空/invalid なら fail。
- pnpm/composer/pip それぞれについて、スタブコマンドで「空出力」「invalid JSON」「有効 JSON + 非ゼロ」を再現する contract test を追加する。
- その修正後に施策 5 の CI job を追加する。順序は `audit-gate fail-closed 化 → advisory 解消 → supply-chain-audit job 配線`。

**[Critical] 施策 10 W9: YAML key 走査だけでは `BROWSER_TEST_LANES` の inline override を検出できない**

`env: { BROWSER_TEST_LANES: chromium }` は検出できますが、次は通ります。

```yaml
run: BROWSER_TEST_LANES=chromium composer test:browser
```

または:

```yaml
run: |
  export BROWSER_TEST_LANES=chromium
  composer test:browser
```

これは CI の WebKit レーンを骨抜きにする偽グリーン経路です。W13 の `continue-on-error` は key としてしか意味を持たないので key 走査で十分ですが、W9 は env 変数名の scalar 値内出現も見る必要があります。

修正案:
- `findKeyPaths` に加えて、全 scalar string を走査する `findScalarValuePathsContaining(node, pattern)` を追加。
- `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` は key 名だけでなく、`run` 文字列を含む workflow 全体の scalar 値にも出現禁止にする。
- コメントは YAML parse 後に落ちるため、workflow コメントで説明を書く運用とは衝突しない。

**[Warning] 施策 6: 負のコントロール記述と検査方式が一部ずれている**

C2 は sandbox 実走で十分検証できますが、「レーンループから `break` するよう改変した source → C2 の静的検査」と書かれている一方、静的検査項目には C2 検出器がありません。

修正案:
- C2 の負のコントロールは「mutated script を sandbox に置いて実走し、2 行記録されないことを検出する」に変更する。
- もしくは C2 の静的負のコントロール記述を削り、C2 は実走検査の責務だと明記する。

**[Warning] 施策 8: 再帰防止条件の表現を実装に合わせるべき**

「子プロセス起動を it() 内に閉じる」と書きつつ、後段では `beforeAll` 共有を許容しています。`vitest list` が test/hook を実行しない前提なら `beforeAll` でも再帰しませんが、設計文としては揺れています。

修正案:
- 条件を「module top-level と `describe` callback 内で spawn しない。spawn は通常実行時にだけ走る `it`/hook callback 内に閉じる」と明文化する。
- helper 初期化時に spawn しないことをコード構造で守る。

**[Suggestion] 施策 9: README inventory は将来の nested scripts も拾うとより堅い**

現設計は `scripts/*` と `scripts/ci/*` だけを見るため、将来 `scripts/foo/bar.sh` が追加されると漏れます。現時点では問題ありませんが、deny-by-default を名乗るなら recursive scan + 明示除外の方が筋が通ります。

DTO/JsonResource、Inertia Props、DESIGN.md、Atomic Design は本バッチでは該当なしです。dev DB 保護については、少なくとも設計上は `DB_DATABASE` 不在 + bootstrap 単一点ガードに寄せており、壊していない判断です。