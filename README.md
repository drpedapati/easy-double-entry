# Easy Double Entry for REDCap

A REDCap External Module that replaces the built-in double data entry system with a simpler, more flexible approach using repeating instances.

![DDE Dashboard](docs/images/tut-01-dashboard.png)

## Why This Exists

REDCap's built-in Double Data Entry uses record ID suffixes (`--1`, `--2`), requires rigid user role assignments, and needs constant admin permission changes for rotating staff. This module replaces all of that with repeating instances — no special permissions, no record-ID tricks.

## How It Works

Each DDE-enabled instrument uses three repeating instances per record:

| Instance | Purpose |
|----------|---------|
| **Instance 1** | Round 1 — first data entry pass |
| **Instance 2** | Round 2 — independent second pass |
| **Instance 3** | Final merged record (verified data) |

Any authorized user can open a record and enter data for the appropriate round. When both rounds are complete, a reviewer compares them field-by-field and merges to produce the final verified record.

## Module Pages

The module adds three pages to your REDCap project sidebar:

### DDE Dashboard

Project-wide overview showing every record with color-coded instrument badges and overall DDE status (Pending, Partial, Ready to Compare, Merged).

![DDE Dashboard](docs/images/tut-01-dashboard.png)

### DDE Task List

Prioritized action items — records ready for comparison appear at the top, followed by instruments still awaiting data entry. Each task links directly to either the data entry form or the comparison page.

![DDE Task List](docs/images/tut-02-tasklist.png)

### DDE Comparison & Merge

Side-by-side field comparison with match/discrepancy detection:

![Comparison View](docs/images/tut-04b-comparison-full.png)

**Discrepancy filtering** — click "Show Discrepancies Only" to focus on fields that need attention:

![Discrepancies Only](docs/images/tut-05-discrepancies-only.png)

**Resolving discrepancies** — click Keep R1 or Keep R2 to accept a value, or use the edit button to enter a custom merged value. Resolved fields turn blue:

![Resolving Discrepancies](docs/images/tut-07-resolve-clinician.png)

**All resolved** — every field shows either Match (green) or Merged (blue):

![All Resolved](docs/images/tut-08-all-resolved.png)

After merge, Instance 3 contains the verified data alongside the two original entries:

![Merged Record](docs/images/tut-09-record-merged.png)

## Setup

1. Install the module via **External Modules** in REDCap Control Center
2. Enable it on your project
3. In module settings, select which instruments require double entry
4. Configure those instruments as **repeating instruments** in Project Setup
5. Optionally configure filter rules for dashboard visibility
6. Choose merge target: overwrite Round 1, or write to a new Instance 3 (Final)

## Settings

| Setting | Description |
|---------|-------------|
| **DDE Instruments** | Which forms require double entry (select from form list, repeatable) |
| **Filter Rules** | Field/value pairs that control instrument visibility per participant |
| **Merge Target** | Write merged values to Instance 1 or Instance 3 |
| **Require Comment** | Force a comment when resolving discrepancies (audit trail) |
| **Notification Email** | Get notified when both rounds are complete |

## AJAX Actions

The module uses REDCap's framework AJAX system (JSMO) for all data operations:

| Action | Description |
|--------|-------------|
| `get-dashboard-data` | Fetch per-record DDE status for all instruments |
| `get-dde-stats` | Summary statistics (total, pending, merged counts) |
| `get-task-list` | Prioritized task queue |
| `compare-rounds` | Field-by-field comparison of Round 1 vs Round 2 |
| `merge-field` | Merge a single field into the final instance |
| `merge-bulk` | Auto-merge all matching fields at once |
| `get-record-rounds` | Check which rounds exist for a record/instrument |

## Compatibility

- REDCap 13.0+, PHP 8.0+, Framework Version 14
- Works with: classic projects, longitudinal, repeating instruments, DAGs, surveys
- Upgrade-safe: no core modifications, pure External Module

## File Structure

```
easy_double_entry_v1.0/
├── EasyDoubleEntry.php      # Core module class (AJAX router, comparison, merge logic)
├── config.json              # Module configuration and settings schema
├── README.md
├── pages/
│   ├── dashboard.php        # DDE Dashboard page
│   ├── tasklist.php         # Task List page
│   └── compare.php          # Compare & Merge page
└── docs/
    ├── tutorial.html        # Visual walkthrough (self-contained HTML)
    └── images/              # Tutorial screenshots
```

## Tutorial

A complete visual walkthrough is available at [docs/tutorial.html](docs/tutorial.html) covering the full workflow end-to-end:

1. Dashboard overview with status cards
2. Task list with prioritized actions
3. Navigating to Compare & Merge
4. Side-by-side comparison with match/discrepancy stats
5. Filtering to discrepancies only
6. Auto-merging matching fields
7. Manually resolving a discrepancy
8. All fields resolved
9. Merged record in REDCap (3 instances)
10. Final dashboard with Merged status

**Hosted version:** [https://report.cincibrainlab.com/ede-tutorial/](https://report.cincibrainlab.com/ede-tutorial/)

## Author

Ernest Pedapati — Cincinnati Children's Hospital Medical Center
