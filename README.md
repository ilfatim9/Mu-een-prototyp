# MU’EEN (مُعين)

Campus support prototype for students with approved health and accessibility needs. It connects support requests, schedules and facility updates with administrative review, and includes student and public SOS flows.

## Run locally

1. Place this folder at `C:\xampp\htdocs\mueen`.
2. Start Apache and MySQL in XAMPP.
3. Create a MySQL database named `mueen_db` in phpMyAdmin and import its schema. **The SQL export is not included in this package yet.** Add a schema-only export to `data/mueen_db.sql` before sharing a runnable copy.
4. Check `db.php` against your local database settings.
5. Open `http://localhost/mueen/`.

The public SOS page accepts a location URL parameter, for example `http://localhost/mueen/public_sos.php?location=Building%20A`. A phone needs a reachable host address rather than `localhost`.

## Project structure

| Path | Purpose |
| --- | --- |
| `index.php` | Main PHP application: login and student, admin, clinic and facilities views |
| `css/styles.css` | MU’EEN visual styles and responsive layouts |
| `js/app.js` | Frontend interactions |
| Root PHP handlers | Requests, emergency reports, facility updates and case actions |
| `data/` | Place for a schema-only MySQL export; export is still needed |
| `assets/mueen-logo.png` | Project logo |
| `brand/color-and-typography.png` | Original color and typography reference |
| `docs/project-overview.md` | Product overview and supplied-material notes |
| `uploads/health_reports/` | Local report uploads, excluded from Git |

## Prototype note

The supplied login logic compares passwords as plain text. Use demo accounts only, and avoid real student or medical data in a public repository.
