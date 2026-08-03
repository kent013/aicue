<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\Organization;
use Illuminate\Contracts\Session\Session;

/**
 * onboarding subscription gate で失われる「意図先 destination」を org-scoped session に保持し、
 * checkout / activate 完了着地で復帰導線 (continueUrl) を提供する。IntendedPlanResolver と同型の
 * 3 規約 (put: 妥当な same-origin 内部 path のみ / peek: 再正規化 / forget: 消費)。
 *
 * open-redirect 防御: 保存値は **same-origin の内部相対 path (先頭 '/')** のみ許可する。
 * 絶対 URL / '//host' (protocol-relative) / 別ホストは破棄して保存しない。
 */
final class OnboardingReturnResolver
{
    public function __construct(private readonly Session $session) {}

    public static function orgKey(Organization $organization): string
    {
        return "onboarding.return_to.org.{$organization->id}";
    }

    /**
     * 内部 path への正規化 (open-redirect 防御を多段で実施)。
     * same-origin 内部相対 path のみ許可し、それ以外 (絶対URL/protocol-relative/scheme/host/
     * user:pass@/port/バックスラッシュ/制御文字/エンコード回避) は null。
     */
    public static function normalizePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // 1) 制御文字 (改行/タブ/NUL 等) を拒否 (ヘッダ/解釈差注入対策)。
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        // 2) raw / エンコード両方で判定する (%2F%2F や %5C のブラウザ解釈差回避)。
        $decoded = rawurldecode($value);
        foreach ([$value, $decoded] as $candidate) {
            // decoded 後に制御文字 (%0a/%0d/%09/%00 等) が現れるケースを拒否
            // (header/解釈差注入対策。raw 側は step 1 で既に判定済だが decoded 側もここで弾く)。
            if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
                return null;
            }
            // scheme 付き (javascript: / http: 等) と protocol-relative (//host) を拒否。
            if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $candidate) === 1) {
                return null;
            }
            // バックスラッシュ混入 (\\host / /\host のブラウザ解釈差) を拒否。
            if (str_contains($candidate, '\\')) {
                return null;
            }
            // 先頭 '/' 必須 (相対 path や 'foo' は拒否)。
            if (! str_starts_with($candidate, '/')) {
                return null;
            }
        }
        // 3) parse_url で scheme/host/user/pass/port が 1 つでもあれば絶対 URL とみなし拒否。
        $parsed = parse_url($value);
        if ($parsed === false) {
            return null;
        }
        foreach (['scheme', 'host', 'user', 'pass', 'port'] as $key) {
            if (isset($parsed[$key])) {
                return null;
            }
        }
        // 4) path のみ採用 (query は保持、fragment は drop)。返却値は raw 由来の parse_url 結果で一貫させる。
        $path = $parsed['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        return $path.$query;
    }

    public function rememberForOrganization(Organization $organization, string $path): void
    {
        $normalized = self::normalizePath($path);
        if ($normalized === null) {
            return; // 不正値は no-op (既存 return_to を壊さない)
        }
        $this->session->put(self::orgKey($organization), $normalized);
    }

    public function peekForOrganization(Organization $organization): ?string
    {
        $raw = $this->session->get(self::orgKey($organization));

        return is_string($raw) ? self::normalizePath($raw) : null; // 再検証 (改ざん耐性)
    }

    public function forgetForOrganization(Organization $organization): void
    {
        $this->session->forget(self::orgKey($organization));
    }
}
