# Production project templates

Use one working copy per event. Assign people and dates at kickoff, then use the tasks as the production lead's checklist and the evidence column for acceptance at each handoff.

| Project | Working template | Decision at kickoff |
| --- | --- | --- |
| Corporate | [Corporate production](corporate.csv) | Who owns the business objective, budget and final approvals? |
| Nonprofit | [Cause on Cue and approved foundation projects](nonprofit.csv) | What support has been approved and who at the foundation owns donor and sponsor relationships? |
| Brand activation | [Brand Experiences & Activation](brand-activation.csv) | Is this an existing event, an authorized partnership or a brand-owned event? Who can grant the rights? |
| Original experience | [VNV-owned editions](original-experiences.csv) | Which edition is under consideration and who makes the financial go/no-go decision? |

## Set up an event

1. Open the relevant CSV in Excel or Google Sheets and save a working copy in the authorized project space. Add the project name, event date, client decision maker and executive producer to the working brief. Replace the proposed role with an actual person in `assigned_person`. A role can have a different owner for each event.
2. Convert `days_from_event` to a calendar due date using the confirmed event date. For example, a value of `-5` schedules a task five days before the event. The offsets are starting estimates. Adjust for contract deadlines, vendor lead times and the venue's access windows. Dependencies use task IDs within the same worksheet.
3. Review the five phases with the team. **Discover** ends with an approved brief. **Design the Scope** ends with agreed responsibilities and commercial scope. **Build the System** ends when the assigned people have rehearsed the plan. **Produce the Event** captures the live decisions and outcomes. **Close & Recap** ends with a reconciliation and a dated follow-up.
4. Track `todo`, `doing` and `done`. Record the corresponding link or approved evidence reference when marking a task done. Keep donor and guest details in the permissioned system of record. The checklist can point authorized staff there.
5. For an already-provisioned VNV-Q pilot project, match tasks to the assigned project users and track supported status updates in the workspace. The project lead uses the worksheet for dates, dependencies and completion evidence.

## Handoff into VNV Productions

When Productions Management Center is enabled, open Corporate CRM, choose the approved opportunity, record its signed scope or foundation approval reference and confirm the event date. Open the resulting production under Active Productions and apply the relevant five-phase checklist to an actual team member. Direction may link an existing VNV order. Order-backed assignments require confirmed order staff. An approved VNV-owned edition starts under Original Experiences. Full instructions and record boundaries are in the [workboard guide](management-center.md).

## Handoff into a separate VNV-Q workspace

The commercial owner and the receiving producer share responsibility for the handoff. The live commercial CRM records the decision. The separate VNV-Q staging pilot holds fictional organizations and pre-provisioned projects. A pipeline stage change, a signed menu or a linked invoice does not create a VNV-Q project.

1. **Verify the decision.** In the VNV commercial dashboard, open **CRM → Partnership Pipelines**, then the existing opportunity in the appropriate organization and program. The opportunity's edit link contains `?edit=<opportunity_id>`. For corporate work, check **Won**; for an activation, **Approved**; for a sponsorship, **Contracted** or **Paid**, as applicable. For a foundation, have Direction confirm the approved support plan and its scope. An Original Experiences waitlist or ticket record represents an attendee. Use the producer-approved edition brief for the event itself.
2. **Confirm the scope.** For an order-backed engagement, open **Management → Orders → the order → Proposal** at `panel/planner-hub/management/orders/orders/proposal?id=<order_id>`. The manager view shows the current internal version. Verify **Approved and signed** and **Open current signed PDF** when that proposal is the applicable approval. Check the actual production agreement or approved support plan for the responsibilities being handed over. A signed menu, proposal stage or invoice alone does not establish a paid deposit or authorize extra work. Record the relevant order ID, revision and date, or the restricted reference to the approved agreement.
3. **Claim the handoff once.** Use **Edit partnership contact → Requested services / notes** on the existing opportunity, keeping the original notes. Have one authorized commercial owner append the same handoff record on retries. Capture the site and opportunity ID, client organization, event and edition/date, scope, approval reference, commercial owner, producer, template and next deadline. Include an existing order ID when applicable. A subsequent revision updates the entry for this event. Separate editions require separate entries. On a renewal or reassignment, check the existing notes for the project reference before opening another request.
4. **Verify the destination.** In an authorized operational workspace, the receiving producer opens **Workspace → Projects** (`/projects`), selects the assigned event and checks its organization, name and project kind. Its detail route is `/projects/<project_id>`. Confirm the commercial reference with the project coordinator before associating that ID. A missing project in an assigned user's list can mean restricted access. Ask the organization's coordinator to check. In the current private pilot there is no commercial-project creation screen, production provisioning command or CRM-to-project connector. For real work without a confirmed live destination, record **Awaiting production workspace**, continue in the authorized working template and assign a next check. Keep live customer records out of the synthetic staging environment.
5. **Close the loop.** On an authorized live destination, the producer checks that the assigned lead can open the correct project and see the agreed first task. The producer confirms the project ID, organization, template, first owner and due date back to the commercial owner. Only then does the owner append **Handoff confirmed**, the project reference and the confirmation date to the same opportunity. Keep signing links, invite codes and private guest lists in their original access-controlled systems.

For a staging rehearsal, an authorized Harbor reviewer can open its fictional **Harbor Lights Benefit Gala** project under `/projects/101`, then inspect assigned tasks and permitted status controls. This demonstrates project access and acceptance independently of a live customer handoff.

## Current system scope

The private VNV operational pilot on `develop` at `2b62121` has projects, organization-scoped assignments, task titles, assignees and the three task statuses. It also has project CRM delegations and follow-up. Its current project screen supports status updates for authorized existing tasks. The schema and controller reviewed on November 27, 2026 do not provide template import, phase records, evidence links or automatic dependency scheduling. These four worksheets can be used immediately without adding a new system, provisioning demo contacts or changing a client's live project.

The platform references used for this assessment are `database/001_pilot.sql`, `database/002_crm.sql`, `src/Pilot.php`, `src/PilotCrm.php` and `templates/project.php` in the private `vnv-operational-software` repository.
