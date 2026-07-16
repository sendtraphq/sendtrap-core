<?php

namespace Sendtrap\Core\Contracts;

/**
 * Resolves the entitlements (feature availability and numeric limits) for a
 * given workspace.
 *
 * Community reads optional local configuration; unset limits mean
 * unlimited. Cloud uses the Team's Stripe subscription and SaaS plan
 * catalogue. Core services depend on this contract rather than importing
 * Cashier or `TeamPlan` directly.
 */
interface Entitlements
{
    /**
     * The entitlements that apply to the given workspace.
     */
    public function for(Workspace $workspace): WorkspaceEntitlements;
}
