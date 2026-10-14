<?php

// Routes for posting FRC fee payments. Loaded inside the authenticated, active-user group of routes/web.php.

use App\Http\Controllers\FeePosting\FeeEnrollmentRecognitionController;
use App\Http\Controllers\FeePosting\FeePaymentPostingController;
use App\Http\Controllers\FeePosting\FeePostingController;
use App\Http\Controllers\FeePosting\FeeSettlementMappingController;
use Illuminate\Support\Facades\Route;

Route::get('/fee-settlement-mappings', [FeeSettlementMappingController::class, 'index'])->middleware('permission:frc_integration.view|fee_settlement.manage')->name('fee-settlement-mappings.index');
Route::put('/fee-settlement-mappings', [FeeSettlementMappingController::class, 'update'])->middleware('permission:fee_settlement.manage')->name('fee-settlement-mappings.update');

// Previews are read-only; posting needs both FRC posting and journal posting permissions.
Route::get('/fee-posting/payments', [FeePaymentPostingController::class, 'index'])->middleware('permission:frc_integration.view')->name('fee-posting.payments.index');
Route::post('/fee-posting/payments', [FeePaymentPostingController::class, 'post'])->middleware(['permission:frc_integration.post', 'permission:journals.post'])->name('fee-posting.payments.post');
Route::get('/fee-posting/enrollments', [FeeEnrollmentRecognitionController::class, 'index'])->middleware('permission:frc_integration.view')->name('fee-posting.enrollments.index');
Route::post('/fee-posting/enrollments', [FeeEnrollmentRecognitionController::class, 'post'])->middleware(['permission:frc_integration.post', 'permission:journals.post'])->name('fee-posting.enrollments.post');

Route::get('/fee-posting/postings', [FeePostingController::class, 'index'])->middleware('permission:frc_integration.view')->name('fee-posting.postings.index');
Route::post('/fee-posting/postings/{posting}/reverse', [FeePostingController::class, 'reverse'])->middleware(['permission:frc_integration.reverse', 'permission:journals.reverse'])->name('fee-posting.postings.reverse');
