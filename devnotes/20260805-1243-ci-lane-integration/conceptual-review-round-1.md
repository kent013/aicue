全体判定: **APPROVED**

概念設計としては妥当です。CI に載っていない検証レーンを、ローカルの公式 entrypoint と同じ形で CI 化する方針は North Star とセキュリティ不変条件に直結しています。特に PostgreSQL 実 DB、Browser WebKit lane、vitest inventory gate、未配線 shard 削除の判断は一貫しています。

**[Warning] audit:gate の運用責任がまだ設計上やや弱い**

判断 B は方向性として妥当です。soft-fail / baseline を拒否し、「緑にしてから blocking」は AGENTS.md の supply-chain 規約とも整合します。

ただし、blocking 化後に上流 advisory で全 PR が赤くなるトレードオフは、nightly だけでは完全には吸収できません。設計に以下を明記すると持続性が上がります。

- nightly 赤化時の一次対応 owner
- high/critical ごとの初動 SLA
- upgrade 不可時に accept-risk を誰が承認するか
- accept-risk 用 tracking issue の必須化を CI で確認すること
- Dependabot / Renovate 等の自動 upgrade PR と組み合わせるかどうか

「第 4 の選択肢」としては、PR では lockfile/package manifest 変更時だけ audit を blocking にする案がありますが、これは既存 advisory を抱えた main を許容しやすく、今回の supply-chain invariant とは弱くなります。したがって本設計の採用案の方が筋が良いです。

**[Warning] browser lane の 2 レーン契約は CI 設定側の固定テストが必要**

判断 G は妥当です。`BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を CI で上書きしない方針は、T082 を骨抜きにしないために重要です。

修正提案として、`scripts/run-browser-test.contract.test.ts` だけでなく、`.github/workflows/ci.yml` に対しても以下を検査する Architecture / JS test を入れるのがよいです。

- `browser-tests` job が存在する
- `composer test:browser` を呼んでいる
- `BROWSER_TEST_LANES` を設定していない
- `BROWSER_TEST_PROCESSES` を設定していない
- `pnpm exec playwright install --with-deps chromium webkit` を含む
- `continue-on-error` が無い

run script の契約だけ守っても、workflow 側で lane を絞る退行は残り得ます。

**[Warning] vitest inventory gate は workflow 側との結合も固定した方がよい**

判断 E/F の論理は概ね妥当です。SoT を project 配列で表現する判断、FS 走査と `vitest list` を独立に突合する判断、子プロセス起動を `it()` 内へ閉じる再帰対策はいずれも正しいです。

ただし、gate が追加されても CI の `pnpm test` がその project を確実に走らせることまで固定しないと、将来 workflow 側で test command が差し替わった時に空洞化します。

修正提案:

- `frontend` job が root `pnpm test` と `pnpm test:packages` の両方を呼ぶことを workflow inventory test で固定する
- `vitest-inventory-gate.test.ts` 自身が root project に列挙されることを明示 assertion する
- gate の子プロセスには再帰防止用 env を入れず、設計どおり `vitest list --json=<tmpfile>` のみに限定する

**[Suggestion] make-shard-phpunit.php 削除は妥当**

判断 D は「後方互換の並走を残さない」に照らして妥当です。未配線 shard generator は、現在の公式 entrypoint と違う経路を温存しており、台帳ドリフトの原因にもなっています。

削除に加えて `ScriptsReadmeInventoryTest` を入れる方針も適切です。将来 sharding が必要になった場合は、設計にある通り `run-test.sh` 側へ shard 引数を通す形で再設計するべきです。

**[Suggestion] PostgreSQL 18 採用は妥当だが、Actions runner 側の ready check を明示したい**

`postgres:18-alpine` を compose と揃える判断は合理的です。`DB_DATABASE` を置かず、既存の `tests/bootstrap.php` の DB 名注入を保つ点も良いです。

追加で、workflow には service health check を明示するとよいです。`ensure-test-db.php` が fail-closed でも、DB 起動待ちの揺らぎを CI flake にしないためです。

**[Suggestion] スコープは大きいが 1 バッチとしては許容範囲**

9 施策は多いですが、共通テーマが「CI lane integration」で揃っており、相互依存も明確です。特に audit 解消だけは時間 drift の影響を受けるため、実装時は最初に再実測し、必要なら supply-chain 部分だけ先行 PR に分ける判断はあり得ます。

禁止事項違反は見当たりません。DTO / JsonResource パターンにも直接触れない変更で、PHPStan level 10 についても `file_get_contents` 等の narrow 方針が明記されており妥当です。