# Central Question Bank (Phases 1B and 1C)

Phase 1B separates question **content** from the quizzes that use it. A question
is authored once, stored in the bank, and linked to any number of quizzes. Each
link carries the quiz-specific order and an optional points override.

Phase 1C adds taxonomy (categories and subject-scoped topics), an approval
workflow, private-by-default sharing, and immutable question versions so a
student's attempt stays reviewable after the bank changes.

The legacy `quiz_questions` and `quiz_question_options` tables remain in the
database for the transition, but the application no longer reads or writes
them.

## Tables

| Table | Purpose |
| --- | --- |
| `questions` | The question content: text, image, type, default points, difficulty, explanation, answer key, institution, owner, archived flag, plus Phase 1C metadata (`category_id`, `subject_id`, `topic_id`, `visibility`, `approval_status`, review fields, `forked_from_id`, `current_version_id`). |
| `question_bank_options` | Answer options for a bank question (`option_label`, `option_text`, `is_correct`). |
| `quiz_question_links` | Which bank questions a quiz uses, in which order (`position`), and an optional `points_override`. |
| `question_categories` | Institution-scoped, optionally nested categories. |
| `question_topics` | Institution-scoped topics, optionally scoped to a subject (`subject_id = NULL` means a general topic). |
| `question_versions` | Immutable snapshots of answerable content (`content_json`) with a per-question `version_number`. |
| `quiz_submission_questions` | Per-attempt order plus `question_snapshot` (Phase 1B) and `question_version_id` (Phase 1C). |

The canonical DDL is split across three init files:

- `db/init/05-question-bank.sql` — bank tables and metadata columns on `questions`.
- `db/init/06-question-bank-metadata.sql` — taxonomy and version tables.
- `db/init/01-lms.sql` (and the standalone `04-quiz-submission-questions.sql`) — attempt order, snapshot, and version pin columns.

No foreign keys are declared. That matches the rest of the canonical schema and
allows legacy data that already contains orphaned option rows to migrate.

## Ownership, visibility, and approval

`questions.institution_id` is always set. `questions.owner_user_id` is the user
who owns the question:

- A question added through a quiz is owned by that quiz's teacher
  (`class_subjects.teacher_id` → `teachers.user_id`).
- A course with no teacher produces an institution-owned question
  (`owner_user_id IS NULL`).

New questions are **private** (`visibility = 'private'`) and **drafts**
(`approval_status = 'draft'`). The sharing rule is:

| Actor | Can see |
| --- | --- |
| Teacher | Own questions (any status) plus questions with `visibility = 'institution'` **and** `approval_status = 'approved'` and not archived. |
| Institution admin | Every question in their institution, including drafts. |
| Super admin | Every question in every institution. |

Cross-institution reads return `404` rather than `403` so the existence of a
question in another institution is not confirmed.

### Approval flow

1. The creator calls `POST /api/question-bank/questions/{id}/submit-for-approval`.
   This sets `approval_status = 'pending'` and keeps `visibility = 'private'`.
2. An institution admin or super admin calls `.../approve` (optionally with a
   `note`) or `.../reject` (a `note` is required). Approval sets
   `visibility = 'institution'`, `approval_status = 'approved'`, and records the
   reviewer and time.
3. A **non-admin** content edit to an approved question automatically returns it
   to `pending`/`private`, so the institution copy is not silently changed.
   Admin edits stay approved.

Content edits are limited to the creator or an admin. A teacher who did not
create a question cannot edit it; they must **fork** it with
`POST /api/question-bank/questions/{id}/fork`, which creates a new private
draft in their own bank (new owner, new version history, original untouched).

## Versions and attempt integrity

Every content change calls `QuestionBankRepository::createVersion()`, which
 INSERTs an immutable row in `question_versions` and advances
`questions.current_version_id`. Versions are never updated or deleted by the
application.

When an attempt starts, `ensureSubmissionQuestionOrder()` writes both:

- `question_snapshot` — the normalized content JSON (Phase 1B), and
- `question_version_id` — the version that was served (Phase 1C).

Serving, review, and grading prefer the pinned version, then the snapshot, then
the live bank row. This means a bank edit — or a pending-reset after a teacher
edit — cannot rewrite what a student already saw or how that attempt was
graded. The snapshot's points remain authoritative when both exist because it
captured the per-quiz `points_override` at serve time, while a version stores
only the bank default.

Legacy attempts created before Phase 1C have `question_version_id = NULL` and
keep using their JSON snapshot. The metadata migration freezes every legacy
bank row as version 1 and grandfathers it as `institution`/`approved`.

## Per-quiz points and order

