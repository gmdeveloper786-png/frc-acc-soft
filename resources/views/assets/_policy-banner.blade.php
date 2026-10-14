@if(! $policyApproved)
<div class="alert alert-warning" role="alert">
    <strong>Depreciation policy not approved.</strong>
    Assets can be registered and depreciation can be drafted and previewed, but <strong>posting depreciation is blocked</strong> until a Director approves the setting <code>assets.depreciation_policy_approved</code> (Depreciation Policy Approved). Figures shown before approval are previews only.
</div>
@endif
