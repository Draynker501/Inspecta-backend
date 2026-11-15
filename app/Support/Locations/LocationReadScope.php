<?php

namespace App\Support\Locations;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class LocationReadScope
{
    public static function rejectUnknownQuery(Request $request, array $allowed): void
    {
        foreach (array_keys($request->query()) as $key) {
            if (! in_array($key, $allowed, true)) {
                abort(422);
            }
        }
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    public static function hasLocationVisibility(User $user): bool
    {
        return $user->hasPermission(Permission::LocationsViewAll);
    }

    public static function canViewPlant(User $user, string $plantId): bool
    {
        return self::hasLocationVisibility($user) || $user->hasPlantAccess($plantId);
    }

    public static function canViewArea(User $user, Area $area): bool
    {
        if (self::hasLocationVisibility($user)) {
            return true;
        }

        if ($user->hasAreaAccess((string) $area->getKey())) {
            return true;
        }

        $plantId = $area->getAttribute('plant_id');

        return $plantId !== null && $user->hasPlantAccess((string) $plantId);
    }

    public static function canViewLine(User $user, ProductionLine $line): bool
    {
        if (self::hasLocationVisibility($user)) {
            return true;
        }

        $areaId = $line->getAttribute('area_id');

        if ($areaId === null) {
            return false;
        }

        if ($user->hasAreaAccess((string) $areaId)) {
            return true;
        }

        $plantId = $line->area?->getAttribute('plant_id');

        return $plantId !== null && $user->hasPlantAccess((string) $plantId);
    }

    public static function plants(User $user, string $organizationId): Builder
    {
        $query = Plant::query()->where('organization_id', $organizationId);

        if (! self::hasLocationVisibility($user)) {
            $query->whereIn('id', function ($subquery) use ($user, $organizationId): void {
                $subquery->select('plant_id')
                    ->from('user_plants')
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organizationId);
            });
        }

        return $query;
    }

    public static function areasForPlant(User $user, string $organizationId, string $plantId): Builder
    {
        $query = Area::query()
            ->where('organization_id', $organizationId)
            ->where('plant_id', $plantId);

        if (! self::hasLocationVisibility($user)) {
            $query->where(function (Builder $scope) use ($user, $organizationId, $plantId): void {
                $scope->whereExists(function ($subquery) use ($user, $organizationId): void {
                    $subquery->selectRaw('1')
                        ->from('user_areas')
                        ->whereColumn('user_areas.area_id', 'areas.id')
                        ->where('user_areas.user_id', $user->id)
                        ->where('user_areas.organization_id', $organizationId);
                });

                if ($user->hasPlantAccess($plantId)) {
                    $scope->orWhereRaw('1 = 1');
                }
            });
        }

        return $query;
    }

    public static function linesForArea(User $user, string $organizationId, string $areaId, string $plantId): Builder
    {
        $query = ProductionLine::query()
            ->where('organization_id', $organizationId)
            ->where('area_id', $areaId);

        if (! self::hasLocationVisibility($user)) {
            $query->where(function (Builder $scope) use ($user, $organizationId, $areaId, $plantId): void {
                $scope->whereExists(function ($subquery) use ($user, $organizationId, $areaId): void {
                    $subquery->selectRaw('1')
                        ->from('user_areas')
                        ->where('user_areas.area_id', $areaId)
                        ->where('user_areas.user_id', $user->id)
                        ->where('user_areas.organization_id', $organizationId);
                });

                if ($user->hasPlantAccess($plantId)) {
                    $scope->orWhereRaw('1 = 1');
                }
            });
        }

        return $query;
    }
}
