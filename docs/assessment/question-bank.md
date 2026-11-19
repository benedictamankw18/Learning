# Central Question Bank (Phase 1B)

Phase 1B separates question **content** from the quizzes that use it. A question
is authored once, stored in the bank, and linked to any number of quizzes. Each
link carries the quiz-specific order and an optional points override.

The legacy `quiz_questions` and `quiz_question_options` tables remain in the
database for the transition, but the application no longer reads or writes
them. Phase 1C adds categories, subjects/topics, approval, and versioning on top
of this model.

## Tables

| Table | Purpose |
| --- | --- |
| `questions` | The question content: text, image, type, default points, difficulty, explanation, answer key, institution, owner, archived flag. |
| `question_bank_options` | Answer options for a bank question (`option_label`, `option_text`, `is_correct`). |
| `quiz_question_links` | Which bank questions a quiz uses, in which order (`position`), and an optional `points_override`. |

The canonical DDL is `db/init/05-question-bank.sql`. The migration
`lms-api/migrations/create_question_bank.php` executes that file and copies every
legacy question and option across while keeping the original IDs, so existing
submission answers, exports, and links continue to resolve.

No foreign keys are declared. That matches the rest of the canonical schema and
allows legacy data that already contains orphaned option rows to migrate.

## Ownership and visibility

`questions.institution_id` is always set. `questions.owner_user_id` is the user
who owns the question:

- A question added through a quiz is owned by that quiz's teacher
  (`class_subjects.teacher_id` → `teachers.user_id`).
- A course with no teacher produces an institution-owned question
  (`owner_user_id IS NULL`).
- Institution-owned questions are shared across the institution.

Teachers only see and reuse their own questions plus institution-owned ones.
Admins can see every question in their institution, and super admins can see
everything. Content edits are limited to the creator or an admin; a teacher who
did not create a question cannot edit it. Phase 1C introduces explicit
`visibility` and an approval flow for sharing private questions.

## Per-quiz points and order

`quiz_question_links.position` is the quiz's own ordering. Drag-and-drop
reordering updates the link, not the bank row.

`quiz_question_links.points_override` is `NULL` by default, which means "use
`questions.points`". Editing points from a quiz stores the override on that link
only. A bank-level edit changes the shared default for every quiz that has no
override.

Grading and the question list both use
`COALESCE(link.points_override, questions.points)`.

## Removing a question

`DELETE /api/quiz-questions/{id}?quiz_id={quizId}` removes the link only. The
bank row and its options are kept, so other quizzes keep working and past
attempts remain reviewable. Without `quiz_id` the legacy behaviour removes every
link for that question; a teacher may only do that for a question they created.

Deleting a quiz removes its links but never touches bank content.

## Attempt snapshots

When an attempt starts, the served content is written to
`quiz_submission_questions.question_snapshot` as JSON (question text, image,
type, points, difficulty, explanation, answer key, and options).

Reads for an in-progress or submitted attempt prefer the snapshot, so editing a
bank question later cannot rewrite what a student saw or how that attempt was
graded. Attempts started before Phase 1B have no snapshot and fall back to the
current bank content. Phase 1C replaces the JSON snapshot with first-class
`question_versions`.

## CSV import

Import checks the quiz for duplicate text first, then looks for a matching bank
question visible to the quiz's teacher and links it instead of creating a
duplicate. See [Question Import and Export](question-import-export.md).

## Migration and rollback

Run the schema and backfill once per environment:

```bash
php lms-api/migrations/create_question_bank.php
php lms-api/migrations/add_question_snapshot_to_quiz_submission_questions.php
```

The backfill is additive and idempotent: it copies legacy rows, links each quiz
to its questions in the existing `order_index` order, and leaves the legacy
tables untouched.

Rollback is a database restore, or dropping the three bank tables and the
`question_snapshot` column after restoring the legacy code. There is no reverse
migration because the migration is designed to be non-destructive.

## API surface

The existing quiz question endpoints keep their paths:

| Method | Path | Behaviour |
| --- | --- | --- |
| `GET` | `/api/quizzes/{id}/questions` | Quiz questions with link order and effective points. |
| `POST` | `/api/quizzes/{id}/questions` | Creates a bank question and links it. |
| `PUT` | `/api/quiz-questions/{id}?quiz_id={quizId}` | Updates bank content; `order_index` and `points` apply to that quiz's link. |
| `DELETE` | `/api/quiz-questions/{id}?quiz_id={quizId}` | Removes the link; bank content is kept. |
| `GET` | `/api/quiz-submissions/{id}/questions` | Serves the attempt in its persisted order from the snapshot. |

`quiz_id` is optional for backward compatibility. When present, the server
checks that the question is actually linked to that quiz before authorizing the
request, which prevents a question reused by several quizzes from being edited
through the wrong one.
