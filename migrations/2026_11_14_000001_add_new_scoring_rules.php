<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('pickem_events', function (Blueprint $table) {
            $table->boolean('new_scoring')->default(false);
            $table->boolean('is_special')->default(false);
        });
        $schema->table('pickem_user_scores', function (Blueprint $table) {
            $table->decimal('total_points', 14, 1)->default(0)->change();
        });
    },
    'down' => function (Builder $schema) {
        if ($schema->getConnection()->table('pickem_events')->where('new_scoring', true)->exists()
            || $schema->getConnection()->table('pickem_user_scores')->whereRaw('total_points <> ROUND(total_points)')->exists()) {
            throw new RuntimeException('Cannot roll back scoring rules while new-rule matches or fractional points exist.');
        }
        $schema->table('pickem_events', function (Blueprint $table) {
            $table->dropColumn(['new_scoring', 'is_special']);
        });
        $schema->table('pickem_user_scores', function (Blueprint $table) {
            $table->integer('total_points')->default(0)->change();
        });
    }
];
