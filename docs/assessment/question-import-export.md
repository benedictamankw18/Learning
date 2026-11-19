# Question Import and Export (CSV)

Teachers and administrators can move quiz questions in and out of the LMS as
CSV files. The same CSV shape is used by the export endpoint and by the
downloadable template, so a file exported from one quiz can be imported into
another without editing.

This is Phase 1A of the assessment roadmap: import, export, and a template.
There is no `.xlsx` support and no "replace all" mode yet.

## Where it lives in the UI

Open **Teacher → Quizzes**, then open a quiz's question manager. The toolbar
has three controls:

| Control | What it does |
| --- | --- |
| **Import Questions** | Opens a dialog where you choose a `.csv` file, optionally download the template, then confirm the import. Per-row results are shown in the dialog. |
| **Export Questions** | Downloads the quiz's current questions as `quiz-<id>-questions-<timestamp>.csv`. |
| **Download Template** (inside the import dialog) | Downloads `quiz-<id>-questions-import-template.csv`, which contains guidance comments, the header row, and three `SAMPLE` rows. |

All three actions require a signed-in teacher, institution admin, or super
admin who is allowed to manage the quiz. The server enforces the same rules as
the rest of the quiz endpoints:

- Super admins may manage any quiz.
- Everyone else may only touch quizzes in their own institution (`404` if the
  quiz belongs to another institution, so the existence of the quiz is not
  leaked).
- A teacher who is not also an admin may only touch their own quizzes (`403`
  otherwise).

## CSV columns

Columns are matched by header name, so their order does not matter. Names are
case-insensitive and `-`/whitespace are treated as `_`.

| Column | Required | Notes |
| --- | --- | --- |
| `question_text` | Yes | The question itself. Quoted commas, quotes, and embedded newlines are supported. Maximum 65,535 characters. |
| `question_type` | Yes | One of `multiple_choice`, `true_false`, `short_answer`, `essay`. |
| `difficulty` | No | `easy`, `medium`, or `hard`. Blank defaults to `medium`. |
| `points` | No | Whole number, 1 or more. Blank defaults to `1`. |
| `option_a` … `option_f` | MCQ only | Option text. At least `option_a` and `option_b` must be non-empty for `multiple_choice`. |
| `correct_answer` | Depends on type | See the per-type rules below. |
| `explanation` | No | Shown during review. Maximum 65,535 characters. |
| `image_url` | No | An image path or URL. Maximum 255 characters. |

Recognised header aliases:

| Alias | Treated as |
| --- | --- |
| `question` | `question_text` |
| `type` | `question_type` |
| `image` | `image_url` |
| `image_question` | `image_url` |

Unknown columns are ignored, so a file exported from this system can carry
extra spreadsheet columns without breaking the import.

### Per-type rules

**`multiple_choice`**

- Fill the option columns you need: two to six options (`option_a`–`option_f`).
  Empty option cells are skipped, and labels are assigned in order from the
  filled columns, so leaving a gap (for example `option_a`, `option_b`,
  `option_d`) produces labels `A`, `B`, `C` — not `A`, `B`, `D`.
- `correct_answer` must be the label of an option that is present in the row
  (`A`–`F`). It is not case-sensitive.

**`true_false`**

- `correct_answer` is required and is canonicalised to `TRUE` or `FALSE`.
  Accepted inputs: `TRUE`/`FALSE`, `T`/`F`, `YES`/`NO`, `Y`/`N`, `1`/`0`,
  `A`/`B` (case-insensitive).
- Option columns are ignored.

**`short_answer` and `essay`**

- `correct_answer` is optional and is stored as written (maximum 500
  characters). Keeping it means an exported file can be imported again without
  losing the model answer. It is not used for automatic grading of free text.
- Option columns are ignored.

## Comments, blank lines, and encoding

- A UTF-8 byte-order mark is stripped on import and written on export and on
  the template, so Excel opens non-ASCII text correctly.
- Before the header row, blank lines and lines whose first cell starts with `#`
  are ignored. This is how the template carries its guidance text.
- After the header row, **every** non-empty row is treated as data. A question
  whose text begins with `#` is imported normally.
- Empty rows (every cell blank or whitespace) are skipped anywhere in the file.

## Import behaviour

Import is **append-only**. Existing questions are never modified or removed.

