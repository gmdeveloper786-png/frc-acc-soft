# Client Change Request (CR) Register

**Project:** FRC Accounting Software  
**Target Repository:** `E:\wamp64\www\frc-accounting-software`  
**Latest Baseline Commit:** `31dfd1b`  
**Document Purpose:** Centralized, auditable log of all formal client-requested changes, enhancements, and policy adjustments beyond the original Milestones 1–8 baseline.

---

## 1. Change Request Management Protocol

Every future client request or functional modification must follow this lifecycle before being declared complete:

1. **Intake & Logging:** Assign a sequential `CR-2026-XXX` ID with request date and exact client wording.
2. **Impact & Phase Mapping:** Map the request to the affected software module and the original 15-Phase UAT Plan.
3. **Approval Gate:** Confirm stakeholder approval (Owner, Director, or Accountant) before code modifications.
4. **Implementation & Safety:** Apply minimal targeted code changes without violating core accounting rules (balanced journals, period locks, maker-checker separation, branch scoping).
5. **Automated QA:** Run focused Pest tests and full regression under SQLite `:memory:` isolation.
6. **Browser Retesting:** Perform manual verification on the dedicated UAT instance (`http://127.0.0.1:8010`).
7. **Git Commit Reference:** Record the exact Git commit SHA where the change was preserved.
8. **Closure:** Formally close the CR upon stakeholder acceptance.

---

## 2. Change Request Status Legend

* **Priority:** `Critical` (Blocks operations), `High` (Core accounting requirement), `Medium` (Operational enhancement), `Low` (Cosmetic / Nice-to-have).
* **Approval Status:** `Proposed` | `Under Review` | `Approved` | `Rejected` | `Deferred`.
* **Dev Status:** `Backlog` | `In Progress` | `Completed`.
* **Automated QA:** `Pending` | `Passed` | `Failed`.
* **Browser Retest:** `Required` | `Verified PASS` | `N/A`.
* **Closure:** `Open` | `Closed`.

---

## 3. Master Change Register Table

| CR ID | Date | Client Requirement Description | Affected Module | Original UAT Phase | Priority | Approval Status | Dev Status | Auto QA | Browser Retest | Git Commit | Closure Status |
|:---:|:---:|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| *Template* | *YYYY-MM-DD* | *Detailed description of the client change* | *Module Name* | *Phase #* | *Medium* | *Approved* | *Completed* | *Passed* | *Verified PASS* | *[hash]* | *Closed* |

*(Note: Real client change entries will be appended sequentially starting from `CR-2026-001` upon receipt of formal client instructions).*

---

## 4. Pending Client Policy Items (Awaiting Formal CR / Instructions)

The following documented business decisions (`docs/CLIENT_POLICY_DECISIONS.md`) are currently open and may convert into Change Requests once decided by the client:

1. **COA Row Decisions:** Decisions on 27 School accounts and 11 signal accounts in `COA_APPROVAL_SUMMARY.md`.
2. **Cash Overdraft / Negative Cash:** Policy decision on whether an automatic hard stop should be implemented on payments or cash transfers.
3. **Split Payment Thresholds:** Whether multiple payments to one payee totaling > PKR 150,000 should require Director approval.
4. **Receipt Reversal Authority:** Whether reversing a fee or donation receipt above PKR 150,000 requires Director approval.
5. **Salary Visibility Policy:** Final client decision on whether the Management role should retain sensitive salary viewing access.
