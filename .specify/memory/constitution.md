<!--
Sync Impact Report
Version change: template/unratified -> 1.0.0
Modified principles:
- Placeholder PRINCIPLE_1_NAME -> I. Security-First Plane Separation
- Placeholder PRINCIPLE_2_NAME -> II. Privacy and Compliance by Design
- Placeholder PRINCIPLE_3_NAME -> III. Performance and Reliability Budgets
- Placeholder PRINCIPLE_4_NAME -> IV. Quality Gates and Test Coverage
- Placeholder PRINCIPLE_5_NAME -> V. Consistent User Experience
Added principles:
- VI. Modular Design, Documentation, and Trunk-Based Delivery
Added sections:
- Platform Constraints
- Development Workflow and Review Gates
Removed sections:
- Placeholder SECTION_2_NAME
- Placeholder SECTION_3_NAME
Templates requiring updates:
- ✅ updated .specify/templates/plan-template.md
- ✅ updated .specify/templates/spec-template.md
- ✅ updated .specify/templates/tasks-template.md
- ✅ no command templates present under .specify/templates/commands/*.md
- ✅ updated docs/blueprints/BLUEPRINT_PLATFORM.md
Follow-up TODOs:
- None
-->
# VerifySky Constitution

## Core Principles

### I. Security-First Plane Separation
VerifySky MUST preserve a strict separation between the Laravel dashboard control
plane and the Cloudflare Worker enforcement plane. The control plane owns policy
configuration, orchestration, tenant administration, billing state, and operator
actions. The enforcement plane owns request-time protection, risk scoring,
challenge issuance, blocking, session validation, and telemetry capture. Runtime
policy changes MUST propagate through explicit contracts, cache invalidation, and
sync jobs; dashboard code MUST NOT assume a Worker redeploy for policy activation.

Rationale: security decisions happen at the edge, while administrative authority
and auditability stay in the control plane. Mixing these responsibilities creates
authorization ambiguity and delayed enforcement.

### II. Privacy and Compliance by Design
Unencrypted personally identifiable information, secrets, tokens, credentials,
or customer operational data MUST NOT be stored, logged, cached, exported, or
sent to the client. PII and tenant data MUST be minimized, encrypted in transit,
encrypted
at rest where storage supports it, access-controlled, and handled according to
GDPR principles for lawful purpose, retention, deletion, and auditability.
Secrets MUST remain in server-side settings, environment variables, Cloudflare
secret storage, or equivalent protected stores.

Rationale: the platform processes security telemetry for customer domains. Privacy
failures directly undermine customer trust and regulatory posture.

### III. Performance and Reliability Budgets
User-facing API responses MUST complete in under 200 ms at the application
boundary for normal operating conditions, excluding documented third-party
provider latency that is handled asynchronously or surfaced with an explicit
degraded state. Edge enforcement MUST favor deterministic, bounded work on the
request path and move non-critical analysis to background execution. Queue,
scheduler, cache, and sync failures that can stale enforcement state MUST be
observable and treated as production incidents.

Rationale: edge protection must not become the bottleneck it is meant to defend.
Predictable latency and stale-state detection are mandatory for a protection
platform.

### IV. Quality Gates and Test Coverage
PHP code MUST follow PSR-12 using Laravel Pint, pass PHPStan where configured,
and preserve framework conventions. JavaScript and TypeScript code MUST use and
pass ESLint policy for lintable source, plus the worker typecheck and build gates.
Automated tests MUST cover security-critical behavior, control/enforcement-plane
contracts, privacy handling, and each independently deliverable user story. The
combined project coverage target is at least 80%; any lower coverage requires a
documented exception with compensating manual verification and owner approval.

Rationale: quality gates keep security controls reviewable and prevent accidental
contract drift between the dashboard and worker.

### V. Consistent User Experience
Dashboard workflows MUST present consistent navigation, labels, validation,
empty states, loading states, success messages, and error recovery across feature
areas. Any customer- or operator-facing flow MUST be testable from the UI and
MUST explain degraded provider states without exposing internal secrets or
implementation details. UX changes MUST preserve accessibility basics, including
semantic controls, keyboard reachability, and readable contrast.

Rationale: operators manage security posture under time pressure. Inconsistent
interfaces increase operational mistakes and support burden.

### VI. Modular Design, Documentation, and Trunk-Based Delivery
Features MUST be designed as small, documented modules with explicit ownership,
contracts, and dependency boundaries. Laravel controllers MUST stay thin,
validation MUST live in FormRequests, business behavior MUST live in actions or
services, and Blade MUST remain presentation-only. Worker modules MUST expose
clear TypeScript contracts and avoid hidden coupling to dashboard internals. All
risky or incomplete behavior MUST be guarded by feature flags or equivalent
configuration. Development MUST use trunk-based practices: short-lived branches,
incremental commits, backward-compatible migrations, and deployable intermediate
states.

Rationale: modular, documented delivery lets security-sensitive changes ship
incrementally without destabilizing production enforcement.

## Platform Constraints

- The repository is a monorepo with `dashboard/` as the Laravel control plane and
  `worker/` as the Cloudflare Worker enforcement plane.
- Dashboard quality gates MUST include `composer quality` unless a narrower,
  documented gate is justified for the change.
- Worker quality gates MUST include `npm run -s typecheck` and
  `npm run -s build` for Worker changes.
- Control/enforcement-plane contract changes MUST include contract or integration
  tests and documentation updates.
- API performance-sensitive changes MUST define the expected p95 latency impact
  and keep normal API responses below 200 ms.
- Any feature that touches PII, secrets, security telemetry, payment state, or
  tenant configuration MUST document storage, retention, encryption, and access
  controls.

## Development Workflow and Review Gates

- Every specification MUST include security, privacy, performance, UX, feature
  flag, and plane-boundary considerations before planning begins.
- Every implementation plan MUST pass the Constitution Check before Phase 0
  research and again after Phase 1 design.
- Every task list MUST include tests and validation work sufficient to protect
  the 80% coverage target and the 200 ms API response budget.
- Pull requests MUST document affected modules, feature flags, migrations,
  contract changes, observability changes, and rollback/deactivation steps.
- Reviewers MUST block changes that weaken these principles without an accepted
  amendment to this constitution.

## Governance

This constitution supersedes conflicting local practices, generated plans, and
ad hoc implementation preferences. Amendments require a documented proposal that
states the affected principles, operational impact, migration plan, and version
bump rationale. Approval requires review by the project owner or designated
maintainers for both the control plane and enforcement plane.

Semantic versioning applies to this constitution:

- MAJOR for principle removals or backward-incompatible governance changes.
- MINOR for new principles, new mandatory sections, or materially expanded
  guidance.
- PATCH for clarifications, wording fixes, and non-semantic refinements.

Compliance is reviewed during specification, planning, task generation, code
review, and release readiness. Any exception MUST name the owner, expiration or
revisit date, compensating controls, and the reason the compliant path is not
currently feasible.

**Version**: 1.0.0 | **Ratified**: 2026-05-02 | **Last Amended**: 2026-05-02
