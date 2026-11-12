# Fava Park — Proposal Assessment System

**سامانه ارزش‌گذاری طرح‌های پارک فاوا** — راهنمای نصب و استفاده به فارسی:
[`docs/INSTALL.fa.md`](docs/INSTALL.fa.md) · قواعد امتیازدهی: [`docs/SCORING.fa.md`](docs/SCORING.fa.md)

A small self-hosted web application for assessing the proposals that companies submit
to the park. The secretariat uploads the applicant's Word worksheet, two judges fill
their half of the assessment form through a private link, and the system works out
the score, the tier, the support amount and the staged payments. The interface is in
Persian (right-to-left, Jalali dates).

It is plain PHP: no Composer, no Node, no build step and no cron job on the server.
It runs on ordinary cPanel hosting.

## How it works

| Step | Who | What happens |
|---|---|---|
| 1. Upload | Admin | Drops the applicant's `.docx` worksheet. The system reads the title, company, expected output, declared TRL, team, company facts and the cost table. |
| 2. Review | Admin | Checks and corrects what was read, fixes the requested amount (or leaves it to the judges) and an optional deadline, then confirms. |
| 3. Assign | Admin | Chooses a technical and a business judge. Each gets a private link; the system e-mails it, or the admin copies it. Judges have no account. |
| 4. Judge | Judges | Each judge sees only their own part of the form, with the worksheet beside it. Answers are saved while typing; a submitted form is read-only. |
| 5. Result | System | Total score out of 100, tier, risk coefficient, support amount and three staged payments. Disagreements between the judges are put to the admin. |
| 6. Decide | Admin | Settles disagreements and records the final decision. A decision or amount that differs from the calculated one needs a written reason. Printable report and CSV exports. |

Also included: a judges directory, reminders, reopening a form for correction,
replacing a judge, an activity log, an e-mail to the admin when a form comes in,
duplicate-file detection, and password recovery without e-mail.

## Requirements

| | Minimum | Notes |
|---|---|---|
| PHP | 7.4 | Tested on 7.4 and 8.3; 8.1–8.3 recommended. |
| PHP extensions | `pdo` with `pdo_mysql` or `pdo_sqlite`, `mbstring`, `dom`, `json`, `zlib`, `session`, `filter` | All are part of a standard PHP build. `openssl` is needed for e-mail over SSL/TLS; `zip` is optional. |
| Database | MySQL 5.7 / MariaDB 10.2, or SQLite 3.7 | A MySQL user with all privileges on one database. SQLite needs no account. |
| Web server | Apache 2.4 reading `.htaccess` | LiteSpeed reads the same files but is untested. `mod_rewrite` is optional: without it the addresses look like `index.php?r=/admin`. |
| HTTPS | required for real use | A judge's link works like a password. |
| Disk | about 1 MB for the application | Plus the uploaded Word files and a few kilobytes per proposal in the database. |
| PHP settings | `file_uploads = On` | `.user.ini` raises the upload limit to 32 MB; the default `memory_limit` is far more than needed. |
| Outgoing e-mail | optional | An SMTP mailbox (port 465 or 587) or PHP `mail()`. Without it the admin copies the links by hand. |

No Composer, Node, SSH, cron job or background process is needed. The application
copes with functions that shared hosts commonly switch off (`ini_set`, `exec`,
`chmod`, `disk_free_space`, `getenv` and the like) and with `open_basedir`.

## Installation in short

1. Upload all files to `public_html` (or a sub-folder), **including the hidden
   `.htaccess` and `.user.ini` files**.
2. In cPanel create a MySQL database and a user with all privileges (or choose SQLite
   in the installer).
3. Open the site address. The installer checks the server, creates the tables and the
   admin account.
4. In «تنظیمات › ایمیل» enter the mailbox the invitations are sent from.

The step-by-step guide, with backup, update and troubleshooting, is in
[`docs/INSTALL.fa.md`](docs/INSTALL.fa.md).

## Scoring rules

The rules follow the park's assessment form; the numbers below are the defaults and
can be changed in «تنظیمات › امتیازدهی». They are frozen for a proposal when its
first judge is assigned, so later changes never alter an assessment that is under way.

- **Score.** Four dimensions, five criteria each, every criterion 1 to 5.
  Technical judge: technology (weight 30) and team (20). Business judge: market (25)
  and finance (25). Dimension score = average ÷ 5 × weight; the total is out of 100.
- **Decision.** Below 40 reject, 40 to below 60 conditional, 60 and above approve.
- **Tier.** Both judges choose scalability and strategic priority. High + high is
  tier A (ceiling 30 billion rials), low + normal is tier C (8), everything else
  tier B (15). If the judges disagree, the lower tier is used until the admin chooses.
- **Risk.** TRL (1–3 → 1, 4–6 → 3, 7–9 → 5), execution risk and market risk, each
  1, 3 or 5. Coefficient = 0.7 + (average − 1) × 0.15, so between 0.7 and 1.3.
- **Amount.** `min(requested, tier ceiling) × total ÷ 100 × coefficient`, never more
  than the first factor, and zero for a rejected proposal.
- **Payments.** 30% / 40% / 30%. The last stage is whatever remains, so the three
  always add up to the amount exactly.

