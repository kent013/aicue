## Round 2: Round 1 の指摘への対応

対応マトリクスは devnotes の codex-history/design-review-decisions-round-1.md に記録済み。要点:

### [Critical 1] 施策 3 の更新トークン失効漏れ → **修正した**
母集団の取得から `where('revoked', false)` を外し、絞るのは件数を数える更新文の側だけにした。
更新文には主キーで絞った後も `organization_id` / `user_id` を再条件として残した。
施策 8 に「親の利用トークンが既に失効済みで更新トークンだけ未失効という不整合行も失効する」を追加した。

修正後のコード:

```php
        /** @var list<string> $tokenIds */
        $tokenIds = DB::table('oauth_access_tokens')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->pluck('id')
            ->all();

        $accessTokens = 0;
        $refreshTokens = 0;
        if ($tokenIds !== []) {
            $accessTokens = DB::table('oauth_access_tokens')
                ->whereIn('id', $tokenIds)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('revoked', false)
                ->update(['revoked' => true]);
            $refreshTokens = DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);
        }
```

### [Critical 2] 施策 6 の API キーの前提 → **指摘が正しく、設計側の主張が誤っていた。前提そのものを訂正した**
実装 (`ResolveApiActor::contextFromApiKey()` と `ProjectController::index()`) を実読した結果、
API キー経路は**発行者の所属を再評価していない**ことを確認した。したがって
「退会者が発行した API キーは実行時に拒否される」は**読み取りについて偽**である。
真の境界は次のとおりで、これを施策 8 の振る舞いテストで固定する:

| 経路 | 発行者が組織から外れた後 |
|---|---|
| API キーで読み取り | 通る (組織の資産である鍵として振る舞う) |
| API キーで書き込み | 403 (`ProjectPolicy` が発行者の現在の組織ロールを評価する) |

所属の再評価を足すのは**本件の範囲外**とした。理由は、発行した管理者が抜けた瞬間に
組織の自動連携が無言で止まるという可用性側の事故を新たに作る判断であり、
正典 3 本 (laravel-claude-template / aigenba / spirux) も同じ理由で組織の API キーを
失効対象から外しているため。文書に残余リスクとして明記し、後続の独立した項目として残す。
施策 6 の検査 C からは API キーの項を削除した (静的検査で「守っている」と主張できないため)。

**この訂正の妥当性を判断してほしい。** 「範囲外にする」ではなく「本件で塞ぐべき」と考えるなら、
その根拠 (可用性の事故をどう避けるか) を添えて指摘してほしい。

### [指摘 D-1] 「理由で分岐しない」の検査が弱い → **より強い形に変えた**
分岐の書き方 (`match` / `switch` / `if ===`) を列挙して禁止する形をやめ、
**窓口の `revoke()` の本文に `$reason` がちょうど 1 回しか現れないこと**を完全一致で固定する形にした
(現れてよいのは監査 metadata の `'reason' => $reason->value` の 1 箇所だけ)。列挙は必ず漏れるため。

### [指摘 D-2] 位置検査は全制御パスを保証しない → **保証しないことを強く書いた**
「途中に早期 return や条件分岐を足せば、検査は緑のまま失効しない経路が生まれる」と明記し、
そこは施策 8 の振る舞いテストが担うと役割分担を書いた。

### [指摘 D-3] 施策 7 で戻り値を捨てる形を通し得る → **構造検査を追加した**
`authorizeTool(` が否定の形 (`if (! $ctx->authorizeTool(`) で現れ、直後に `throw` があることまで見る。
負例 fixture に「否定と throw が無い形」を追加した。

### [指摘 C] 失効経路で握り潰す版を使わないことの固定 → **施策 5 に検査 F を追加した**
窓口の本文に `->recordOrFail(` が現れ、`->record(` が現れないことを静的に固定する。

### [指摘 E] 完了条件 → **AGENTS.md の検証コマンド一式に差し替えた**
frontend を 1 行も変えないが、影響が無いことを口頭で述べず全レーンを走らせて示す、と明記した。

---

上記の対応を踏まえ、各施策の判定と全体判定を出し直してほしい。
とくに次を確認してほしい:
1. 修正後の施策 3 のクエリに、まだ失効漏れ・過剰失効・cross-org の穴が無いか
2. [Critical 2] への対応 (前提の訂正 + 範囲外の判断 + テストでの境界固定) が妥当か
3. 検査 E (出現回数の完全一致) が、正当な実装を誤って落とす形になっていないか
