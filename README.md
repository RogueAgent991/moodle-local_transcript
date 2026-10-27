# Student Transcript (local_transcript) for Moodle 4.5

Generates a protected PDF transcript of all the courses a student has taken or is taking.

## Where to find it
Site administration has a **Transcripts** menu (next to Plugins) with: Settings, Transcript layouts, Issued transcripts.
Users generate transcripts at `/local/transcript/index.php` (also linked from user profiles).

## A layout has a master control and three sections
Open Transcripts > Transcript layouts, create a layout, then use the tabs:
- **Master control**: name, page size (A4, Letter, Legal or custom width x height), margins, the heights of the header and
  footer, the height of one course and of the column headings band, which courses are included, sorting, grouping, date
  format, table look, background graphic (whole page), watermark (behind the transcript), PDF protection.
  The transcript section height is always the page height minus the header and the footer.
- **Header** and **Footer**: the same on every page. Drag elements: text (with ready-made student name, ID, date, code,
  page number), image, student picture, line, rectangle, QR code.
- **Transcript**: design ONE course; it is repeated for every course and continues on extra pages. Elements: course name,
  course description, date taken (enrolment date), date completed, grade (as shown / percent / letter / pass-fail), status,
  year, category and more, plus text, image, line, rectangle. A band with column headings repeats at the top of every page.
- **Preview** shows the layout with sample data.

## Who can do what
- Everyone logged in: generate own transcript, send by email.
- Managers and course teachers: generate for students (teachers: students in their courses).
- Managers: manage layouts, see/revoke/delete issued transcripts.
- Verification code: Site administration > Transcripts > General settings chooses who may use it: course teachers and
  managers; the learner, course teachers and managers; any logged in user; or anybody.

## Settings
Site administration > **Transcripts** (the new menu next to Plugins) > General settings. It is also listed under
Plugins > Local plugins > Student Transcript. Moodle's Plugins overview does not show a Settings link for local plugins.

## License and credits
GPL v3 or later. Created and copyrighted by Roy Jensen, developed with Claude AI. The layout designer is modelled on
mod_customcert. See CREDITS.md.
