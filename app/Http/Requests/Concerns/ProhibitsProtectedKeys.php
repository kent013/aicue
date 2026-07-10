<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Security\MassAssignmentProtectedKeys;

/**
 * mass-assignment 入口防御 (Q3 決定: 広い universal 集合 + `missing`)。
 *
 * 保護キーが payload に**存在するだけで 422** にする (`prohibited` と違い空値も拒否 =
 * 「このエンドポイントは当該キーを受け付けない」という強い契約)。
 *
 * 使い方 (rules() で必ず後勝ち merge):
 *     return array_merge([
 *         'name' => ['required', 'string'],
 *     ], $this->protectedKeyMissingRules());
 *
 * 正当に受け取る必要があるキー (多態スコープ等) が生じた場合は、テンプレからの
 * 逸脱として docs/template-divergence.md に記録した上で except を使う。
 */
trait ProhibitsProtectedKeys
{
    /**
     * @param  list<string>  $except
     * @return array<string, list<string>>
     */
    protected function protectedKeyMissingRules(array $except = []): array
    {
        $rules = [];
        foreach (MassAssignmentProtectedKeys::all() as $key) {
            if (! in_array($key, $except, true)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
