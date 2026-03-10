# Easy Double Entry

REDCap External Module for simplified double data entry workflows.

## Why This Exists

REDCap's built-in Double Data Entry uses record ID suffixes (`--1`, `--2`), requires rigid user role assignments, and needs constant admin permission changes for rotating staff. This module replaces that with a simpler approach.

## How It Works

- **Repeat instances** instead of record ID tricks: Round 1 = instance 1, Round 2 = instance 2
- **Any authorized user** can open a record and select which round to fill — no special DDE permissions needed
- **Smart filtering**: the dashboard shows only the instruments relevant to each participant (based on configurable rules like age, group, enrollment)
- **Side-by-side comparison** with green/red highlighting when both rounds are complete
- **One-click merge**: Keep Round 1, Keep Round 2, or enter a custom value — writes to a final instance instantly
- **Task list**: coordinators see exactly what needs attention, sorted by priority

## Setup

1. Install the module via External Modules in REDCap Control Center
2. Enable it on your project
3. In module settings, select which instruments require double entry
4. Configure the selected instruments as **repeating instruments** in your project setup
5. Optionally configure filter rules for dashboard visibility
6. Choose merge target: overwrite Round 1, or write to a new Instance 3 (Final)

## Compatibility

- REDCap 13.0+, PHP 8.0+
- Works with: classic projects, longitudinal, repeating instruments, DAGs, surveys
- Upgrade-safe: no core modifications, pure External Module

## Module Pages

| Page | Description |
|------|-------------|
| DDE Dashboard | Overview of all records with DDE status per instrument |
| DDE Comparison & Merge | Side-by-side Round 1 vs Round 2 with merge controls |
| DDE Task List | Prioritized queue of outstanding items |

## Settings

| Setting | Description |
|---------|-------------|
| DDE Instruments | Which forms require double entry (repeatable) |
| Filter Rules | Field/value pairs that control instrument visibility per participant |
| Merge Target | Write merged values to Instance 1 or Instance 3 |
| Require Comment | Force a comment when resolving discrepancies |
| Notification Email | Get notified when both rounds are complete |
