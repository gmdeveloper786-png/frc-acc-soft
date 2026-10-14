<?php

// Routes for the assets module. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\Assets\AssetCategoryController;
use App\Http\Controllers\Assets\DepreciationRunController;
use App\Http\Controllers\Assets\FixedAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/asset-categories', [AssetCategoryController::class, 'index'])->middleware('permission:asset_categories.view')->name('asset-categories.index');
Route::get('/asset-categories/create', [AssetCategoryController::class, 'create'])->middleware('permission:asset_categories.manage')->name('asset-categories.create');
Route::post('/asset-categories', [AssetCategoryController::class, 'store'])->middleware('permission:asset_categories.manage')->name('asset-categories.store');
Route::get('/asset-categories/{assetCategory}/edit', [AssetCategoryController::class, 'edit'])->middleware('permission:asset_categories.manage')->name('asset-categories.edit');
Route::put('/asset-categories/{assetCategory}', [AssetCategoryController::class, 'update'])->middleware('permission:asset_categories.manage')->name('asset-categories.update');

Route::get('/fixed-assets', [FixedAssetController::class, 'index'])->middleware('permission:fixed_assets.view')->name('fixed-assets.index');
Route::get('/fixed-assets/register', [FixedAssetController::class, 'register'])->middleware('permission:fixed_assets.view')->name('fixed-assets.register');
Route::get('/fixed-assets/create', [FixedAssetController::class, 'create'])->middleware('permission:fixed_assets.create')->name('fixed-assets.create');
Route::post('/fixed-assets', [FixedAssetController::class, 'store'])->middleware('permission:fixed_assets.create')->name('fixed-assets.store');
Route::get('/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])->middleware('permission:fixed_assets.view')->name('fixed-assets.show');
Route::get('/fixed-assets/{fixedAsset}/edit', [FixedAssetController::class, 'edit'])->middleware('permission:fixed_assets.update')->name('fixed-assets.edit');
Route::put('/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'update'])->middleware('permission:fixed_assets.update')->name('fixed-assets.update');
Route::post('/fixed-assets/{fixedAsset}/activate', [FixedAssetController::class, 'activate'])->middleware('permission:fixed_assets.update')->name('fixed-assets.activate');
Route::post('/fixed-assets/{fixedAsset}/retire', [FixedAssetController::class, 'retire'])->middleware('permission:fixed_assets.retire')->name('fixed-assets.retire');
Route::post('/fixed-assets/{fixedAsset}/documents', [FixedAssetController::class, 'storeDocument'])->middleware('permission:fixed_assets.update')->name('fixed-assets.documents.store');
Route::get('/fixed-assets/{fixedAsset}/documents/{document}', [FixedAssetController::class, 'downloadDocument'])->middleware('permission:fixed_assets.view')->name('fixed-assets.documents.download');

Route::get('/depreciation-runs', [DepreciationRunController::class, 'index'])->middleware('permission:depreciation.view')->name('depreciation-runs.index');
Route::get('/depreciation-runs/create', [DepreciationRunController::class, 'create'])->middleware('permission:depreciation.prepare')->name('depreciation-runs.create');
Route::post('/depreciation-runs', [DepreciationRunController::class, 'store'])->middleware('permission:depreciation.prepare')->name('depreciation-runs.store');
Route::get('/depreciation-runs/{depreciationRun}', [DepreciationRunController::class, 'show'])->middleware('permission:depreciation.view')->name('depreciation-runs.show');
Route::post('/depreciation-runs/{depreciationRun}/submit', [DepreciationRunController::class, 'submit'])->middleware('permission:depreciation.prepare')->name('depreciation-runs.submit');
Route::post('/depreciation-runs/{depreciationRun}/approve', [DepreciationRunController::class, 'approve'])->middleware('permission:depreciation.approve')->name('depreciation-runs.approve');
Route::post('/depreciation-runs/{depreciationRun}/reject', [DepreciationRunController::class, 'reject'])->middleware('permission:depreciation.approve')->name('depreciation-runs.reject');
Route::post('/depreciation-runs/{depreciationRun}/post', [DepreciationRunController::class, 'post'])->middleware('permission:depreciation.post')->name('depreciation-runs.post');
Route::post('/depreciation-runs/{depreciationRun}/reverse', [DepreciationRunController::class, 'reverse'])->middleware('permission:depreciation.reverse')->name('depreciation-runs.reverse');
Route::post('/depreciation-runs/{depreciationRun}/discard', [DepreciationRunController::class, 'discard'])->middleware('permission:depreciation.prepare')->name('depreciation-runs.discard');
