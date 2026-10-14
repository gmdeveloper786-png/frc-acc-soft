@if(!$policyApproved)
<div class="alert alert-warning" role="alert">
    <strong>Payroll calculation policy not approved.</strong>
    Employees, components, imports, validation, submission and approval work as drafts, but the salary accrual cannot be posted until the Director approves the
    “Payroll Calculation Policy Approved” setting (<code>payroll.calculation_policy_approved</code>). Tax, leave and statutory amounts are entered or imported; the system does not calculate them.
</div>
@endif