Amounts are in billion rials with three decimals (one million rials). Rounding is
half-up and is the same calculation on the server and in the judge's browser.

## Security

- `app/`, `config/`, `storage/`, `dev/`, `tests/` and `docs/` are closed by the root
  `.htaccess` and again by a `.htaccess` inside each folder; hidden files and folders
  (`.git`, `.user.ini`) are refused everywhere. The installer and the status page
  check from the browser that `storage/` really cannot be reached, and warn if it can.
- On a server that ignores `.htaccess` (nginx, for example) move `config/` and
  `storage/` out of the web root with a `paths.php` file; see the installation guide.
- A judge's link is the judge's credential: 32 random characters, valid for one
  proposal and one role. Replacing a judge or making a new link kills the old one.
- Admin login is rate-limited (6 failures per address in 15 minutes); passwords are
  hashed with `password_hash`; forms carry CSRF tokens; the session cookie is
  `HttpOnly`, `SameSite=Lax` and `Secure` over HTTPS.
- The SMTP password is stored encrypted. The error log never contains passwords,
  function arguments or complete judge links.
- Uploaded files are checked to be real Word documents, stored under random names
  and only ever sent through the application. CSV exports are safe against
  spreadsheet formula injection.

## Project layout

```
index.php            front controller
.htaccess .user.ini  web server rules and upload limits
app/                 application code (Core, Docx, Domain, Controllers, Views)
app/form_definition.php   texts and structure of the assessment form
assets/              compiled CSS, JavaScript, the Vazirmatn font
config/              config.php is written here by the installer
storage/             database (SQLite), uploaded worksheets, logs
docs/                guides
dev/                 development helpers (not needed on the server)
tests/               automated tests (not needed on the server)
```

`dev/` and `tests/` are left out of GitHub's “Download ZIP”.

## Tests

```
php tests/run.php              # everything
php tests/run.php scoring      # only tests whose group or name contains “scoring”
php tests/run.php --no-http    # skip the end-to-end tests
```

The tests need only the PHP command line (and Node.js for the two tests that compare
the browser's copy of the scoring rules with the server's; they are skipped without
it). They work in a temporary folder and never touch `config/` or `storage/`.

What they cover: Persian text, numbers and Jalali dates; the scoring rules, including
3000 random forms calculated by PHP and by the browser code with identical results;
reading `.docx` files and refusing unsafe ones; the complete workflow on the database;
e-mail against a local SMTP server (plain, SSL, STARTTLS, authentication); and
end-to-end flows over HTTP — installer, admin pages, judge links, drafts, results,
exports, a sub-folder installation and the `index.php?r=` fallback.

Two optional environment variables widen the run:

```
# the database tests and an end-to-end flow on MySQL/MariaDB as well
FAVA_TEST_MYSQL='{"host":"127.0.0.1","name":"fava_test","user":"fava","pass":"secret"}' php tests/run.php

# the end-to-end tests through a real Apache + PHP-FPM, so that the project's own
# .htaccess and .user.ini answer the requests (needs the setsid command)
FAVA_TEST_APACHE='{"httpd":"/usr/sbin/apache2","fpm":"/usr/sbin/php-fpm8.3"}' php tests/run.php
```

Add `"fpm_ini":"/path/to/php.ini"` to the second one to run PHP-FPM with a php.ini of
your own, for example one that loads only the required extensions or disables
functions the way your host does.

### What has been verified, and what has not

| | |
|---|---|
| PHP 8.3.8 and PHP 7.4.33 | all tests pass on both |
| SQLite 3.7, MariaDB 10.2.11 | all database tests pass on both |
| Apache 2.4.69 + PHP-FPM | all end-to-end tests pass through the real `.htaccess`, in the web root, in a sub-folder and without `mod_rewrite` |
| A bare host | the same tests pass with only the required extensions loaded, OPcache on, `memory_limit = 8M`, `open_basedir` set and some forty functions disabled |
| Browser | headless Chromium only, at desktop and phone widths |
| **Not tested** | LiteSpeed, nginx, a real cPanel account, MySQL 8, a real mail provider, Firefox, Safari, Windows hosting, PHP 8.4 |

Reading the worksheet was checked against one real worksheet and the park's earlier
ones. A worksheet with a different layout may be read only partly; that is why step 2
asks the admin to review what was read.

## Development

```
php -S 127.0.0.1:8080 dev/router.php     # local server; the router imitates .htaccess
```

- Styles are Tailwind CSS 3, compiled into `assets/css/app.css`. After changing a
  class in a view or in `dev/tailwind/input.css`, rebuild with
  `npx tailwindcss@3 -c dev/tailwind/tailwind.config.js -i dev/tailwind/input.css -o assets/css/app.css --minify`.
- The texts and structure of the form are in `app/form_definition.php`. Criterion ids
  are stored with every submission: add new ids rather than renumbering.
- `assets/js/scoring.js` is the browser's copy of `app/Domain/Scoring.php`. Change
  both together; a test fails when they disagree.
- The test worksheets in `tests/fixtures/` are generated by `dev/make_sample_docx.py`
  and contain invented data.

## Licences

The application code belongs to the park. The bundled font is
[Vazirmatn](https://github.com/rastikerdar/vazirmatn) under the SIL Open Font License
(`assets/fonts/OFL.txt`).
