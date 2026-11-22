<!-- fixerMarker: 2026-11-22 quiz-share-export-results-bank-picker -->

# Changelog

All notable changes to the Learning LMS are documented here.

## [Unreleased] - 2026-11-22

### Added

- Quiz Details **Share** button and popup modal.
  - The popup lists the courses/classes assigned to the teacher as checkboxes.
  - The quiz's original class is shown checked and disabled as **Original class**.
  - Save keeps the popup open and confirms with `Sharing updated.`
  - The popup closes only via **×** or **Cancel**.
- `quiz_shares` table and additive migration `lms-api/migrations/create_quiz_shares.php`.
- `GET /quizzes/{id}/shares` and `PUT /quizzes/{id}/shares` endpoints.
- Shared-class support in:
  - student course quiz listings;
  - quiz detail, question, start, and attempt access checks;
  - activation, deactivation, and deadline notifications;
  - student quiz completion statistics.
- Owner/institution validation so teachers can only share to classes assigned to the quiz owner.
- Continued access for in-progress attempts if a class share is later removed.
- `QuizShareTest.php` covering the sharing schema, routes, authorization, and UI wiring.

### Changed

- Student quiz results now show labelled answers from the immutable served options, for example `Your Answer: A. Dog` and `Correct Answer: D. Cat`.
- The production fresh-install runbook now includes the quiz sharing migration command.

### Fixed

- PDF question export images in Docker were blocked by missing CORS headers on Apache's `/uploads/` alias.
- Removed explicit `cache: 'no-store'` from question image fetches that forced an unnecessary CORS preflight.
- Added an error log with the exact image URL when an image cannot be added to the PDF export.
- **Link Question from Bank** picker now stays open after linking, for both **From Bank** and **Link existing**. The picker refreshes both lists so linked questions show **In quiz**, and it closes only via its close button.

### Notes

- No commit was made for these changes.
- Existing production volumes must run `php migrations/create_quiz_shares.php` after rebuilding the API container.
