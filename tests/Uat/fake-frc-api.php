<?php

/*
 * SYNTHETIC stand-in for the FRC Management Software accounting API, used only
 * for isolated UAT. It implements the proposed contract in
 * docs/FRC_FEE_INTEGRATION_DESIGN.md with invented records (no real data).
 *
 *   php -S 127.0.0.1:8011 tests/Uat/fake-frc-api.php
 *
 * The data set mirrors the shape of the historical Finance Report screenshot:
 * 7 paid payments totalling PKR 18,000 (11,000 cash, 7,000 card/bank/wallet),
 * plus one pending-verification and one rejected payment, and one payment of a
 * cancelled enrollment that the report excludes.
 */

const TOKEN = 'uat-synthetic-token';

header('Content-Type: application/json');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer '.TOKEN) {
    http_response_code(401);
    echo json_encode(['message' => 'Unauthenticated.']);

    return;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$stamp = fn (int $minute): string => sprintf('2026-10-01T09:%02d:00+05:00', $minute);

$branches = [['id' => 990001, 'name' => 'UAT Branch A', 'city' => 'Lahore', 'status' => 'publish'], ['id' => 990002, 'name' => 'UAT Branch B', 'city' => 'Karachi', 'status' => 'publish']];
$services = [['id' => 990101, 'name' => 'UAT Speech Therapy', 'status' => 'publish'], ['id' => 990102, 'name' => 'UAT School Readiness Program', 'status' => 'publish']];

$enrollments = [
    ['id' => 501, 'branch_id' => 990001, 'service_id' => 990101, 'status' => 'active', 'payment_status' => 'fully_paid', 'final_total' => '9000.00', 'discount_amount' => '0.00', 'paid_amount' => '9000.00', 'remaining_amount' => '0.00', 'approved_at' => '2026-10-01T08:00:00+05:00', 'created_at' => $stamp(1), 'updated_at' => $stamp(1), 'deleted_at' => null],
    ['id' => 502, 'branch_id' => 990001, 'service_id' => 990102, 'status' => 'approved', 'payment_status' => 'fully_paid', 'final_total' => '5000.00', 'discount_amount' => '500.00', 'paid_amount' => '5000.00', 'remaining_amount' => '0.00', 'approved_at' => '2026-10-01T08:00:00+05:00', 'created_at' => $stamp(2), 'updated_at' => $stamp(2), 'deleted_at' => null],
    ['id' => 503, 'branch_id' => 990002, 'service_id' => 990101, 'status' => 'completed', 'payment_status' => 'partial_paid', 'final_total' => '6000.00', 'discount_amount' => '0.00', 'paid_amount' => '4000.00', 'remaining_amount' => '2000.00', 'approved_at' => '2026-10-01T08:00:00+05:00', 'created_at' => $stamp(3), 'updated_at' => $stamp(3), 'deleted_at' => null],
    ['id' => 504, 'branch_id' => 990002, 'service_id' => 990102, 'status' => 'cancelled', 'payment_status' => 'partial_paid', 'final_total' => '3000.00', 'discount_amount' => '0.00', 'paid_amount' => '500.00', 'remaining_amount' => '2500.00', 'approved_at' => null, 'created_at' => $stamp(4), 'updated_at' => $stamp(4), 'deleted_at' => null],
];
$enrollmentRef = fn (int $id): array => (function () use ($id, $enrollments): array {
    foreach ($enrollments as $row) {
        if ($row['id'] === $id) {
            return ['id' => $row['id'], 'branch_id' => $row['branch_id'], 'service_id' => $row['service_id'], 'status' => $row['status'], 'updated_at' => $row['updated_at']];
        }
    }

    return [];
})();
$payment = fn (int $id, ?string $receipt, int $enrollment, string $amount, string $method, string $status, string $date, int $minute): array => [
    'id' => $id, 'receipt_number' => $receipt, 'enrollment_id' => $enrollment, 'amount' => $amount, 'payment_method' => $method, 'status' => $status,
    'payment_date' => $date, 'verified_at' => $status === 'pending_verification' ? null : $date.'T12:00:00+05:00',
    'created_at' => $stamp($minute), 'updated_at' => $stamp($minute), 'deleted_at' => null, 'enrollment' => $enrollmentRef($enrollment),
];
$payments = [
    $payment(9001, 'UAT-000001', 501, '3000.00', 'cash', 'paid', '2026-10-02', 10),
    $payment(9002, 'UAT-000002', 501, '3000.00', 'cash', 'paid', '2026-10-03', 11),
    $payment(9003, 'UAT-000003', 501, '3000.00', 'card', 'paid', '2026-10-04', 12),
    $payment(9004, 'UAT-000004', 502, '4000.00', 'cash', 'paid', '2026-10-05', 13),
    $payment(9005, 'UAT-000005', 502, '1000.00', 'cash', 'paid', '2026-10-05', 14),
    $payment(9006, 'UAT-000006', 503, '2000.00', 'bank_transfer', 'paid', '2026-10-06', 15),
    $payment(9007, 'UAT-000007', 503, '2000.00', 'easypaisa', 'paid', '2026-10-07', 16),
    $payment(9008, null, 503, '1500.00', 'jazzcash', 'pending_verification', '2026-10-09', 17),
    $payment(9009, null, 501, '1000.00', 'cash', 'rejected', '2026-10-08', 18),
    $payment(9010, 'UAT-000008', 504, '500.00', 'cash', 'paid', '2026-10-10', 19),
];

/** Cursor pagination over (updated_at, id), per the proposed contract. */
$page = function (array $rows): array {
    $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));
    $since = $_GET['updated_since'] ?? null;
    $afterId = (int) ($_GET['after_id'] ?? 0);
    usort($rows, fn ($a, $b) => [$a['updated_at'], $a['id']] <=> [$b['updated_at'], $b['id']]);
    if ($since !== null) {
        $sinceTs = strtotime($since);
        $rows = array_values(array_filter($rows, fn ($row) => strtotime($row['updated_at']) > $sinceTs || (strtotime($row['updated_at']) === $sinceTs && $row['id'] > $afterId)));
    }
    $slice = array_slice($rows, 0, $perPage);
    $last = end($slice) ?: null;

    return ['data' => $slice, 'meta' => ['per_page' => $perPage, 'has_more' => count($rows) > $perPage, 'next_updated_since' => $last['updated_at'] ?? null, 'next_after_id' => $last['id'] ?? null, 'server_time' => date(DATE_ATOM)]];
};

$response = match (true) {
    str_ends_with($path, '/integrations/accounting/v1/branches') => ['data' => $branches, 'meta' => ['current_page' => 1, 'last_page' => 1]],
    str_ends_with($path, '/integrations/accounting/v1/services') => ['data' => $services, 'meta' => ['current_page' => 1, 'last_page' => 1]],
    str_ends_with($path, '/integrations/accounting/v1/enrollments') => $page($enrollments),
    str_ends_with($path, '/integrations/accounting/v1/payments') => $page($payments),
    default => null,
};
if ($response === null) {
    http_response_code(404);
    echo json_encode(['message' => 'Not found.']);

    return;
}
echo json_encode($response);
