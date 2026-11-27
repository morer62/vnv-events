# Productions Management Center — dependency map

## Before implementation

The public VNV website and its authenticated PHP panel share a codebase. The operations pilot has a separate release, sign-in and datastore. An account in one does not imply membership in the other. Never copy a production contact list into a demo.

| Requirement | System of record | Integration decision |
| --- | --- | --- |
| VNV direction and staff | VNV users and user_feature_permissions | Validate the signed-in identity, active status, VNV site, owner and explicit delegated grants on each center request. Existing user levels retain their meaning. |
| Private client and sponsor pipeline | CommercialPartnershipRepository and its assigned opportunities | Keep the standard clients CRM distinct. Staff see the opportunities assigned to them. |
| Execution | Existing production records, order tasks and production-operations methodology | Attach phases, assignments, dates and closing evidence to the existing operating records. |
| Original experiences | Break Club and existing concept registries | Report real waitlists, registrations, payments and attendance separately. Record unavailable sources explicitly. |
| External organizations | Private VNV-Q operations release | Management delegates have a scoped portfolio. Organization and environment identities are independent of email. Demo, reset and activation must preserve that isolation. |
| Learning | Actual modules in the installed environment | Verify role-specific actions against records, scope evidence per membership and revision, and distinguish unavailable integrations from completed work. |
| Existing Ophyra Core / Victoria Venezuela features | Separate applications with their own roles and event data | Reuse documented operations where integrated. Do not expose or migrate an unrelated tenant to demonstrate a module. |

The real VNV business is administered by its existing Level 1 owner. A VNV Level 4 staff member can receive scoped center permissions. In a managed workspace Level 2 controls that organization, Level 4 is a team member and Level 5 is a customer. Updating a VNV user's global level is not part of delegation.

## Operating the center

Open `/panel/productions-management-center` after signing in to VNV. Direction can also open Team access from a Level 4 user?s existing Management access screen. An active, verified VNV identity is required. Changes to delegation, the effective organization or account status take effect on the next request. The current corporate CRM remains controlled by its own entitlement.

Theresa?s first assignment is in Tasks & priorities. Review one assigned opportunity and its next action. In Demo center, create a fictional training organization assigned to yourself. Use the administrator invitation to create your workspace login, then invite a team member and a customer. Exercise each authorized role in a separate sign-in. Open Onboarding & training to review the evidence for each role, print the checklists and note any usability issues. Direction reviews those reports before closing the assignment. Issuing an invitation, watching a video or opening a screen never substitutes for completing the activity.

Direction can grant creation, invitations, demo reset and account lifecycle separately. The initial assignment includes creation, invitations and demo reset. Staff may request a lifecycle change for their own portfolio; direction decides whether to activate or deactivate the account. Declining or executing the request leaves a record.

## Deployment and verification

The VNV application reads its normal MySQL connection. Apply the audit migration and the workboard migration in that order. See [operational data sources and workflow](management-center.md) for the existing tables extended by those migrations. The one-time CLI staff assignment checks the supplied staff ID, expected name, active status, organization and level before it writes. Retrying it preserves subsequent permission decisions and prior progress. Administrative audit records contain action, result, actor and target. Never store invitation codes in logs or CRM notes.

The private operations release and the VNV panel share a private registry location. Each external organization receives its own database and version. Activation and reset create a fresh generation. See the private operations repository?s `docs/MANAGED_WORKSPACES.md` and `docs/TRAINING.md` for configuration, retention, login, evidence definitions and role walkthroughs. Set the public access-ready switch only after the authoritative DNS record, trusted certificate and external HTTPS entry point have all been verified.

The VNV-Q workspace currently provides projects, tasks and CRM. The native VNV ticketing and attendance/payroll workflows were tested separately. They must be explicitly integrated and isolated before becoming external workspace entitlements. Waitlist interest, paid registrations and recorded arrivals are distinct metrics. An empty registry reports no real accounts; a disconnected registry reports unavailable.

Regression coverage includes authenticated VNV HTTP requests, the private VNV-Q bridge, native payroll and Break Club, independent owner/staff/customer sessions, invitations, tenant boundaries, printable checklists and mobile layout. These checks use synthetic organizations and gateway adapters. Theresa?s actual training and any customer rollout are separate operational milestones.

## Release verification, November 27, 2026

- VNV Events main deployed application revision 02e5d938 by fast-forward on cPanel. The private operations origin runs develop revision 70328f5. Its existing database received the additive training schema and its new managed registry was initialized empty. Pre-change backups were verified.
- Synthetic sign-ins exercised all ten tabs through the VNV HTTP kernel and the real Twig layouts. Checks covered unrelated owners, missing grants, incorrect levels, revocation, CSRF, scoped CRM handoff, task links, demo creation and role-filtered printing. Chromium confirmed the workboard at 390 and 1440 pixels. The protected role report rendered to PDF.
- The private operations suite passed 64 managed account checks, 25 HTTP checks, 61 historical workspace checks, 57 CRM checks and 36 onboarding checks. The three role journeys were also exercised with Chromium. Separate native regressions passed 11 attendance/payroll and 29 ticket lifecycle checks using isolated test records.
- A read-only production check loaded all ten data sources for the VNV direction and delegated staff scopes. One pending training assignment was found for the designated team member. A second run of the assignment command confirmed it did not create a duplicate. An anonymous request to the VNV center received the existing session-required page.
- At release the private VNV-Q origin presented its expected login page when checked against the pinned private certificate. Projects and training required authentication. Both authoritative DNS servers returned NXDOMAIN for the operations hostname, and the origin certificate was self-signed. The external-ready setting remained off. Recheck public DNS and a normally trusted HTTPS connection before enabling or sharing external invitations.
