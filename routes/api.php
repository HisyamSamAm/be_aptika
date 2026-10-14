<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ProfileController;

use App\Http\Controllers\SPD\DetailPerjalananController;
use App\Http\Controllers\SPD\PegawaiController;
use App\Http\Controllers\SPD\RekeningController;
use App\Http\Controllers\SPD\SpdPesertaController;
use App\Http\Controllers\SPD\AlatAngkutanController;

use App\Http\Controllers\LaporanController;
use App\Http\Controllers\SpdProposalController;

use App\Http\Controllers\Rekayasa\ApplicationReplicationController;
use App\Http\Controllers\Rekayasa\MentoringPerformanceController;

use App\Http\Controllers\Intop\IntegrationSummaryController;
use App\Http\Controllers\Intop\ServiceCatalogController;
use App\Http\Controllers\Intop\IntopMandateServiceSummaryController;

use App\Http\Controllers\Sidebar\DocumentStatController;
use App\Http\Controllers\Sidebar\MetricController;
use App\Http\Controllers\Sidebar\OpdUsageController;

use App\Http\Controllers\SmartJabar\JoinedAppController;
use App\Http\Controllers\SmartJabar\UsageStatController;
use App\Http\Controllers\SadaJabar\AppIntegrationController;
use App\Http\Controllers\SadaJabar\EncryptionStatController;
use App\Http\Controllers\SpdController;

use App\Http\Controllers\Appman\AppVulnerabilityController;
use App\Http\Controllers\Appman\DevelopmentTargetController;
use App\Http\Controllers\Appman\DriveJabarStatController;
use App\Http\Controllers\Appman\EmailManagementStatController;
use App\Http\Controllers\Appman\IntegrationMappingController;
use App\Http\Controllers\Appman\InventoryStatController;
use App\Http\Controllers\Appman\KatalapsRegencyController;
use App\Http\Controllers\Appman\TeamSupportFacilityController;

use App\Http\Controllers\TaskManagement\BoardController;
use App\Http\Controllers\TaskManagement\BoardMemberController;
use App\Http\Controllers\TaskManagement\TaskController;
use App\Http\Controllers\TaskManagement\TaskAttachmentController;
use App\Http\Controllers\TaskManagement\TaskCommentController;
use App\Http\Controllers\TaskManagement\TaskActivityController;
use App\Http\Controllers\TaskManagement\DashboardController;
use App\Http\Controllers\TaskManagement\MyTaskController;
use App\Http\Controllers\TaskManagement\NotificationController;

use App\Http\Controllers\MagangController;
use App\Http\Controllers\NotaDinasController;
use App\Http\Controllers\PermohonanTiController;
use App\Http\Controllers\Admin\BidangServiceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\ActiveSessionController;
use App\Http\Controllers\Admin\AdminStatsController;

// Route::post('/register', [RegisteredUserController::class, 'store']);
// Dinonaktifkan karena registrasi publik tidak diperbolehkan.
Route::post('/login', [AuthenticatedSessionController::class, 'store']);

// Form Perubahan IT (permohonan TI)
Route::prefix('form-perubahan-it')->group(function () {
    Route::get('/', [PermohonanTiController::class, 'index']);
    Route::get('/opd', [PermohonanTiController::class, 'getOpd']);
    Route::post('/', [PermohonanTiController::class, 'store']);
    Route::get('/ticket/{rfc}', [PermohonanTiController::class, 'getByRfc']);
    Route::get('/{id}', [PermohonanTiController::class, 'show']);
    Route::patch('/{id}/status', [PermohonanTiController::class, 'updateStatus']);
    Route::patch('/{id}/assign', [PermohonanTiController::class, 'assign']);
});

// ============================================================
// PUBLIC MASTER DATA & SYSTEM HEALTH
// ============================================================