- **Duplicates are skipped.** A row whose `question_text` matches an existing
  question in the same quiz is skipped. The comparison ignores leading and
  trailing whitespace and letter case. Re-importing the same file is therefore
  safe: nothing is duplicated.
- **Invalid rows do not abort the file.** Each row is validated before insert,
  and each insert runs inside its own savepoint. A row that fails validation or
  saving is reported, and the remaining rows still import.
- **Imported questions are appended** after the quiz's current highest
  `order_index`.
- **Limits:** 500 question rows and 2 MB per file. Split larger banks into
  several files.

The response reports the totals and a per-row result list:

```json
{
  "message": "Imported 8 questions. Skipped 2 duplicates. Failed 1 row.",
  "imported": 8,
  "skipped": 2,
  "failed": 1,
  "total_rows": 11,
  "results": [
    { "row": 2, "status": "imported", "message": "Question imported." },
    { "row": 3, "status": "skipped", "message": "A question with this text already exists in this quiz." },
    { "row": 4, "status": "failed", "message": "points must be a whole number of 1 or more." }
  ]
}
```

`row` is the physical CSV record number, counting the header row, so it lines
up with the row numbers shown by a spreadsheet.

## Export behaviour

Export writes every question in the quiz, in the quiz's stored order, using the
canonical column list. Multiple-choice rows include the correct option label in
`correct_answer` and the option text in `option_a`–`option_f`.

The export is not a backup of the whole quiz (title, timing, attempts, and so
on are not included) — it is a portable question bank extract. Importing it
into a different quiz copies the questions only.

## Formula injection protection

A spreadsheet treats a cell beginning with `=`, `+`, `-`, `@`, tab, or carriage
return as a formula. On export, such cells are prefixed with an apostrophe so
they open as literal text. On import, the apostrophe is removed again when the
next character is one of those formula starters, so export → import keeps the
original wording.

If you hand-write a question that genuinely begins with an apostrophe followed
by one of those characters, the leading apostrophe will be treated as the
neutraliser and dropped on import. That is the documented trade-off for
round-trip safety.

## HTTP endpoints

All endpoints require authentication. `<id>` is the quiz ID.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/quizzes/<id>/questions/import` | Import a CSV. Send `multipart/form-data` with a single `file` field containing a `.csv`. |
| `GET` | `/api/quizzes/<id>/questions/export` | Download the quiz's questions as CSV. |
| `GET` | `/api/quizzes/<id>/questions/import-template` | Download the import template. |

Import error responses:

| Status | When |
| --- | --- |
| `400` | No file, an upload failure, or an unreadable temporary file. |
| `403` | The caller is a teacher who does not own the quiz. |
| `404` | The quiz does not exist, or is in another institution. |
| `422` | Not a `.csv`, empty file, larger than 2 MB, missing required headers, no question rows, or more than 500 rows. |

Exports and templates are streamed with `Content-Type: text/csv; charset=utf-8`
and `Cache-Control: no-store`, so a stale copy is not served from a proxy or
the browser cache.

## Image paths

`image_url` is normalised the same way as the question editor:

- `photo.png` becomes `/api/upload/quiz-questions/photo.png`.
- `/uploads/quiz-questions/photo.png` becomes
  `/api/upload/quiz-questions/photo.png`.
- `/api/upload/...` and absolute `http(s)://` URLs are kept as they are.

Upload images through the question editor first, then reference the returned
path in the CSV.

## Template workflow

1. In the question manager, choose **Import Questions** → **Download Template**.
2. Open the file in a spreadsheet.
3. Delete the three `SAMPLE` rows and the `#` guidance lines (or leave the
   guidance lines — anything before the header is ignored).
4. Add your questions and save as **CSV UTF-8**.
5. Choose **Import Questions**, select the file, and confirm.
6. Check the per-row results in the dialog. Fix and re-import any failed rows;
   rows that already succeeded are skipped as duplicates, not duplicated.

## Tests

`lms-api/tests/QuestionCsvTest.php` covers parsing (comments, BOM, quoted
commas, quotes, embedded newlines), header validation, every question type,
true/false canonicalisation, formula neutralisation and restoration, and the
duplicate-skipping import path. It runs as part of the normal harness:

```bash
php lms-api/tests/run.php --db-host=localhost --db-user=root --db-pass=""
```

See [`../deployment/README.md`](../deployment/README.md) for the Docker
variant and the rest of the deployment documentation.
