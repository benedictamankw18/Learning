# Teacher-Private Question Bank

Question content lives in a bank owned by one teacher. A question is authored
once, stored in the bank, and linked to any number of that teacher's quizzes. Each
link carries the quiz's own order and an optional points override.

The bank is private: only the owner may read, edit, archive, export, or link their
questions. Institution admins and super admins have no question-bank access and do
not author questions; their quiz role is assignment, scheduling, and results only.
There is no approval workflow and no sharing/visibility state to manage.

The legacy `quiz_questions` and `quiz_question_options` tables remain in the
database for the transition, but the application no longer reads or writes
them.

## Tables

| Table | Purpose |
| --- | --- |
| `questions` | The question content: text, image, type, default points, difficulty, explanation, answer key, institution, owner, archived flag, optional `course_section_id`, and `current_version_id`. Legacy `category_id`, `subject_id`, `topic_id`, `visibility`, `approval_status`, review fields, and `forked_from_id` remain in the schema for compatibility only; the app does not use them as workflow or UI organization. |
| `question_bank_options` | Answer options for a bank question (`option_label`, `option_text`, `is_correct`). |
| `quiz_question_links` | Which bank questions a quiz uses, in which order (`position`), and an optional `points_override`. |
| `course_sections` | Lesson-plan Topic / Unit / Week buckets, managed by Manage Topics / Units / Weeks and scoped to a course. This is the only organization shown in the bank editor and list. |
| `question_categories` | Legacy teacher-owned Sections. Kept for existing data only. |
| `question_topics` | Legacy teacher-owned Topics, optionally scoped to a subject. Kept for existing data only. |
| `question_versions` | Immutable snapshots of answerable content (`content_json`) with a per-question `version_number`. |
| `quiz_submission_questions` | Per-attempt order plus `question_snapshot` and `question_version_id`. |

The canonical DDL is split across three init files:

- `db/init/05-question-bank.sql` — bank tables and metadata columns on `questions`.
- `db/init/06-question-bank-metadata.sql` — taxonomy and version tables.
- `db/init/01-lms.sql` (and the standalone `04-quiz-submission-questions.sql`) — attempt order, snapshot, and version pin columns.

