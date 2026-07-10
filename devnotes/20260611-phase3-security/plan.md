# Phase 3: セキュリティ層(M4)実装メモ

> ドナー: spirux(入口防御)+ aigenba(URL 整合 guard)。Q3/Q5/Q9/Q15 決定の実装。

## スコープ(今回)

1. MassAssignmentProtectedKeys(SSOT)+ ProhibitsProtectedKeys trait(`missing` rule)
2. Architecture テスト:
   - MassAssignmentSafetyTest(全 Model の $fillable に保護キーが無い)
   - FormRequestProhibitedKeyTest(全 FormRequest が trait 使用 — deny-by-default)
   - NestedRouteIdorDefenseTest(パラメータ付き route の防御モード inventory — deny-by-default)
3. URL 整合 guard: `{user}` 等の子パラメータが current org に属さなければ**認可より前に 404**
   (resolveOrganizationMember helper。Service 層の 422 チェックは第 2 層として残す)
4. SecurityHeaders middleware + config/security.php(HSTS/CSP/Referrer-Policy/X-Frame、env 駆動)
5. RedirectToHttps(308、`FORCE_HTTPS_REDIRECT` で無効化可=Q9)
6. production:preflight command(APP_DEBUG/APP_KEY/CIPHERSWEET_KEY/SECURE_COOKIE/HSTS/DEBUG_LOGIN 検査)
7. docs/template-divergence.md 空レジストリ

## 後続フェーズ送り(理由付き)

- kent013/laravel-ssrf-pin: ドナーでは path/VCS repo 参照。packagist 公開状況の確認が必要なため
  外部 HTTP を叩く機能(Phase 6 以降)とセットで導入
- cache serializable_classes=false: ドナー独自拡張の可能性があり、Laravel 13 標準 config に
  該当キーが無ければ「object cache 禁止」を docs 規約として書く(Phase 5 で再確認)
- ModelDirectFetchInvariantTest: ドメインリソースが増える Phase 4 で導入
