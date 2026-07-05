# local_vbs_myoverview

Backend enrichment for the VBS "Course overview" cards (Feature F01 — *Học viên
xem danh sách khóa học*). Provides the data for the three additive presentation
slots that the `theme_vbs` `core_course/coursecard` override froze in VBS-132:
`vbsbadges`, `vbsdaterange`, `vbsregisterurl`.

**Architecture (arch-reviewer approved, VBS-130):** a small *enrichment overlay*
web service + a JS overlay in `theme_vbs`. Core
`core_course_get_enrolled_courses_by_timeline_classification` is left untouched —
the theme JS batches the visible `courseids` into
`local_vbs_myoverview_enrich_courses` and injects the result into each card. This
keeps the core WS surface out of our code so it does not rot across Moodle
versions.

## Components

| File | Responsibility |
|---|---|
| `classes/local/state_computer.php` | Pure logic — `lifecycle_state` (dates + Completion API) and `enrollment_state` (enrol status), computed independently (BR-F01-02, never from progress %). |
| `classes/local/badge_mapper.php` | Maps state → `{label, classes}` in card order: delivery → lifecycle → enrollment (spec §5.4). Colour via Bootstrap variants themed by `$primary` — no hex. |
| `classes/external/enrich_courses.php` | WS `local_vbs_myoverview_enrich_courses(courseids[])` → `[{courseid, vbsbadges, vbsdaterange, vbsregisterurl}]`. Enrolled-scope only. |
| `classes/local/customfield_installer.php` | Idempotently provisions the `delivery_mode` course custom field (menu: online/offline/blended) under a `VBS` category. |
| `db/services.php` | Registers the WS with `ajax => true`. |
| `db/install.php` / `db/upgrade.php` | Provision the custom field. |
| `classes/privacy/provider.php` | `null_provider` — stores no personal data. |

## WS return contract (must match the frozen coursecard override)

```json
[
  {
    "courseid": 42,
    "vbsbadges": [
      {"label": "Online",       "classes": "border border-secondary text-body bg-white"},
      {"label": "Đang diễn ra", "classes": "bg-primary text-white"},
      {"label": "Phân công",    "classes": "border border-primary text-primary bg-white"}
    ],
    "vbsdaterange": "01/06/2026 – 31/07/2026",
    "vbsregisterurl": ""
  }
]
```

## Pilot constraints

- `vbsregisterurl` is **always empty** at pilot — the `open_for_registration`
  half (course catalog / `/course/index.php`) is deferred. The card degrades
  correctly (no CTA).
- `delivery_mode` badge is omitted when the course has no value (or an
  unrecognised value) — the card simply shows lifecycle + enrollment.
- Enrollment is only `assigned` / `pending_approval` at pilot (block_myoverview
  lists enrolled courses only).

## Tests

`tests/` — `state_computer`, `badge_mapper`, `enrich_courses`. Run with:

```
vendor/bin/phpunit --filter local_vbs_myoverview
```