`quiz_question_links.position` is the quiz's own ordering. Drag-and-drop
reordering updates the link, not the bank row.

`quiz_question_links.points_override` is `NULL` by default, which means "use
`questions.points`". Editing points from a quiz stores the override on that link
only. A bank-level edit changes the shared default for every quiz that has no
override. Neither a points override nor a reorder creates a version.

`POST /api/quizzes/{id}/questions/link` adds an existing visible bank question
to a quiz by `question_id` without copying it. Hidden drafts, another teacher's
unapproved question, and questions already in the quiz are rejected with 422;
cross-institution questions return 404.

## Removing a question

`DELETE /api/quiz-questions/{id}?quiz_id={quizId}` removes the link only. The
bank row and its options are kept, so other quizzes keep working and past
attempts remain reviewable. Without `quiz_id` the legacy behaviour removes every
link for that question; a teacher may only do that for a question they created.

Deleting a quiz removes its links but never touches bank content.

## CSV import

Import checks the quiz for duplicate text first, then looks for a matching bank
question the teacher can actually see (own row, or `institution` + `approved` +
not archived) and links it instead of creating a duplicate. Drafts, pending
submissions, rejected rows, and archived rows are never borrowed. See
[Question Import and Export](question-import-export.md).

## Migration and rollback

Run the schema and backfill once per environment, in order:

```bash
php lms-api/migrations/create_question_bank.php
php lms-api/migrations/add_question_snapshot_to_quiz_submission_questions.php
php lms-api/migrations/add_question_bank_metadata.php
```

Each migration is additive and idempotent. The first copies legacy rows, links
each quiz to its questions in the existing `order_index` order, and leaves the
legacy tables untouched. The last creates the taxonomy/version tables, adds the
metadata columns, and freezes legacy questions as version 1.

Rollback is a database restore, or dropping the bank tables and the added
columns after restoring the legacy code. There is no reverse migration because
the migrations are designed to be non-destructive.

## API surface

### Quiz question endpoints (existing paths, backward compatible)

| Method | Path | Behaviour |
| --- | --- | --- |
| `GET` | `/api/quizzes/{id}/questions` | Quiz questions with link order, effective points, `current_version_id`, `visibility`, and `approval_status`. |
| `POST` | `/api/quizzes/{id}/questions` | Creates a bank question (version 1) and links it. |
| `POST` | `/api/quizzes/{id}/questions/link` | Links an existing visible bank question by `question_id`. |
| `PUT` | `/api/quiz-questions/{id}?quiz_id={quizId}` | Updates bank content, creates a new version, and resets approval for non-admins; `order_index`/`points` apply to that quiz's link. |
| `DELETE` | `/api/quiz-questions/{id}?quiz_id={quizId}` | Removes the link; bank content is kept. |
| `GET` | `/api/quiz-submissions/{id}/questions` | Serves the attempt in its persisted order from the pinned version or snapshot. |

`quiz_id` is optional for backward compatibility. When present, the server
checks that the question is actually linked to that quiz before authorizing the
request, which prevents a question reused by several quizzes from being edited
through the wrong one.

### Question bank endpoints (Phase 1C)

| Method | Path | Behaviour |
| --- | --- | --- |
| `GET` | `/api/question-bank/questions` | Visible questions; filters `q`, `category_id`, `subject_id`, `topic_id`, `visibility`, `approval_status`, `is_archived`; paginated. |
| `POST` | `/api/question-bank/questions` | Create a private draft with version 1. |
| `GET` | `/api/question-bank/questions/{id}` | Read one visible question. |
| `PUT` | `/api/question-bank/questions/{id}` | Edit content/metadata (creator or admin); creates a version and resets teacher approval. |
| `POST` | `/api/question-bank/questions/{id}/submit-for-approval` | Creator submits for review. |
| `POST` | `/api/question-bank/questions/{id}/approve` | Admin approves (optional `note`). |
| `POST` | `/api/question-bank/questions/{id}/reject` | Admin rejects (required `note`). |
| `POST` | `/api/question-bank/questions/{id}/fork` | Copy into the caller's private bank. |
| `POST` | `/api/question-bank/questions/{id}/archive` | Archive/restore with `is_archived`. |
| `GET` | `/api/question-bank/questions/{id}/versions` | Version history for a visible question. |
| `GET/POST/PUT/DELETE` | `/api/question-bank/categories` | Taxonomy management (admins write; teachers read). |
| `GET/POST/PUT/DELETE` | `/api/question-bank/topics` | Subject-scoped topics (admins write; teachers read). |

Super admins creating bank records must supply `institution_id` in the request
body, because their account has no institution of its own.
