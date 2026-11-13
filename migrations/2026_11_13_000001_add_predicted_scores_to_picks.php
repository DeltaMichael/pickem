<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('pickem_picks', function (Blueprint $table) {
            $table->unsignedInteger('predicted_home_score')->nullable();
            $table->unsignedInteger('predicted_away_score')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('pickem_picks', function (Blueprint $table) {
            $table->dropColumn(['predicted_home_score', 'predicted_away_score']);
        });
    }
];
