# user (Round 2): Round 1 指摘への対応

# 対応マトリクス: impl-review Round 1

Round 1 の全体判定は **APPROVED**。Critical は 0 件。Warning 1 件・Suggestion 1 件を以下のとおり捌いた。

## [Warning] 供給先の親ディレクトリが symlink の場合は拒否されない (scripts/setup-worktree.sh)

- 判断: **保証範囲の限定を明記する形で対応する** (機構は足さない)
- 根拠:
  - 新規 worktree の `storage/` は git 追跡下の実ディレクトリとして作られるため、
    親ディレクトリが symlink である状態は setup が作らない。起こすには作成後に人手で張り替える必要がある。
  - 呼び出しは同一ファイル内の 4 行の定数だけで外部入力を受けない (詳細設計の設計判断表と同じ理由)。
    今必要でない防御は作らない (思考原則 2)。
  - 一方で「symlink を辿らない」と読める書き方のまま限定を書かないのは、
    保証範囲の誇張にあたる (AGENTS.md が一貫して禁じている書き方)。
- 対応内容: `provision_secret_file` の契約コメント 5) に
  「見るのは供給先のファイル自身だけで、親ディレクトリが symlink の場合は検出しない」旨と、
  それが今は起き得ない理由を追記した。挙動は変えていない。

## [Suggestion] D-12 に「optional 成功時にも記録される」ケースを足すと強い

- 判断: **対応する**
- 根拠: 記録が `required` 専用になる退行 (= optional で供給したパスが health check の
  再検証から漏れる) を D-12 が今は検出できない。追加コストは 4 行で、偽グリーンを 1 つ減らせる。
- 対応内容: D-12 に「optional で供給元がある場合は `PROVISIONED_PATHS` に記録される」assertion を追加。
  契約テストは 18 ケースのまま (assertions 40 → 42) で green。

## [Approved] その他

AGENTS.md / docs/worktree-isolation-strategy.md / scripts/README.md / 主要実装 / 18 ケースの実効性は
指摘なし。DESIGN.md / Atomic Design 観点は diff が `resources/js` `resources/css` を含まないため該当なし。


## 追加差分 (Round 1 の返答を受けて入れた変更のみ)

### scripts/setup-worktree.sh — 契約コメント 5) への追記 (挙動変更なし)

```diff
 #   5) **供給先が symlink なら落とす**。install は symlink を辿ってリンク先へ書き込むため、
 #      辿った先が worktree の外でも 0600 の秘密ファイルを置いてしまう。
+#      ★ 保証範囲を誇張しない: 見るのは**供給先のファイル自身**だけである。
+#        親ディレクトリが symlink の場合 (例: worktree/storage が外を指す) は検出しない。
+#        新規 worktree の storage/ は git 追跡下の実ディレクトリとして作られるため今は起き得ず、
+#        起こすには作成後に人手で張り替える必要がある。今必要でない防御は作らない (思考原則 2)。
```

### tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php — D-12 への追加 assertion

```diff
         $skipped = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
         expect($skipped->exitCode() ?? 1)->toBe(0);
         expect(provisionedPathsOf($skipped))->toBe([], '供給していないパスが記録されている');
+
+        // optional でも供給できたときは記録される (記録が required 専用になっていない)。
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\\n");
+        $optional = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
+        expect($optional->exitCode() ?? 1)->toBe(0);
+        expect(provisionedPathsOf($optional))->toBe(['.env.bughunt.local'], '供給したパスが記録されていない');
```

## 再実行結果

- `bash -n scripts/setup-worktree.sh`: 構文 OK
- `composer test -- --filter=SetupWorktreeRuntimeFilesContractTest`: passed 18/18 (assertions 42)

## 質問

上記の対応で Round 1 の Warning / Suggestion が解決しているか判定し、全体判定 (APPROVED / CHANGES_REQUESTED) を明記せよ。
