<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends TenantController
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    /**
     * Obtiene los indicadores y estadísticas del Dashboard
     * correspondientes a la organización activa.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => [
                'sometimes',
                'required',
                'string',
                'date_format:Y-m',
            ],
        ], [
            'month.required' => 'Debes proporcionar un mes válido.',

            'month.string' => 'El periodo debe ser una cadena de texto.',

            'month.date_format' => 'El periodo debe tener el formato YYYY-MM.',
        ]);

        $period = isset($validated['month'])
            ? CarbonImmutable::createFromFormat(
                '!Y-m',
                $validated['month']
            )->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $dashboard = $this->dashboardService->getDashboard(
            $request->user(),
            $period
        );

        return response()->json($dashboard);
    }
}
