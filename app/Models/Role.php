<?php

declare(strict_types=1);

namespace App\Models;

use Laratrust\Models\Role as RoleModel;

class Role extends RoleModel
{
    /**
     * パッケージ既定は $guarded = [] (全開放) のため、明示的に許可リスト化する
     * (tests/Architecture/MassAssignmentSafetyTest の不変条件)。
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'display_name', 'description'];

    public $guarded = [];
}