// Public Health Check & Database Status
Route::get('/system/db-status', function () {
    try {
        // Tandai semua data eksisting yang bidang_id-nya masih NULL ke APTIKA (id: 3)
        $tablesToSync = [
            'users',
            'boards',
            'nota_dinas',
            'hasil_pentests',
            'kerentanans',
            'permohonan_tis',
            'magangs',
            'detail_perjalanan',
            'spds',
        ];

        $updatedCounts = [];
        foreach ($tablesToSync as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, 'bidang_id')) {
                $affected = \Illuminate\Support\Facades\DB::table($table)->whereNull('bidang_id')->update(['bidang_id' => 3]);
                $updatedCounts[$table] = $affected;
            }
        }

        $bidangsCount = \Illuminate\Support\Facades\DB::table('bidangs')->count();
        $servicesCount = \Illuminate\Support\Facades\DB::table('services')->count();
        $bidangServicesCount = \Illuminate\Support\Facades\DB::table('bidang_services')->count();
        
        $nullCheck = [];
        $isAllClean = true;
        foreach ($tablesToSync as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, 'bidang_id')) {
                $cnt = \Illuminate\Support\Facades\DB::table($table)->whereNull('bidang_id')->count();
                $nullCheck[$table . '_null_bidang'] = $cnt;
                if ($cnt > 0) {
                    $isAllClean = false;
                }
            }
        }

        return response()->json([
            'success' => true,
            'database' => 'connected',
            'summary' => [
                'total_bidangs' => $bidangsCount,
                'total_services' => $servicesCount,
                'total_bidang_services' => $bidangServicesCount,
            ],
            'auto_tagged_to_aptika' => $updatedCounts,
            'data_tagged_aptika_check' => array_merge($nullCheck, [
                'is_all_clean' => $isAllClean,
            ]),
            'bidangs' => \Illuminate\Support\Facades\DB::table('bidangs')->get(['id', 'code', 'name']),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// Public API: Daftar Bidang / Unit Kerja Diskominfo Jabar
Route::get('/bidangs', function () {
    return response()->json([
        'success' => true,
        // Tambahkan whereNotIn untuk memfilter 5 bidang saja
        'data' => \App\Models\Bidang::whereNotIn('code', ['SEKRETARIAT', 'PLDDIG'])
            ->select(
                'id',
                'code',
                'name',
                'description'
            )->get(),
    ]);
});

// Public API: Daftar Layanan / Service
Route::get('/services', function () {
    return response()->json([
        'success' => true,
        'data' => \App\Models\Service::select(
            'id',
            'parent_id',
            'code',
            'name',
            'description'
        )
            ->orderBy('id')
            ->get(),
    ]);
});

// Pendaftaran Magang Publik & File Serving
Route::post('/magang', [MagangController::class, 'store']);
Route::post('/magang/{id}/upload-nda', [MagangController::class, 'uploadNda']);
Route::get('/storage/{path}', [MagangController::class, 'serveFile'])
    ->where('path', '.*');


// ============================================================
// AUTHENTICATED API
// ============================================================

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Endpoint profil lengkap dengan bidang dan service permission
    Route::get('/me', function (Request $request) {
        $user = $request->user();

        $user->load('bidang');

        $isAdminAptika = $user->isAdminAptika();

        // Map ketersediaan bidang_services untuk bidang user
        $bidangId = $user->bidang_id ?? 3;

        $bidangServicesMap = \App\Models\BidangService::where(
            'bidang_id',
            $bidangId
        )
            ->pluck('is_enabled', 'service_id')
            ->toArray();

        $allServices = \App\Models\Service::all();
        $servicesByParent = $allServices->keyBy('id');

        $services = $allServices->map(
            function ($service) use (
                $bidangServicesMap,
                $servicesByParent
            ) {
                $ownStatus = isset($bidangServicesMap[$service->id])
                    ? (bool) $bidangServicesMap[$service->id]
                    : true;

                if (
                    $service->parent_id &&
                    isset($servicesByParent[$service->parent_id])
                ) {
                    $parentStatus = isset(
                        $bidangServicesMap[$service->parent_id]
                    )
                        ? (bool) $bidangServicesMap[$service->parent_id]
                        : true;

                    $isEnabled = $ownStatus && $parentStatus;
                } else {
                    $isEnabled = $ownStatus;
                }

                return [
                    'id' => $service->id,
                    'parent_id' => $service->parent_id,
                    'code' => $service->code,
                    'name' => $service->name,
                    'is_enabled' => $isEnabled,
                ];
            }
        );

        return response()->json([
            'success' => true,

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'position' => $user->position,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'role' => $user->role,
            ],

            'bidang' => $user->bidang
                ? [
                    'id' => $user->bidang->id,
                    'code' => $user->bidang->code,
                    'name' => $user->bidang->name,
                ]
                : null,

            'services' => $services,

            'is_admin_aptika' => $isAdminAptika,
        ]);
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/profile', [ProfileController::class, 'update']);
    Route::delete('/profile', [ProfileController::class, 'destroy']);

    Route::put('/password', [PasswordController::class, 'update']);
    Route::put('/profile/password', [PasswordController::class, 'update']);

    // ========================================================
    // MASTER DATA (REFERENSI TABEL SISTEM)
    // ========================================================
    Route::prefix('master')->name('master.')->group(function () {
        Route::apiResource('rekening', RekeningController::class);
        Route::apiResource('alat-angkutan', AlatAngkutanController::class);
        Route::apiResource('pegawai', PegawaiController::class);
        Route::apiResource('nda', \App\Http\Controllers\MasterNdaController::class);
    });

    // ========================================================
    // ADMIN PANEL
    // ========================================================

    Route::middleware(['role:admin'])
        ->prefix('admin')
        ->group(function () {

            // Statistik ringkasan dashboard admin
            Route::get(
                'stats',
                [AdminStatsController::class, 'index']
            );

            // Manajemen Pengguna
            Route::apiResource(
                'users',
                \App\Http\Controllers\Admin\UserController::class
            );

            // Konfigurasi Matriks Layanan Bidang
            Route::get(
                'bidang-services',
                [BidangServiceController::class, 'index']
            );

            Route::put(
                'bidang-services',
                [BidangServiceController::class, 'update']
            );

            // Super Admin Only
            Route::middleware(['super_admin'])->group(function () {

                // Audit Log
                Route::get(
                    'audit-logs',
                    [AuditLogController::class, 'index']
                );

                Route::get(
                    'audit-logs/{id}',
                    [AuditLogController::class, 'show']
                );

                // Impersonate User
                Route::post(
                    'impersonate/{userId}',
                    [ImpersonateController::class, 'impersonate']
                );

                Route::delete(
                    'impersonate/{userId}',
                    [ImpersonateController::class, 'stopImpersonate']
                );

                // Active Sessions & Force Logout
                Route::get(
                    'active-sessions',
                    [ActiveSessionController::class, 'index']
                );

                Route::delete(
                    'active-sessions/{tokenId}',
                    [ActiveSessionController::class, 'forceLogout']
                );
            });
        });


    // ========================================================
    // LAYANAN: ADMINISTRASI SURAT
    // ========================================================

    Route::middleware(['service.enabled:ADMINISTRASI_SURAT'])
        ->group(function () {

            Route::prefix('spd')->name('spd.')->group(function () {

                Route::apiResource(
                    'detail-perjalanan',
                    DetailPerjalananController::class
                );

                Route::patch(
                    'detail-perjalanan/{id}/status',
                    [
                        DetailPerjalananController::class,
                        'updateStatus'
                    ]
                );

                Route::apiResource(
                    'rekening',
                    RekeningController::class
                );

                Route::apiResource(
                    'pegawai',
                    PegawaiController::class
                );

                Route::apiResource(
                    'spd-peserta',
                    SpdPesertaController::class
                );

                Route::apiResource(
                    'alat-angkutan',
                    AlatAngkutanController::class
                );
            });

            Route::get(
                '/spd/stats',
                [SpdProposalController::class, 'stats']
            );

            Route::prefix('smartjabar')->group(function () {

                Route::get(
                    '/export',
                    [LaporanController::class, 'smartjabarExport']
                );

                Route::get(
                    '/joined-apps',
                    [JoinedAppController::class, 'index']
                );

                Route::get(
                    '/joined-apps/create',
                    [JoinedAppController::class, 'create']
                );

                Route::post(
                    '/joined-apps',
                    [JoinedAppController::class, 'store']
                );

                Route::get(
                    '/joined-apps/{id}/edit',
                    [JoinedAppController::class, 'edit']
                );

                Route::put(
                    '/joined-apps/{id}',
                    [JoinedAppController::class, 'update']
                );

                Route::delete(
                    '/joined-apps/{id}',
                    [JoinedAppController::class, 'destroy']
                );

                Route::get(
                    '/stats',
                    [UsageStatController::class, 'index']
                );

                Route::get(
                    '/stats/create',
                    [UsageStatController::class, 'create']
                );

                Route::post(
                    '/stats',
                    [UsageStatController::class, 'store']
                );

                Route::get(
                    '/stats/{id}/edit',
                    [UsageStatController::class, 'edit']
                );

                Route::put(
                    '/stats/{id}',
                    [UsageStatController::class, 'update']
                );

                Route::delete(
                    '/stats/{id}',
                    [UsageStatController::class, 'destroy']
                );
            });

            Route::prefix('spd')->group(function () {

                Route::get(
                    '/',
                    [SpdController::class, 'index']
                );

                Route::get(
                    '/{id}',
                    [SpdController::class, 'show']
                );

                Route::post(
                    '/',
                    [SpdController::class, 'store']
                );

                Route::put(
                    '/{id}',
                    [SpdController::class, 'update']
                );

                Route::delete(
                    '/{id}',
                    [SpdController::class, 'destroy']
                );

                Route::post(
                    '/laporan',
                    [SpdController::class, 'submitLaporan']
                );
            });
        });


    // ========================================================
    // LAYANAN: IKI REPORT
    // ========================================================

    Route::middleware(['service.enabled:IKI_REPORT'])
        ->group(function () {

            Route::middleware(['service.enabled:IKI_SADAJABAR'])->prefix('sadajabar')->group(function () {

                Route::get(
                    '/export',
                    [LaporanController::class, 'sadajabarExport']
                );

                Route::get(
                    '/integrasi',
                    [AppIntegrationController::class, 'index']
                );

                Route::get(
                    '/integrasi/create',
                    [AppIntegrationController::class, 'create']
                );

                Route::post(
                    '/integrasi',
                    [AppIntegrationController::class, 'store']
                );

                Route::get(
                    '/integrasi/{id}/edit',
                    [AppIntegrationController::class, 'edit']
                );

                Route::put(
                    '/integrasi/{id}',
                    [AppIntegrationController::class, 'update']
                );

                Route::delete(
                    '/integrasi/{id}',
                    [AppIntegrationController::class, 'destroy']
                );

                Route::get(
                    '/enkripsi',
                    [EncryptionStatController::class, 'index']
                );

                Route::get(
                    '/enkripsi/create',
                    [EncryptionStatController::class, 'create']
                );

                Route::post(
                    '/enkripsi',
                    [EncryptionStatController::class, 'store']
                );

                Route::get(
                    '/enkripsi/{id}/edit',
                    [EncryptionStatController::class, 'edit']
                );

                Route::put(
                    '/enkripsi/{id}',
                    [EncryptionStatController::class, 'update']
                );

                Route::delete(
                    '/enkripsi/{id}',
                    [EncryptionStatController::class, 'destroy']
                );
            });

            Route::prefix('rekayasa')->group(function () {

                Route::get(
                    '/export',
                    [LaporanController::class, 'rekayasaExport']
                );

                Route::get(
                    '/application-replications/summary',
                    [
                        ApplicationReplicationController::class,
                        'summary'
                    ]
                );

                Route::get(
                    '/application-replications',
                    [
                        ApplicationReplicationController::class,
                        'index'
                    ]
                );

                Route::get(
                    '/application-replications/create',
                    [
                        ApplicationReplicationController::class,
                        'create'
                    ]
                );

                Route::post(
                    '/application-replications',
                    [
                        ApplicationReplicationController::class,
                        'store'
                    ]
                );

                Route::get(
                    '/application-replications/{id}/edit',
                    [
                        ApplicationReplicationController::class,
                        'edit'
                    ]
                );

                Route::put(
                    '/application-replications/{id}',
                    [
                        ApplicationReplicationController::class,
                        'update'
                    ]
                );

                Route::delete(
                    '/application-replications/{id}',
                    [
                        ApplicationReplicationController::class,
                        'destroy'
                    ]
                );

                Route::get(
                    '/mentoring-performances',
                    [
                        MentoringPerformanceController::class,
                        'index'
                    ]
                );

                Route::get(
                    '/mentoring-performances/create',
                    [
                        MentoringPerformanceController::class,
                        'create'
                    ]
                );

                Route::post(
                    '/mentoring-performances',
                    [
                        MentoringPerformanceController::class,
                        'store'
                    ]
                );

                Route::get(
                    '/mentoring-performances/{id}/edit',
                    [
                        MentoringPerformanceController::class,
                        'edit'
                    ]
                );

                Route::put(
                    '/mentoring-performances/{id}',
                    [
                        MentoringPerformanceController::class,
                        'update'
                    ]
                );

                Route::delete(
                    '/mentoring-performances/{id}',
                    [
                        MentoringPerformanceController::class,
                        'destroy'
                    ]
                );
            });

            Route::prefix('intop')->group(function () {

                Route::get(
                    '/export',
                    [LaporanController::class, 'intopExport']
                );

                Route::get(
                    '/integration-summaries',
                    [IntegrationSummaryController::class, 'index']
                );

                Route::get(
                    '/integration-summaries/create',
                    [IntegrationSummaryController::class, 'create']
                );

                Route::post(
                    '/integration-summaries',
                    [IntegrationSummaryController::class, 'store']
                );

                Route::get(
                    '/integration-summaries/{id}/edit',
                    [IntegrationSummaryController::class, 'edit']
                );

                Route::put(
                    '/integration-summaries/{id}',
                    [IntegrationSummaryController::class, 'update']
                );

                Route::delete(
                    '/integration-summaries/{id}',
                    [IntegrationSummaryController::class, 'destroy']
                );

                Route::get(
                    '/service-catalogs',
                    [ServiceCatalogController::class, 'index']
                );

                Route::get(
                    '/service-catalogs/create',
                    [ServiceCatalogController::class, 'create']
                );

                Route::post(
                    '/service-catalogs',
                    [ServiceCatalogController::class, 'store']
                );

                Route::get(
                    '/service-catalogs/{id}/edit',
                    [ServiceCatalogController::class, 'edit']
                );

                Route::put(
                    '/service-catalogs/{id}',
                    [ServiceCatalogController::class, 'update']
                );

                Route::delete(
                    '/service-catalogs/{id}',
                    [ServiceCatalogController::class, 'destroy']
                );

                Route::get(
                    '/intop-mandate-service-summaries',
                    [
                        IntopMandateServiceSummaryController::class,
                        'index'
                    ]
                );

                Route::get(
                    '/intop-mandate-service-summaries/create',
                    [
                        IntopMandateServiceSummaryController::class,
                        'create'
                    ]
                );

                Route::post(
                    '/intop-mandate-service-summaries',
                    [
                        IntopMandateServiceSummaryController::class,
                        'store'
                    ]
                );

                Route::get(
                    '/intop-mandate-service-summaries/{id}/edit',
                    [
                        IntopMandateServiceSummaryController::class,
                        'edit'
                    ]
                );

                Route::put(
                    '/intop-mandate-service-summaries/{id}',
                    [
                        IntopMandateServiceSummaryController::class,
                        'update'
                    ]
                );

                Route::delete(
                    '/intop-mandate-service-summaries/{id}',
                    [
                        IntopMandateServiceSummaryController::class,
                        'destroy'
                    ]
                );
            });

            Route::prefix('sidebar')->group(function () {

                Route::get(
                    '/export',
                    [LaporanController::class, 'sidebarExport']
                );

                Route::get(
                    '/document-stats',
                    [DocumentStatController::class, 'index']
                );

                Route::get(
                    '/document-stats/create',
                    [DocumentStatController::class, 'create']
                );

                Route::post(
                    '/document-stats',
                    [DocumentStatController::class, 'store']
                );

                Route::get(
                    '/document-stats/{id}/edit',
                    [DocumentStatController::class, 'edit']
                );

                Route::put(
                    '/document-stats/{id}',
                    [DocumentStatController::class, 'update']
                );

                Route::delete(
                    '/document-stats/{id}',
                    [DocumentStatController::class, 'destroy']
                );

                Route::get(
                    '/metrics',
                    [MetricController::class, 'index']
                );

                Route::get(
                    '/metrics/create',
                    [MetricController::class, 'create']
                );

                Route::post(
                    '/metrics',
                    [MetricController::class, 'store']
                );

                Route::get(
                    '/metrics/{id}/edit',
                    [MetricController::class, 'edit']
                );

                Route::put(
                    '/metrics/{id}',
                    [MetricController::class, 'update']
                );

                Route::delete(
                    '/metrics/{id}',
                    [MetricController::class, 'destroy']
                );

                Route::get(
                    '/opd-usages',
                    [OpdUsageController::class, 'index']
                );

                Route::get(
                    '/opd-usages/create',
                    [OpdUsageController::class, 'create']
                );

                Route::post(
                    '/opd-usages',
                    [OpdUsageController::class, 'store']
                );

                Route::get(
                    '/opd-usages/{id}/edit',
                    [OpdUsageController::class, 'edit']
                );

                Route::put(
                    '/opd-usages/{id}',
                    [OpdUsageController::class, 'update']
                );

                Route::delete(
                    '/opd-usages/{id}',
                    [OpdUsageController::class, 'destroy']
                );
            });

            Route::prefix('appman')->group(function () {

                Route::get(
                    '/export',
                    [LaporanController::class, 'appmanExport']
                );

                // Kerentanan pada aplikasi Pemprov Jabar
                Route::get(
                    '/app-vulnerabilities',
                    [AppVulnerabilityController::class, 'index']
                );

                Route::get(
                    '/app-vulnerabilities/create',
                    [AppVulnerabilityController::class, 'create']
                );

                Route::post(
                    '/app-vulnerabilities',
                    [AppVulnerabilityController::class, 'store']
                );

                Route::get(
                    '/app-vulnerabilities/{id}/edit',
                    [AppVulnerabilityController::class, 'edit']
                );

                Route::put(
                    '/app-vulnerabilities/{id}',
                    [AppVulnerabilityController::class, 'update']
                );

                Route::delete(
                    '/app-vulnerabilities/{id}',
                    [AppVulnerabilityController::class, 'destroy']
                );

                // Aplikasi / layanan yang menjadi target pengembangan
                Route::get(
                    '/development-targets',
                    [DevelopmentTargetController::class, 'index']
                );

                Route::get(
                    '/development-targets/create',
                    [DevelopmentTargetController::class, 'create']
                );

                Route::post(
                    '/development-targets',
                    [DevelopmentTargetController::class, 'store']
                );

                Route::get(
                    '/development-targets/{id}/edit',
                    [DevelopmentTargetController::class, 'edit']
                );

                Route::put(
                    '/development-targets/{id}',
                    [DevelopmentTargetController::class, 'update']
                );

                Route::delete(
                    '/development-targets/{id}',
                    [DevelopmentTargetController::class, 'destroy']
                );

                // Layanan Drive Jabar
                Route::get(
                    '/drive-jabar-stats',
                    [DriveJabarStatController::class, 'index']
                );

                Route::get(
                    '/drive-jabar-stats/create',
                    [DriveJabarStatController::class, 'create']
                );

                Route::post(
                    '/drive-jabar-stats',
                    [DriveJabarStatController::class, 'store']
                );

                Route::get(
                    '/drive-jabar-stats/{id}/edit',
                    [DriveJabarStatController::class, 'edit']
                );

                Route::put(
                    '/drive-jabar-stats/{id}',
                    [DriveJabarStatController::class, 'update']
                );

                Route::delete(
                    '/drive-jabar-stats/{id}',
                    [DriveJabarStatController::class, 'destroy']
                );

                // Layanan Pengelolaan Email
                Route::get(
                    '/email-management-stats',
                    [EmailManagementStatController::class, 'index']
                );

                Route::get(
                    '/email-management-stats/create',
                    [EmailManagementStatController::class, 'create']
                );

                Route::post(
                    '/email-management-stats',
                    [EmailManagementStatController::class, 'store']
                );

                Route::get(
                    '/email-management-stats/{id}/edit',
                    [EmailManagementStatController::class, 'edit']
                );

                Route::put(
                    '/email-management-stats/{id}',
                    [EmailManagementStatController::class, 'update']
                );

                Route::delete(
                    '/email-management-stats/{id}',
                    [EmailManagementStatController::class, 'destroy']
                );

                // Pemetaan integrasi aplikasi
                Route::get(
                    '/integration-mappings',
                    [IntegrationMappingController::class, 'index']
                );

                Route::get(
                    '/integration-mappings/create',
                    [IntegrationMappingController::class, 'create']
                );

                Route::post(
                    '/integration-mappings',
                    [IntegrationMappingController::class, 'store']
                );

                Route::get(
                    '/integration-mappings/{id}/edit',
                    [IntegrationMappingController::class, 'edit']
                );

                Route::put(
                    '/integration-mappings/{id}',
                    [IntegrationMappingController::class, 'update']
                );

                Route::delete(
                    '/integration-mappings/{id}',
                    [IntegrationMappingController::class, 'destroy']
                );

                // Pendataan aplikasi 2026
                Route::get(
                    '/inventory-stats',
                    [InventoryStatController::class, 'index']
                );

                Route::get(
                    '/inventory-stats/create',
                    [InventoryStatController::class, 'create']
                );

                Route::post(
                    '/inventory-stats',
                    [InventoryStatController::class, 'store']
                );

                Route::get(
                    '/inventory-stats/{id}/edit',
                    [InventoryStatController::class, 'edit']
                );

                Route::put(
                    '/inventory-stats/{id}',
                    [InventoryStatController::class, 'update']
                );

                Route::delete(
                    '/inventory-stats/{id}',
                    [InventoryStatController::class, 'destroy']
                );

                // Katalaps Kabupaten Kota
                Route::get(
                    '/katalaps-regencies',
                    [KatalapsRegencyController::class, 'index']
                );

                Route::get(
                    '/katalaps-regencies/create',
                    [KatalapsRegencyController::class, 'create']
                );

                Route::post(
                    '/katalaps-regencies',
                    [KatalapsRegencyController::class, 'store']
                );

                Route::get(
                    '/katalaps-regencies/{id}/edit',
                    [KatalapsRegencyController::class, 'edit']
                );

                Route::put(
                    '/katalaps-regencies/{id}',
                    [KatalapsRegencyController::class, 'update']
                );

                Route::delete(
                    '/katalaps-regencies/{id}',
                    [KatalapsRegencyController::class, 'destroy']
                );

                // Fasilitasi dukungan tim pada pengembangan aplikasi
                Route::get(
                    '/team-support-facilities',
                    [TeamSupportFacilityController::class, 'index']
                );

                Route::get(
                    '/team-support-facilities/create',
                    [TeamSupportFacilityController::class, 'create']
                );

                Route::post(
                    '/team-support-facilities',
                    [TeamSupportFacilityController::class, 'store']
                );

                Route::get(
                    '/team-support-facilities/{id}/edit',
                    [TeamSupportFacilityController::class, 'edit']
                );

                Route::put(
                    '/team-support-facilities/{id}',
                    [TeamSupportFacilityController::class, 'update']
                );

                Route::delete(
                    '/team-support-facilities/{id}',
                    [TeamSupportFacilityController::class, 'destroy']
                );
            });
        });


    // ========================================================
    // LAYANAN: MANAJEMEN TUGAS DIGITAL
    // ========================================================

    Route::middleware(['service.enabled:MANAJEMEN_TUGAS'])
        ->group(function () {

            Route::prefix('task-management')->group(function () {

                Route::get(
                    '/boards',
                    [BoardController::class, 'index']
                );

                Route::post(
                    '/boards',
                    [BoardController::class, 'store']
                );

                Route::get(
                    '/boards/{id}',
                    [BoardController::class, 'show']
                );

                Route::put(
                    '/boards/{id}',
                    [BoardController::class, 'update']
                );

                Route::delete(
                    '/boards/{id}',
                    [BoardController::class, 'destroy']
                );

                Route::post(
                    '/boards/{boardId}/members/join',
                    [BoardMemberController::class, 'join']
                );

                Route::get(
                    '/boards/{boardId}/members',
                    [BoardMemberController::class, 'members']
                );

                Route::get(
                    '/boards/{boardId}/join-requests',
                    [BoardMemberController::class, 'joinRequests']
                );

                Route::post(
                    '/boards/{boardId}/members/{userId}/approve',
                    [BoardMemberController::class, 'approve']
                );

                Route::post(
                    '/boards/{boardId}/members/{userId}/reject',
                    [BoardMemberController::class, 'reject']
                );

                Route::patch(
                    '/boards/{boardId}/members/{userId}/permission',
                    [BoardMemberController::class, 'updatePermission']
                );

                Route::delete(
                    '/boards/{boardId}/members/leave',
                    [BoardMemberController::class, 'leave']
                );

                Route::get(
                    '/tasks',
                    [TaskController::class, 'index']
                );

                Route::post(
                    '/tasks',
                    [TaskController::class, 'store']
                );

                Route::get(
                    '/tasks/my',
                    [TaskController::class, 'myTasks']
                );

                Route::get(
                    '/tasks/{id}',
                    [TaskController::class, 'show']
                );

                Route::put(
                    '/tasks/{id}',
                    [TaskController::class, 'update']
                );

                Route::patch(
                    '/tasks/{id}/status',
                    [TaskController::class, 'updateStatus']
                );

                Route::patch(
                    '/tasks/{id}/approve',
                    [TaskController::class, 'approve']
                );

                Route::delete(
                    '/tasks/{id}',
                    [TaskController::class, 'destroy']
                );

                Route::get(
                    '/task-comments',
                    [TaskCommentController::class, 'index']
                );

                Route::post(
                    '/task-comments',
                    [TaskCommentController::class, 'store']
                );

                Route::delete(
                    '/task-comments/{id}',
                    [TaskCommentController::class, 'destroy']
                );

                Route::get(
                    '/task-attachments',
                    [TaskAttachmentController::class, 'index']
                );

                Route::post(
                    '/task-attachments',
                    [TaskAttachmentController::class, 'store']
                );

                Route::delete(
                    '/task-attachments/{id}',
                    [TaskAttachmentController::class, 'destroy']
                );

                Route::get(
                    '/task-activities',
                    [TaskActivityController::class, 'index']
                );

                Route::get(
                    '/dashboard',
                    [DashboardController::class, 'index']
                );

                Route::get(
                    '/my-tasks',
                    [MyTaskController::class, 'index']
                );

                // Notifications
                // Urutan penting: route khusus sebelum /{id}
                Route::get(
                    '/notifications',
                    [NotificationController::class, 'index']
                );

                Route::get(
                    '/notifications/unread-count',
                    [NotificationController::class, 'unreadCount']
                );

                Route::patch(
                    '/notifications/read-all',
                    [NotificationController::class, 'markAllAsRead']
                );

                Route::patch(
                    '/notifications/{id}/read',
                    [NotificationController::class, 'markAsRead']
                );

                Route::delete(
                    '/notifications/{id}',
                    [NotificationController::class, 'destroy']
                );
            });
        });


    // ========================================================
    // LAYANAN: MAGANG
    // ========================================================

    Route::middleware(['service.enabled:MAGANG'])
        ->group(function () {

            Route::post(
                'magang/{id}/upload-nda',
                [MagangController::class, 'uploadNda']
            );

            Route::apiResource(
                'magang',
                MagangController::class
            )->except(['store']);
        });


    // ========================================================
    // LAYANAN: ADMINISTRASI SURAT - BAGIAN 2
    // ========================================================

    Route::middleware(['service.enabled:ADMINISTRASI_SURAT'])
        ->group(function () {

            // Nota Dinas
            Route::apiResource(
                'nota-dinas',
                NotaDinasController::class
            );

            Route::get(
                'nota-dinas-export',
                [NotaDinasController::class, 'export']
            );

            // Hasil Pentest
            Route::apiResource(
                'hasil-pentest',
                \App\Http\Controllers\HasilPentestController::class
            );

            Route::get(
                'hasil-pentest-export',
                [\App\Http\Controllers\HasilPentestController::class, 'export']
            );

            // Kerentanan
            Route::apiResource(
                'kerentanan',
                \App\Http\Controllers\KerentananController::class
            );

            Route::get(
                'kerentanan-export',
                [\App\Http\Controllers\KerentananController::class, 'export']
            );
        });

    // ========================================================
    // LAYANAN: SMKI - MANAJEMEN DAFTAR SOFTWARE STANDAR & ASET TI
    // ========================================================

    Route::middleware(['service.enabled:SMKI'])
        ->prefix('smki')
        ->group(function () {
            Route::get('software-standar/lookup', [\App\Http\Controllers\Smki\SmkiSoftwareStandarController::class, 'lookup']);
            Route::get('software-standar/export-docx', [\App\Http\Controllers\Smki\SmkiSoftwareStandarController::class, 'exportDocx']);
            Route::apiResource('software-standar', \App\Http\Controllers\Smki\SmkiSoftwareStandarController::class);

            // Daftar Rekaman (Formulir FR-003)
            Route::get('daftar-rekaman/lookup', [\App\Http\Controllers\Smki\SmkiDaftarRekamanController::class, 'lookup'])
                ->name('smki.daftar-rekaman.lookup');
            Route::get('daftar-rekaman/export-docx', [\App\Http\Controllers\Smki\SmkiDaftarRekamanController::class, 'exportDocx'])
                ->name('smki.daftar-rekaman.export-docx');
            Route::apiResource('daftar-rekaman', \App\Http\Controllers\Smki\SmkiDaftarRekamanController::class)
                ->names([
                    'index'   => 'smki.daftar-rekaman.index',
                    'store'   => 'smki.daftar-rekaman.store',
                    'show'    => 'smki.daftar-rekaman.show',
                    'update'  => 'smki.daftar-rekaman.update',
                    'destroy' => 'smki.daftar-rekaman.destroy',
                ]);

            // Daftar Penyedia Barang/Jasa (Formulir FR-020)
            Route::get('daftar-penyedia/lookup', [\App\Http\Controllers\Smki\SmkiPenyediaBarangJasaController::class, 'lookup'])
                ->name('smki.daftar-penyedia.lookup');
            Route::get('daftar-penyedia/export-docx', [\App\Http\Controllers\Smki\SmkiPenyediaBarangJasaController::class, 'exportDocx'])
                ->name('smki.daftar-penyedia.export-docx');
            Route::apiResource('daftar-penyedia', \App\Http\Controllers\Smki\SmkiPenyediaBarangJasaController::class)
                ->names([
                    'index'   => 'smki.daftar-penyedia.index',
                    'store'   => 'smki.daftar-penyedia.store',
                    'show'    => 'smki.daftar-penyedia.show',
                    'update'  => 'smki.daftar-penyedia.update',
                    'destroy' => 'smki.daftar-penyedia.destroy',
                ]);

            // Berita Acara Penghancuran Media (FR014-SMKI)
            Route::get('berita-acara/lookup', [\App\Http\Controllers\Smki\SmkiBeritaAcaraController::class, 'lookup'])
                ->name('smki.berita-acara.lookup');
            Route::get('berita-acara/export-docx', [\App\Http\Controllers\Smki\SmkiBeritaAcaraController::class, 'exportDocx'])
                ->name('smki.berita-acara.export-docx-all');
            Route::get('berita-acara/{id}/export-docx', [\App\Http\Controllers\Smki\SmkiBeritaAcaraController::class, 'exportDocx'])
                ->name('smki.berita-acara.export-docx-single');
            Route::apiResource('berita-acara', \App\Http\Controllers\Smki\SmkiBeritaAcaraController::class)
                ->names([
                    'index'   => 'smki.berita-acara.index',
                    'store'   => 'smki.berita-acara.store',
                    'show'    => 'smki.berita-acara.show',
                    'update'  => 'smki.berita-acara.update',
                    'destroy' => 'smki.berita-acara.destroy',
                ]);
            // Daftar Aset TI (dalam prefix smki)
            Route::get('daftar-aset-ti/lookup', [\App\Http\Controllers\DaftarAsetTiController::class, 'lookup'])
                ->name('smki.daftar-aset-ti.lookup');
            Route::apiResource('daftar-aset-ti', \App\Http\Controllers\DaftarAsetTiController::class)
                ->names([
                    'index'   => 'smki.daftar-aset-ti.index',
                    'store'   => 'smki.daftar-aset-ti.store',
                    'show'    => 'smki.daftar-aset-ti.show',
                    'update'  => 'smki.daftar-aset-ti.update',
                    'destroy' => 'smki.daftar-aset-ti.destroy',
                ]);

            // Formulir Hardening Pengecekan Aset (FR-047)
            Route::get('formulir-hardening/lookup', [\App\Http\Controllers\Smki\SmkiFormulirHardeningController::class, 'lookup'])
                ->name('smki.formulir-hardening.lookup');
            Route::get('formulir-hardening/{id}/export-docx', [\App\Http\Controllers\Smki\SmkiFormulirHardeningController::class, 'exportDocx'])
                ->name('smki.formulir-hardening.export-docx');
            Route::apiResource('formulir-hardening', \App\Http\Controllers\Smki\SmkiFormulirHardeningController::class)
                ->names([
                    'index'   => 'smki.formulir-hardening.index',
                    'store'   => 'smki.formulir-hardening.store',
                    'show'    => 'smki.formulir-hardening.show',
                    'update'  => 'smki.formulir-hardening.update',
                    'destroy' => 'smki.formulir-hardening.destroy',
                ]);

            // Formulir Rencana Audit (F05-SMKI)
            Route::get('rencana-audit/lookup', [\App\Http\Controllers\Smki\SmkiRencanaAuditController::class, 'lookup'])
                ->name('smki.rencana-audit.lookup');
            Route::apiResource('rencana-audit', \App\Http\Controllers\Smki\SmkiRencanaAuditController::class)
                ->names([
                    'index'   => 'smki.rencana-audit.index',
                    'store'   => 'smki.rencana-audit.store',
                    'show'    => 'smki.rencana-audit.show',
                    'update'  => 'smki.rencana-audit.update',
                    'destroy' => 'smki.rencana-audit.destroy',
                ]);

            // Laporan Audit Internal SMKI (FR-006)
            Route::get('laporan-audit/lookup', [\App\Http\Controllers\Smki\SmkiLaporanAuditController::class, 'lookup'])
                ->name('smki.laporan-audit.lookup');
            Route::get('laporan-audit/{id}/export-docx', [\App\Http\Controllers\Smki\SmkiLaporanAuditController::class, 'exportDocx'])
                ->name('smki.laporan-audit.export-docx');
            Route::apiResource('laporan-audit', \App\Http\Controllers\Smki\SmkiLaporanAuditController::class)
                ->names([
                    'index'   => 'smki.laporan-audit.index',
                    'store'   => 'smki.laporan-audit.store',
                    'show'    => 'smki.laporan-audit.show',
                    'update'  => 'smki.laporan-audit.update',
                    'destroy' => 'smki.laporan-audit.destroy',
                ]);

            // Formulir Hak Akses TI (FR-018)
            Route::get('hak-akses-ti/lookup', [\App\Http\Controllers\Smki\HakAksesTiController::class, 'lookup'])
                ->name('smki.hak-akses-ti.lookup');
            Route::get('hak-akses-ti/export-docx', [\App\Http\Controllers\Smki\HakAksesTiController::class, 'exportDocx'])
                ->name('smki.hak-akses-ti.export-docx');
            Route::apiResource('hak-akses-ti', \App\Http\Controllers\Smki\HakAksesTiController::class)
                ->names([
                    'index'   => 'smki.hak-akses-ti.index',
                    'store'   => 'smki.hak-akses-ti.store',
                    'show'    => 'smki.hak-akses-ti.show',
                    'update'  => 'smki.hak-akses-ti.update',
                    'destroy' => 'smki.hak-akses-ti.destroy',
                ]);

            // Pelaporan Insiden Keamanan Informasi (FR-016)
            Route::get('pelaporan-insiden/lookup', [\App\Http\Controllers\Smki\SmkiInsidenReportController::class, 'lookup'])
                ->name('smki.pelaporan-insiden.lookup');
            Route::get('pelaporan-insiden/export-docx', [\App\Http\Controllers\Smki\SmkiInsidenReportController::class, 'exportDocx'])
                ->name('smki.pelaporan-insiden.export-docx-all');
            Route::get('pelaporan-insiden/{id}/export-docx', [\App\Http\Controllers\Smki\SmkiInsidenReportController::class, 'exportDocx'])
                ->name('smki.pelaporan-insiden.export-docx-single');
            Route::get('pelaporan-insiden/{id}/unduh', [\App\Http\Controllers\Smki\SmkiInsidenReportController::class, 'downloadBukti'])
                ->name('smki.pelaporan-insiden.download');
            Route::apiResource('pelaporan-insiden', \App\Http\Controllers\Smki\SmkiInsidenReportController::class)
                ->names([
                    'index'   => 'smki.pelaporan-insiden.index',
                    'store'   => 'smki.pelaporan-insiden.store',
                    'show'    => 'smki.pelaporan-insiden.show',
                    'update'  => 'smki.pelaporan-insiden.update',
                    'destroy' => 'smki.pelaporan-insiden.destroy',
                ]);
        });

    // ========================================================
    // LAYANAN: INVENTARISASI DAFTAR ASET TI (BIDANG APTIKA)
    // Route standalone (tanpa prefix smki) - memiliki nama default apiResource
    // ========================================================
    Route::get('daftar-aset-ti/lookup', [\App\Http\Controllers\DaftarAsetTiController::class, 'lookup'])
        ->name('daftar-aset-ti.lookup');
    Route::get('daftar-aset-ti/export-excel', [\App\Http\Controllers\DaftarAsetTiController::class, 'exportExcel'])
        ->name('daftar-aset-ti.export-excel');
    Route::apiResource('daftar-aset-ti', \App\Http\Controllers\DaftarAsetTiController::class);
});