The one-time ownership migration also adds `owner_user_id` to the taxonomy tables
and replaces institution-wide name keys with owner-scoped keys, so run it after
initializing a fresh database too. See [Migration and rollback](#migration-and-rollback).

No foreign keys are declared. That matches the rest of the canonical schema and
allows legacy data that already contains orphaned option rows to migrate.

## Ownership and access

Every usable question has exactly one owner:

- A question added through a quiz is owned by that quiz's teacher
  (`class_subjects.teacher_id` → `teachers.user_id`).
- Legacy ownerless rows are assigned once, by the one-time migration, to
  `benedict.teacher` (user 11). Rows that already have an owner keep it.

No API endpoint reassigns ownership: new questions are created with the acting
teacher as owner and that value is never changed later. There is deliberately no
institution- or role-based sharing branch in the bank: admins and super admins
receive `403` on bank endpoints, and teachers can only reach their own rows.
Another teacher's question, or a question in another institution, returns `404`
so its existence is not confirmed.

Access is by ownership, not by `visibility` or `approval_status`. Those columns,
the review fields, and `forked_from_id` stay in the schema for existing
databases, but the application no longer uses a workflow: there is no
submit-for-approval, approve, reject, sharing/visibility status, badge, fork, or
review UI. New rows simply take the schema defaults (`private`, `draft`) and stay
editable only by their owner.

## Topic / Unit / Week organization

Organization is optional and comes from Lesson Plans → Manage Topics / Units /
Weeks. Those rows live in `course_sections` and are scoped to a course. The bank
editor shows one aggregated **Topic / Unit / Week** select across the teacher's
courses, with course-name prefixes so same-named buckets remain distinguishable.

Only the owner can create, rename, or delete those buckets through Lesson Plans.
A bank question may only reference a section from one of the owner's own courses
in the same institution. Cross-teacher or cross-institution section IDs are
rejected on create and update. If a bucket is later deleted, the question keeps
its `course_section_id` and the list displays a deleted-bucket fallback.

`question_categories`, `subjects`, and `question_topics` are legacy taxonomy
tables kept for compatibility with existing rows and CSV data. The bank UI no
longer exposes Section, Unit / Week, or Topic from those tables, and new
questions do not write them.

## Versions and attempt integrity

Every answerable content change calls `QuestionBankRepository::createVersion()`,
which INSERTs an immutable row in `question_versions` and advances
`questions.current_version_id`. Versions are never updated or deleted by the
application. Reordering a quiz or changing a per-quiz points override does not
create a version.

When an attempt starts, `ensureSubmissionQuestionOrder()` writes both:

- `question_snapshot` — the normalized content JSON, and
- `question_version_id` — the version that was served.

Serving, review, and grading prefer the pinned version, then the snapshot, then
the live bank row. A later bank edit cannot rewrite what a student already saw
or how that attempt was graded. The snapshot's points remain authoritative when
both exist because it captured the per-quiz `points_override` at serve time,
while a version stores only the bank default.

Legacy attempts have `question_version_id = NULL` and keep using their JSON
snapshot. The metadata migration freezes every legacy bank row as version 1 and
marks it private/draft; the later scoping migration assigns its owner. Neither
step rewrites an attempt that already started.

Quiz attempt behaviour is unchanged by private ownership: availability windows
for starting an attempt, Prevent Going Back (with an answer required before
moving on), timeout auto-submit that leaves unanswered questions blank, the
persisted question order, and grading rules continue to apply.

## Per-quiz points and order

`quiz_question_links.position` is the quiz's own ordering. Drag-and-drop
reordering updates the link, not the bank row.

`quiz_question_links.points_override` is `NULL` by default, which means "use
`questions.points`". Editing points from a quiz stores the override on that link
only. A bank-level edit changes the shared default for every quiz that has no
override.

`POST /api/quizzes/{id}/questions/link` adds one of the teacher's own,
non-archived bank questions to that teacher's quiz by `question_id` without
copying it. A question belonging to another teacher or another institution
returns `404`; an archived question or one already in the quiz is rejected with
`422`. Linking never changes ownership.

## Removing a question

`DELETE /api/quiz-questions/{id}?quiz_id={quizId}` removes the quiz link only.
The bank row and its options are kept, so other quizzes keep working and past
attempts remain reviewable. The legacy call without `quiz_id` is scoped to the
owning teacher's quiz rather than unlinking the question from every quiz.

Deleting a quiz removes its links but never touches bank content.

## CSV import and export

CSV import, export, and the template are available on both the teacher quiz
question manager (`quiz-questions?id=<quizId>`) and the teacher question-bank
page. They use the same CSV shape: CSV only, append-only, duplicate text in the
teacher's own bank/quiz skipped, and a 500-row cap per file. Admins and super
admins do not author bank questions and cannot use these actions. See
[Question Import and Export](question-import-export.md).

## Migration and rollback

Run the schema and backfill once per environment, in order:

```bash
php lms-api/migrations/create_question_bank.php
php lms-api/migrations/add_question_snapshot_to_quiz_submission_questions.php
php lms-api/migrations/add_question_bank_metadata.php
php lms-api/migrations/scope_question_bank_to_teachers.php
php lms-api/migrations/add_course_section_to_questions.php
```

The first copies legacy rows, links each quiz to its questions in the existing
`order_index` order, and leaves the legacy tables untouched. The snapshot
migration adds per-attempt content storage. The metadata migration adds the
taxonomy/version tables and columns, freezes legacy questions as version 1, and
marks them private/draft.

The last migration is the one-time ownership normalization. It:

- adds `owner_user_id` to `question_categories` and `question_topics` and makes
  their names unique per owner;
- assigns legacy ownerless questions to `benedict.teacher` (user 11), leaving
  already-owned rows unchanged;
- marks legacy rows `private`/`draft` again for compatibility; the application
  ignores these columns either way.

If legacy rows need an owner, it refuses to reassign them when user 11 is
missing; a database with no ownerless rows does not need that account. After this
one-time step, no API changes ownership.

The course-section migration adds nullable `questions.course_section_id` and its
index. Existing rows keep a `NULL` value and remain valid. It is additive and
idempotent. All five migrations are additive and idempotent.

Rollback is a database restore, or dropping the bank tables and the added
columns after restoring the legacy code. There is no reverse migration because
the migrations are designed to be non-destructive.

## API surface

### Quiz question endpoints (existing paths, backward compatible)

| Method | Path | Behaviour |
| --- | --- | --- |
| `GET` | `/api/quizzes/{id}/questions` | Quiz questions with link order, effective points, `current_version_id`, and `owner_user_id`. |
| `POST` | `/api/quizzes/{id}/questions` | Creates a bank question (version 1) owned by the quiz's teacher and links it. Teacher only. |
| `POST` | `/api/quizzes/{id}/questions/link` | Links one of the teacher's own, non-archived bank questions by `question_id`. |
| `PUT` | `/api/quiz-questions/{id}?quiz_id={quizId}` | Updates owned bank content, creates a new version; `order_index`/`points` apply to that quiz's link. |
| `DELETE` | `/api/quiz-questions/{id}?quiz_id={quizId}` | Removes the link; bank content is kept. |
| `GET` | `/api/quiz-submissions/{id}/questions` | Serves the attempt in its persisted order from the pinned version or snapshot. |

`quiz_id` is optional for backward compatibility. When present, the server
checks that the question is actually linked to that quiz before authorizing the
request, which prevents a question reused by several quizzes from being edited
through the wrong one.

### Question bank endpoints

All bank endpoints require the `teacher` role and are scoped to the caller's
`owner_user_id`; admins and super admins are denied. Ownership is never accepted
as a request field.

| Method | Path | Behaviour |
| --- | --- | --- |
| `GET` | `/api/question-bank/questions` | Own questions; UI filters `q`, `course_section_id`, `include_archived`; legacy `category_id`/`subject_id`/`topic_id` filters remain for compatibility; paginated with `page`/`limit`. |
| `POST` | `/api/question-bank/questions` | Create an owned question with version 1. |
| `GET` | `/api/question-bank/questions/{id}` | Read one owned question (`404` otherwise). |
| `PUT` | `/api/question-bank/questions/{id}` | Edit owned content/metadata; content changes create a version. |
| `POST` | `/api/question-bank/questions/{id}/archive` | Archive/restore with `is_archived`. |
| `GET` | `/api/question-bank/questions/{id}/versions` | Immutable version history for an owned question. |
| `POST` | `/api/question-bank/questions/import` | Append CSV rows to the teacher's own bank; duplicate text skipped. |
| `GET` | `/api/question-bank/questions/export` | Download the teacher's own questions as CSV; honours the list filters. |
| `GET` | `/api/question-bank/questions/import-template` | Download the CSV template. |
| `GET` | `/api/question-bank/categories` | List the teacher's Sections. |
| `POST` | `/api/question-bank/categories` | Create a Section (optional `parent_id`). |
| `PUT` | `/api/question-bank/categories/{id}` | Rename or reparent an owned Section. |
| `DELETE` | `/api/question-bank/categories/{id}` | Delete an owned, unused Section. |
| `GET` | `/api/question-bank/topics` | List the teacher's Topics; optional `subject_id` filter. |
| `POST` | `/api/question-bank/topics` | Create a Topic (optional `subject_id`). |
| `PUT` | `/api/question-bank/topics/{id}` | Rename an owned Topic. |
| `DELETE` | `/api/question-bank/topics/{id}` | Delete an owned, unused Topic. |
