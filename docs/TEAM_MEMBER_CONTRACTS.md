# Team Member Contracts

This repo now includes the Level 1 and Level 4 team-member contract flow adapted from `morer62/VNV_venues`.

## Scope

The flow is only wired for:

- Level 1 admin/owner contract assignment and validation.
- Level 4 team-member contract review and signature.

Do not copy Level 2/3 wrappers unless those levels are intentionally reactivated for this repo.

## SQL

Apply this file before assigning contracts:

```text
db/team_member_contracts_required.sql
```

Until the table exists, panel pages render safely, and clock-in remains compatible with the old behavior.

## Routes

Admin:

```text
/panel/planner-hub/management/users/contracts?id=TEAM_MEMBER_ID
```

Team member:

```text
/panel/planner-hub/team/contracts
```

## Behavior

Level 1 can:

- assign a contract from `orders_contracts`,
- store a snapshot in `team_member_contracts.contract_snapshot_html`,
- upload a manually signed PDF,
- validate `SIGNED` contracts,
- reject contracts,
- view/download signed PDFs.

Level 4 can:

- see assigned contract status,
- read the snapshot HTML,
- sign with initials or signature image,
- consent to electronic signature,
- generate a PDF through Dompdf,
- store hashes and signature metadata.

Clock-in is based on the latest contract for that team member and owner.

Allowed statuses:

```text
SIGNED
VALIDATED
MANUALLY_UPLOADED
```

Blocked statuses:

```text
PENDING
SENT
REJECTED
EXPIRED
```

If a new `PENDING` contract is assigned after an old `VALIDATED` contract, clock-in is blocked again because the latest contract controls access.

## Files

```text
src/Repositories/TeamMemberContractsRepository.php
src/Services/TeamMemberContractService.php
src/Services/TeamMemberContractPdfGenerator.php
src/views/panel/level1/planner-hub/management/users/contracts/
src/views/panel/level4/planner-hub/team/contracts/
```

Existing files updated:

```text
src/views/panel/level1/planner-hub/management/users/index.php
src/views/panel/level1/planner-hub/management/users/index.twig
src/views/panel/level4/home/index.php
src/views/panel/level4/home/index.twig
src/views/templates/layout/sidebars/4.twig
src/views/panel/level4/planner-hub/team/payroll/clock/index.php
src/views/panel/level1/planner-hub/team/payroll/clock/index.php
src/views/panel/level1/planner-hub/management/payroll/clock/index.php
src/views/panel/level4/planner-hub/management/payroll/clock/index.php
```

## QA

1. Apply SQL.
2. Confirm a contract template exists in `orders_contracts`.
3. Open Level 1 team member list and confirm the Contract column appears.
4. Assign a contract to a Level 4 user.
5. Log in as that Level 4 user and confirm the dashboard shows pending contract.
6. Attempt clock-in and confirm it is blocked.
7. Open My Contract and sign.
8. Confirm status becomes `SIGNED`.
9. Confirm clock-in is allowed.
10. Validate the signed contract as Level 1 and confirm status becomes `VALIDATED`.
11. Upload a manual signed PDF for another team member and confirm `MANUALLY_UPLOADED` allows clock-in.
